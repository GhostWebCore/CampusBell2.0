<?php
/**
 * api/iot/device.php
 *
 * Polling endpoint for the Wemos D1 (ESP8266) bell controllers.
 * Called every ~1 second per zone:
 *
 *   GET /bell2.0/api/iot/device.php?zone=A
 *   Header: X-Device-Key: <pre-shared key from tbl_devices>
 *
 * This endpoint is intentionally NOT built like the admin-facing
 * APIs in this project:
 *   - No PHP session, no CSRF token (a microcontroller can't hold
 *     a session cookie reliably across reconnects/reboots).
 *   - Auth is a static per-device key checked against tbl_devices.
 *   - Response is always HTTP 200 with a flat, fixed-shape JSON body
 *     (even on auth failure) so the firmware can use one fixed-size
 *     ArduinoJson buffer and one parse path for every outcome.
 *
 * See /iot_api_strategy.md in this same delivery for the full
 * design rationale, priority logic walkthrough, and idempotency
 * strategy this file implements.
 *
 * Priority chain (first match wins, evaluated fresh every request):
 *   1. Active emergency        (tbl_emergency_logs)
 *   2. Due scheduled bell      (tbl_schedules + tbl_schedule_zones)
 *   3. Today's announcement    (tbl_announcements, repeat-aware)
 *   4. Nearest upcoming event  (tbl_events, informational only)
 *   5. Idle (nothing to do)
 */

declare(strict_types=1);

header('Content-Type: application/json');

// ----------------------------------------------------------------
// How wide the "ring window" is for Priority 2. A schedule's
// ring_time must fall within [NOW, NOW + window] to be considered
// "due". Wider window = more tolerant of a device that briefly
// disconnects right at ring time; narrower = tighter precision.
// ----------------------------------------------------------------
const RING_WINDOW_SECONDS    = 8;

// How long the relay should stay closed for an emergency bell.
// Emergencies typically ring longer/continuously compared to a
// normal period bell — adjust to match your physical siren/relay.
const EMERGENCY_RING_SECONDS = 30;

// ----------------------------------------------------------------
// Fatal-error safety net.
// A blank or malformed body would leave the relay logic in an
// undefined state on the device, so this is even more important
// here than on the admin-facing endpoints.
// ----------------------------------------------------------------
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(200); // always 200 — see "always this JSON shape" in the strategy doc
            header('Content-Type: application/json');
        }
        error_log('device.php fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        echo json_encode(idleResponse('error', 'Server error.'));
    }
});

// NOTE: this endpoint deliberately does NOT include session.php or
// require_login.php — those guard the human admin UI and assume a
// browser session, which an ESP8266 doesn't have.
require_once __DIR__ . '/../../config/database.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        http_response_code(200);
        echo json_encode(idleResponse('error', 'Method not allowed.'));
        exit;
    }

    $pdo = get_db_connection();

    // ---- 1. Validate zone parameter -----------------------------
    $zone = trim((string)($_GET['zone'] ?? ''));
    if ($zone === '' || strlen($zone) > 10) {
        echo json_encode(idleResponse('error', 'Missing or invalid zone parameter.'));
        exit;
    }

    // ---- 2. Authenticate the device ------------------------------
    // Device sends its pre-shared key in a custom header. We look it
    // up against tbl_devices and confirm it's both valid AND assigned
    // to the zone it's claiming to be — prevents a leaked key for
    // Zone A from being used to silence/spoof Zone B.
    $deviceKey = trim($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');

    if ($deviceKey === '') {
        echo json_encode(idleResponse('error', 'Missing device key.'));
        exit;
    }

    $stmtDevice = $pdo->prepare(
        'SELECT device_id, default_ring_s
         FROM tbl_devices
         WHERE device_key = :key AND zone_code = :zone AND is_active = 1
         LIMIT 1'
    );
    $stmtDevice->execute([':key' => $deviceKey, ':zone' => $zone]);
    $device = $stmtDevice->fetch();

    if (!$device) {
        // Deliberately vague — don't reveal whether the key or the
        // zone mismatch was the problem (same principle as the login
        // endpoint not revealing "wrong password" vs "unknown user").
        echo json_encode(idleResponse('error', 'Unauthorized device.'));
        exit;
    }

    // Record that this device polled in, for an "online/offline" view
    // on the admin dashboard later if desired. Fire-and-forget — don't
    // let this slow the hot path or block the response on failure.
    try {
        $pdo->prepare('UPDATE tbl_devices SET last_seen_at = NOW() WHERE device_id = :id')
            ->execute([':id' => $device['device_id']]);
    } catch (Throwable $e) {
        // Non-critical — log and continue, never fail the poll over this
        error_log('device.php last_seen_at update failed: ' . $e->getMessage());
    }

    $defaultRingS = (int)$device['default_ring_s'];

    // ================================================================
    // PRIORITY 1 — EMERGENCY (overrides everything)
    // ================================================================
    $stmtEm = $pdo->prepare(
        "SELECT log_id, emergency_type, audio_file
         FROM tbl_emergency_logs
         WHERE status = 'active'
           AND (zones = 'All' OR FIND_IN_SET(:zone, zones))
         ORDER BY triggered_at DESC
         LIMIT 1"
    );
    $stmtEm->execute([':zone' => $zone]);
    $emergency = $stmtEm->fetch();

    if ($emergency) {
        echo json_encode([
            'priority'        => 'emergency',
            'ring'            => true,
            'ring_duration_s' => EMERGENCY_RING_SECONDS,
            'audio_file'      => $emergency['audio_file'] ?? '',
            'label'           => $emergency['emergency_type'],
            'ref_id'          => (int)$emergency['log_id'],
            'server_time'     => date('c'),
        ]);
        exit; // STOP — emergency overrides all lower priorities
    }

    // ================================================================
    // PRIORITY 2 — SCHEDULED BELL
    // ================================================================
    // A bell is "due" when:
    //   - it's active
    //   - today's weekday bit is set in days_mask
    //   - it targets this zone (or 'All')
    //   - its ring_time falls within [NOW, NOW + RING_WINDOW_SECONDS]
    //   - it hasn't already been logged as rung today (idempotency)
    $todayBit = 1 << ((int)date('N') - 1); // Mon=bit0 ... Sun=bit6, matches schedule.php convention

    // "Due" means ring_time has already passed (so the moment has
    // arrived) but not by more than RING_WINDOW_SECONDS (so we don't
    // fire bells that were missed hours ago after an outage).
    $stmtSched = $pdo->prepare(
        "SELECT
             s.sched_id,
             s.bell_name,
             s.duration_s,
             s.audio_file
         FROM tbl_schedules s
         JOIN tbl_schedule_zones sz ON sz.sched_id = s.sched_id
         LEFT JOIN tbl_schedule_ring_log rl
                ON rl.sched_id = s.sched_id AND rl.ring_date = CURDATE()
         WHERE s.is_active = 1
           AND (sz.zone_code = 'All' OR sz.zone_code = :zone)
           AND (s.days_mask & $todayBit) = $todayBit
           AND TIMESTAMPDIFF(SECOND, s.ring_time, CURTIME()) BETWEEN 0 AND :window
           AND rl.sched_id IS NULL
         ORDER BY s.ring_time ASC
         LIMIT 1"
    );
    $stmtSched->execute([':zone' => $zone, ':window' => RING_WINDOW_SECONDS]);
    $schedule = $stmtSched->fetch();

    if ($schedule) {
        // Idempotency guard: record that this schedule has now been
        // served for today BEFORE returning, so the next poll (1 second
        // later, while the relay is still mid-pulse) sees the log row
        // and falls through instead of re-triggering the bell.
        try {
            $pdo->prepare(
                'INSERT INTO tbl_schedule_ring_log (sched_id, ring_date)
                 VALUES (:id, CURDATE())
                 ON DUPLICATE KEY UPDATE rung_at = rung_at' // no-op on race; PK prevents duplicates
            )->execute([':id' => $schedule['sched_id']]);
        } catch (Throwable $e) {
            error_log('device.php ring_log insert failed: ' . $e->getMessage());
            // Even if logging fails, we still ring — missing a bell is
            // worse than a rare double-ring, and the next poll will
            // likely succeed at logging it.
        }

        echo json_encode([
            'priority'        => 'schedule',
            'ring'            => true,
            'ring_duration_s' => (int)($schedule['duration_s'] ?: $defaultRingS),
            'audio_file'      => $schedule['audio_file'] ?? '',
            'label'           => $schedule['bell_name'],
            'ref_id'          => (int)$schedule['sched_id'],
            'server_time'     => date('c'),
        ]);
        exit; // STOP — schedule overrides announcements and events
    }

    // ================================================================
    // PRIORITY 3 — ANNOUNCEMENTS
    // ================================================================
    // Eligible when: scheduled for today, targets this zone, and either
    // never played yet OR its repeat interval has elapsed since last play.
    $stmtAnn = $pdo->prepare(
        "SELECT ann_id, title, audio_file, reminder_interval_min
         FROM tbl_announcements
         WHERE is_deleted = 0
           AND play_date = CURDATE()
           AND (zones = 'All' OR FIND_IN_SET(:zone, zones))
           AND (
                 last_played_at IS NULL
              OR TIMESTAMPDIFF(MINUTE, last_played_at, NOW()) >= reminder_interval_min
           )
         ORDER BY created_at ASC
         LIMIT 1"
    );
    $stmtAnn->execute([':zone' => $zone]);
    $announcement = $stmtAnn->fetch();

    if ($announcement) {
        // Stamp last_played_at now so the NEXT poll (1 second later)
        // sees a fresh timestamp and silently falls through to priority
        // 4 instead of re-announcing every second. This single UPDATE
        // is what makes the "repeat every N minutes" rule work without
        // the device tracking any state of its own.
        try {
            $pdo->prepare(
                'UPDATE tbl_announcements SET last_played_at = NOW() WHERE ann_id = :id'
            )->execute([':id' => $announcement['ann_id']]);
        } catch (Throwable $e) {
            error_log('device.php announcement last_played_at update failed: ' . $e->getMessage());
        }

        echo json_encode([
            'priority'        => 'announcement',
            'ring'            => true,
            'ring_duration_s' => $defaultRingS,
            'audio_file'      => $announcement['audio_file'] ?? '',
            'label'           => $announcement['title'],
            'ref_id'          => (int)$announcement['ann_id'],
            'server_time'     => date('c'),
        ]);
        exit; // STOP — announcement overrides events
    }

    // ================================================================
    // PRIORITY 4 — NEAREST UPCOMING EVENT (informational, no relay)
    // ================================================================
    $stmtEvt = $pdo->prepare(
        "SELECT event_id, event_title, event_date
         FROM tbl_events
         WHERE is_deleted = 0
           AND event_date >= CURDATE()
           AND (zones = 'All' OR FIND_IN_SET(:zone, zones))
         ORDER BY event_date ASC
         LIMIT 1"
    );
    $stmtEvt->execute([':zone' => $zone]);
    $event = $stmtEvt->fetch();

    if ($event) {
        echo json_encode([
            'priority'        => 'event',
            'ring'            => false, // events are informational only — see strategy doc §3
            'ring_duration_s' => 0,
            'audio_file'      => '',
            'label'           => $event['event_title'] . ' (' . $event['event_date'] . ')',
            'ref_id'          => (int)$event['event_id'],
            'server_time'     => date('c'),
        ]);
        exit;
    }

    // ================================================================
    // NOTHING ACTIVE — idle
    // ================================================================
    echo json_encode(idleResponse('idle'));

} catch (Throwable $e) {
    error_log('device.php exception: ' . $e->getMessage());
    http_response_code(200); // still 200 — see header comment
    echo json_encode(idleResponse('error', 'Server error.'));
}


/**
 * Builds a response in the same flat shape as every other branch,
 * for the no-op / error cases. Keeping ALL outcomes (including
 * errors) in this exact shape is what lets the firmware use one
 * fixed JSON buffer and one parse path — see strategy doc §4.
 */
function idleResponse(string $priority = 'idle', string $label = ''): array
{
    return [
        'priority'        => $priority,
        'ring'            => false,
        'ring_duration_s' => 0,
        'audio_file'      => '',
        'label'           => $label,
        'ref_id'          => 0,
        'server_time'     => date('c'),
    ];
}
