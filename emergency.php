<?php
/**
 * emergency.php
 *
 * Protected page — require_login.php redirects unauthenticated
 * visitors to login.php before any HTML is output.
 *
 * On load the page does two AJAX calls:
 *   1. GET ?action=active  — restores the "active alert" UI state if
 *                            an alert was triggered in a previous session.
 *   2. GET ?action=log     — populates the emergency log table.
 */
declare(strict_types=1);

// Auth guard: halts with redirect or 401 JSON if no valid session
require_once __DIR__ . '/includes/require_login.php';

// API base URL (protocol + host + subfolder aware)
include_once __DIR__ . '/includes/api_base.php';

// Embed the first CSRF token server-side — ready without a prefetch
require_once __DIR__ . '/includes/csrf.php';

// ----------------------------------------------------------------
// Session data — escaped for safe HTML output
// ----------------------------------------------------------------
$fullName = htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($_SESSION['username']  ?? '',      ENT_QUOTES, 'UTF-8');

$nameParts = array_filter(explode(' ', $fullName));
$initials  = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($nameParts, 0, 2))));

$initialCsrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>CampusBell — Emergency Override</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

<link rel="stylesheet" href="assets/css/main.css" />
<style>


/* ============================================================
   MAIN CONTENT
============================================================ */


/* CARD */
.card{background:var(--paper-1);border-radius:var(--r-md);border:1px solid var(--line);padding:1.3rem;box-shadow:var(--shadow-1);}
.card-title{font-family:var(--font-display);font-size:0.92rem;font-weight:700;color:var(--text-900);letter-spacing:-0.005em;}

/* ============================================================
   ACTIVE ALERT BANNER
   Shown when an alert is currently live — replaces the static banner.
   Hidden when no alert is active.
============================================================ */
.emergency-banner{
  display:flex;gap:1rem;align-items:flex-start;
  background:var(--bad-100);border:1px solid #F0BFB9;
  border-radius:var(--r-md);padding:1.1rem 1.25rem;margin-bottom:1.4rem;
}
.emergency-banner-icon{width:40px;height:40px;border-radius:var(--r-sm);background:var(--bad-500);color:white;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.emergency-banner h2{font-family:var(--font-display);font-size:0.98rem;color:#8A2A23;margin-bottom:0.2rem;}
.emergency-banner p{font-size:0.84rem;color:#9C3D34;line-height:1.5;}

/* Active alert banner — the pulsing red state when live */
.active-alert-bar{
  display:none; /* hidden until an alert is active */
  align-items:center;gap:1rem;flex-wrap:wrap;
  background:var(--bad-500);color:white;
  border-radius:var(--r-md);padding:1rem 1.25rem;margin-bottom:1.4rem;
  animation:alert-bar-in 0.3s var(--ease);
}
.active-alert-bar.visible{display:flex;}
@keyframes alert-bar-in{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:none;}}
.alert-bar-pulse{width:14px;height:14px;border-radius:50%;background:white;flex-shrink:0;animation:alert-pulse 1.2s ease-in-out infinite;}
@keyframes alert-pulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.4;transform:scale(0.7);}}
.alert-bar-text{flex:1;min-width:0;}
.alert-bar-type{font-family:var(--font-display);font-size:1.05rem;font-weight:700;letter-spacing:0.01em;}
.alert-bar-sub{font-size:0.8rem;opacity:0.82;margin-top:1px;}
.alert-bar-timer{font-family:var(--font-mono);font-size:1.15rem;font-weight:600;background:rgba(0,0,0,0.18);padding:0.3rem 0.75rem;border-radius:var(--r-sm);}
.btn-clear-alert{padding:0.55rem 1.15rem;border-radius:var(--r-sm);background:white;color:var(--bad-600);font-size:0.85rem;font-weight:700;display:flex;align-items:center;gap:0.4rem;transition:background var(--t);flex-shrink:0;}
.btn-clear-alert:hover{background:#ffe8e6;}
.btn-clear-alert:disabled{opacity:0.65;cursor:not-allowed;}

/* ============================================================
   EMERGENCY TYPE CARDS
============================================================ */
.emergency-types{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:0.75rem;}
.emergency-type-card{
  border:1.5px solid var(--line-2);border-radius:var(--r-sm);padding:1rem 0.75rem;text-align:center;
  cursor:pointer;transition:border-color var(--t),background var(--t),transform var(--t);
}
.emergency-type-card:hover{transform:translateY(-1px);border-color:var(--text-400);}
.emergency-type-card.selected{border-color:var(--bad-500);background:var(--bad-100);}
/* Disabled state when an alert is already active */
.emergency-type-card.disabled{opacity:0.45;pointer-events:none;}
.et-icon{font-size:22px;margin-bottom:0.5rem;color:var(--text-600);}
.emergency-type-card.selected .et-icon{color:var(--bad-500);}
.et-name{font-size:0.8rem;font-weight:600;color:var(--text-900);}

/* Optional note field that appears below the type cards */
.note-field{margin-top:1rem;}
.note-label{display:block;font-size:0.74rem;font-weight:600;color:var(--text-600);margin-bottom:0.4rem;letter-spacing:0.04em;text-transform:uppercase;}
.note-input{width:100%;padding:0.68rem 1rem;border:1.5px solid var(--line-2);border-radius:var(--r-sm);font-size:0.875rem;color:var(--text-900);background:var(--paper);outline:none;resize:none;transition:border-color var(--t),box-shadow var(--t),background var(--t);}
.note-input:focus{border-color:var(--bad-500);box-shadow:0 0 0 3px var(--bad-100);background:white;}
.note-input:disabled{opacity:0.5;cursor:not-allowed;}

/* ============================================================
   ACTIVATE BUTTON
============================================================ */
.emergency-btn-wrap{display:flex;justify-content:center;margin:2.2rem 0;}
.emergency-big-btn{
  width:168px;height:168px;border-radius:50%;background:var(--bad-500);color:white;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.3rem;
  font-family:var(--font-display);font-size:1.05rem;font-weight:700;letter-spacing:0.02em;
  box-shadow:0 0 0 8px var(--bad-100),var(--shadow-2);transition:transform var(--t),box-shadow var(--t),background var(--t);
  position:relative;
}
.emergency-big-btn::before{
  content:'';position:absolute;inset:-8px;border-radius:50%;border:2px solid var(--bad-500);
  opacity:0;animation:emergency-pulse 2.2s var(--ease) infinite;
}
@keyframes emergency-pulse{0%{opacity:0.6;transform:scale(1);}100%{opacity:0;transform:scale(1.25);}}
.emergency-big-btn:hover{transform:scale(1.04);box-shadow:0 0 0 10px var(--bad-100),var(--shadow-2);}
.emergency-big-btn:disabled{opacity:0.5;cursor:not-allowed;transform:none;animation:none;}
.emergency-big-btn:disabled::before{animation:none;}
.emergency-big-btn .e-icon{font-size:28px;}
/* Flash animation triggered when an alert fires */
@keyframes emergency-flash{0%,100%{background:var(--bad-500);}50%{background:#7B1A16;}}

/* ============================================================
   LOG TABLE
============================================================ */
.log-table{width:100%;border-collapse:collapse;min-width:600px;}
.log-table th{text-align:left;font-size:0.7rem;font-weight:700;color:var(--text-400);text-transform:uppercase;letter-spacing:0.05em;padding:0.7rem 0.75rem;border-bottom:1px solid var(--line);background:var(--paper);}
.log-table td{padding:0.7rem 0.75rem;font-size:0.85rem;border-bottom:1px solid var(--line);color:var(--text-900);}
.log-table tbody tr:last-child td{border-bottom:none;}
.log-table tbody tr{transition:background var(--t);}
.log-table tbody tr:hover{background:var(--paper);}

.status-chip{font-size:0.68rem;font-weight:600;padding:3px 10px;border-radius:var(--r-pill);}
.status-chip.active {background:var(--bad-100);color:var(--bad-500);}
.status-chip.cleared{background:var(--ok-100);color:var(--ok-500);}

/* Skeleton rows while the log loads */
.skel-line{height:11px;border-radius:var(--r-xs);background:var(--line);animation:shimmer 1.4s ease-in-out infinite;display:inline-block;}
@keyframes shimmer{0%,100%{opacity:1;}50%{opacity:0.4;}}

/* ============================================================
   OVERLAYS — Confirm Trigger / Logout
============================================================ */

</style>
</head>
<body>
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<!-- ============================================================
     TRIGGER CONFIRMATION OVERLAY
     Shown before the ACTIVATE button actually fires.
============================================================ -->
<div class="overlay-backdrop" id="triggerOverlay" role="dialog" aria-modal="true" aria-labelledby="triggerTitle">
  <div class="overlay-box">
    <h3 id="triggerTitle" style="color:var(--bad-500);">
      <i class="fa-solid fa-triangle-exclamation" style="margin-right:0.4rem;"></i>Confirm Emergency Alert
    </h3>
    <p>
      You are about to trigger: <span class="em-type-label" id="confirmTypeName">—</span><br><br>
      This will broadcast to <strong>ALL zones</strong> immediately. This action is
      <strong>logged and cannot be undone</strong> without explicitly clearing the alert.
    </p>
    <div class="overlay-actions">
      <button class="btn-ghost" onclick="closeTriggerOverlay()">Cancel</button>
      <button class="btn-danger" id="confirmTriggerBtn" onclick="doTrigger()">
        <i class="fa-solid fa-bell"></i> Activate Now
      </button>
    </div>
  </div>
</div>

<!-- ============================================================
     LOGOUT CONFIRMATION OVERLAY
============================================================ -->
<div class="overlay-backdrop" id="logoutOverlay" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
  <div class="overlay-box">
    <h3 id="logoutTitle">Sign out?</h3>
    <p>You'll need to enter your credentials again to access CampusBell.</p>
    <div class="overlay-actions">
      <button class="btn-ghost" onclick="closeLogoutOverlay()">Cancel</button>
      <button class="btn-danger" id="confirmLogoutBtn" onclick="performLogout()">
        <i class="fa-solid fa-right-from-bracket"></i> Sign out
      </button>
    </div>
  </div>
</div>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<nav class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="sidebar-header">
    <div class="sidebar-logo-icon"><i class="fa-solid fa-bell"></i></div>
    <div class="sidebar-logo-text"><h2>CampusBell</h2><span>IoT Bell System</span></div>
  </div>
  <div class="nav-section">
    <div class="nav-section-label">Main</div>
    <a href="dashboard.php"    class="nav-item"><span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span> Dashboard</a>
    <a href="announcement.php" class="nav-item"><span class="nav-icon"><i class="fa-solid fa-bullhorn"></i></span> Announcements</a>
    <div class="nav-section-label">Scheduling</div>
    <a href="schedule.php"     class="nav-item"><span class="nav-icon"><i class="fa-solid fa-clock"></i></span> Schedule Management</a>
    <a href="event.php"        class="nav-item"><span class="nav-icon"><i class="fa-solid fa-calendar-days"></i></span> Events Calendar</a>
    <div class="nav-section-label">Control</div>
    <a href="emergency.php"    class="nav-item active emergency-nav" aria-current="page">
      <span class="nav-icon"><i class="fa-solid fa-triangle-exclamation"></i></span> Emergency Override
    </a>
  </div>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar"><?php echo $initials; ?></div>
      <div class="user-info">
        <div class="user-name"><?php echo $fullName; ?></div>
        <div class="user-role">System Administrator</div>
      </div>
      <i class="fa-solid fa-chevron-down"></i>
    </div>
  </div>
</nav>

<!-- ============================================================
     MAIN CONTENT
============================================================ -->
<div class="main-content" id="mainContent">
  <header class="topbar">
    <button class="topbar-menu-btn" onclick="toggleSidebar()" aria-label="Open navigation" id="menuBtn">
      <i class="fa-solid fa-bars"></i>
    </button>
    <div class="topbar-title-wrap">
      <span class="topbar-title">Emergency Override</span>
      <span class="topbar-crumb">CampusBell / Emergency</span>
    </div>
    <div class="topbar-right">
      <div class="status-pill"><span class="status-dot"></span><span class="status-label">System Online</span></div>
      <span class="topbar-time" id="liveClock">--:--:--</span>
      <button class="topbar-icon-btn" aria-label="Notifications" onclick="showToast('No new notifications','info','<i class=&quot;fa-solid fa-bell&quot;></i>')">
        <i class="fa-solid fa-bell"></i><span class="dot"></span>
      </button>
      <button class="topbar-logout-btn" onclick="openLogoutOverlay()" aria-label="Sign out">
        <i class="fa-solid fa-right-from-bracket"></i><span>Sign out</span>
      </button>
    </div>
  </header>

  <main class="page-content" role="main">
    <h1 class="page-heading">Emergency Override</h1>
    <p class="page-subheading">Immediately trigger campus-wide emergency alerts. Use with caution.</p>

    <!-- Active alert bar (hidden until an alert is live) -->
    <div class="active-alert-bar" id="activeAlertBar" role="alert" aria-live="assertive">
      <span class="alert-bar-pulse"></span>
      <div class="alert-bar-text">
        <div class="alert-bar-type" id="activeAlertType">—</div>
        <div class="alert-bar-sub">Broadcasting to all zones &nbsp;·&nbsp; Triggered by <span id="activeAlertOp">—</span></div>
      </div>
      <span class="alert-bar-timer" id="activeAlertTimer">00:00</span>
      <button class="btn-clear-alert" id="clearAlertBtn" onclick="dooClear()">
        <i class="fa-solid fa-circle-check"></i> Clear Alert
      </button>
    </div>

    <!-- Static warning banner (always shown) -->
    <div class="emergency-banner" role="alert">
      <div class="emergency-banner-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div>
        <h2>Emergency Controls Active</h2>
        <p>Any action here will immediately broadcast to ALL zones. This action is logged and cannot be undone.</p>
      </div>
    </div>

    <!-- Type selector card -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-title" style="margin-bottom:0.75rem;">Select Emergency Type</div>
      <div class="emergency-types" id="typeGrid">
        <!-- Each card's data-type must match the ALLOWED_TYPES array in the API -->
        <div class="emergency-type-card selected" data-type="Fire Alert" onclick="selectEmergency(this)" role="button" tabindex="0" aria-pressed="true">
          <div class="et-icon"><i class="fa-solid fa-fire"></i></div>
          <div class="et-name">Fire Alert</div>
        </div>
        <div class="emergency-type-card" data-type="Flood Warning" onclick="selectEmergency(this)" role="button" tabindex="0" aria-pressed="false">
          <div class="et-icon"><i class="fa-solid fa-water"></i></div>
          <div class="et-name">Flood Warning</div>
        </div>
        <div class="emergency-type-card" data-type="Lockdown" onclick="selectEmergency(this)" role="button" tabindex="0" aria-pressed="false">
          <div class="et-icon"><i class="fa-solid fa-lock"></i></div>
          <div class="et-name">Lockdown</div>
        </div>
        <div class="emergency-type-card" data-type="Typhoon" onclick="selectEmergency(this)" role="button" tabindex="0" aria-pressed="false">
          <div class="et-icon"><i class="fa-solid fa-wind"></i></div>
          <div class="et-name">Typhoon</div>
        </div>
        <div class="emergency-type-card" data-type="Earthquake" onclick="selectEmergency(this)" role="button" tabindex="0" aria-pressed="false">
          <div class="et-icon"><i class="fa-solid fa-house-chimney-crack"></i></div>
          <div class="et-name">Earthquake</div>
        </div>
        <div class="emergency-type-card" data-type="Evacuation" onclick="selectEmergency(this)" role="button" tabindex="0" aria-pressed="false">
          <div class="et-icon"><i class="fa-solid fa-person-running"></i></div>
          <div class="et-name">Evacuation</div>
        </div>
      </div>

      <!-- Optional note field -->
      <div class="note-field">
        <label class="note-label" for="alertNote">Note <span style="font-weight:400;text-transform:none;color:var(--text-400);">(optional)</span></label>
        <textarea class="note-input" id="alertNote" rows="2" maxlength="500"
          placeholder="e.g. Drill only — do not evacuate."></textarea>
      </div>
    </div>

    <!-- Big ACTIVATE button -->
    <div class="emergency-btn-wrap">
      <button class="emergency-big-btn" id="activateBtn" onclick="openTriggerOverlay()"
              aria-label="Trigger emergency bell for all zones">
        <span class="e-icon"><i class="fa-solid fa-bell"></i></span>
        <span>ACTIVATE</span>
        <span style="font-size:0.72rem;opacity:0.85;">All Zones</span>
      </button>
    </div>

    <!-- Emergency log table -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
        <div class="card-title">Emergency Log</div>
        <!-- Refresh button so the operator can manually reload the log -->
        <button onclick="loadLog()" style="font-size:0.78rem;color:var(--azure-500);font-weight:600;display:flex;align-items:center;gap:0.35rem;">
          <i class="fa-solid fa-rotate-right"></i> Refresh
        </button>
      </div>
      <div style="overflow-x:auto;">
        <table class="log-table" aria-label="Emergency alert log">
          <thead>
            <tr>
              <th scope="col">Date &amp; Time</th>
              <th scope="col">Type</th>
              <th scope="col">Note</th>
              <th scope="col">Triggered By</th>
              <th scope="col">Duration</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <!-- Rows injected by renderLog() -->
          <tbody id="logBody"></tbody>
        </table>
      </div>
    </div>
  </main>
</div>



<script src='assets/js/main.js' defer ></script>



<script>
/* ================================================================
   CONFIGURATION
================================================================ */
// PHP-rendered — keeps the base URL consistent across all pages
const API_BASE = '<?php echo $API_BASED; ?>';

// CSRF token seeded server-side so the first POST is ready immediately
let csrfToken = '<?php echo $initialCsrfToken; ?>';

// The emergency type currently selected in the type-card grid
let selectedEmType = 'Fire Alert';

// log_id of the currently active alert (null = none active)
let activeLogId     = null;

// Timestamp (ms) when the active alert was triggered — drives the live timer
let activeStartTime = null;

// setInterval handle for the live elapsed-time counter
let timerInterval   = null;

/* ================================================================
   LIVE CLOCK
================================================================ */
function tickClock() {
  document.getElementById('liveClock').textContent =
    new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
tickClock();
setInterval(tickClock, 1000);

/* ================================================================
   SIDEBAR
================================================================ */
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }
function closeSidebar()   { document.getElementById('sidebar').classList.remove('open'); }

/* ================================================================
   EMERGENCY TYPE SELECTION
================================================================ */
function selectEmergency(card) {
  // Ignore clicks when an alert is already active (cards are disabled)
  if (card.classList.contains('disabled')) return;

  document.querySelectorAll('.emergency-type-card').forEach(c => {
    c.classList.remove('selected');
    c.setAttribute('aria-pressed', 'false');
  });
  card.classList.add('selected');
  card.setAttribute('aria-pressed', 'true');

  // Read the type from data-type attribute instead of inner text
  // so it matches the API whitelist exactly
  selectedEmType = card.dataset.type;
}

/* ================================================================
   TRIGGER CONFIRMATION OVERLAY
================================================================ */
function openTriggerOverlay() {
  // Guard: don't allow a second trigger while one is active
  if (activeLogId !== null) {
    showToast('An alert is already active. Clear it before triggering a new one.', 'danger',
      '<i class="fa-solid fa-triangle-exclamation"></i>');
    return;
  }
  // Show the type name in the confirmation dialog
  document.getElementById('confirmTypeName').textContent = selectedEmType;
  document.getElementById('triggerOverlay').classList.add('open');
}
function closeTriggerOverlay() {
  document.getElementById('triggerOverlay').classList.remove('open');
}

/* ================================================================
   TRIGGER — POST action=trigger to the API
================================================================ */
async function doTrigger() {
  const confirmBtn = document.getElementById('confirmTriggerBtn');
  const note       = document.getElementById('alertNote').value.trim();

  const defHtml    = confirmBtn.innerHTML;
  confirmBtn.disabled = true;
  confirmBtn.innerHTML = '<div class="spinner-sm"></div> Activating…';

  try {
    const res = await fetch(`${API_BASE}emergency/emergency.php`, {
      method:      'POST',
      credentials: 'include',   // send session cookie
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token':     csrfToken,   // also in body below
      },
      body: JSON.stringify({
        action:         'trigger',
        csrf_token:     csrfToken,
        emergency_type: selectedEmType,
        note,
        zones:          'All',
      }),
    });

    const data = await res.json();

    // Always sync the CSRF token — the API returns a fresh one on every response
    if (data.csrf_token) csrfToken = data.csrf_token;

    closeTriggerOverlay();

    if (data.success) {
      // Flash the page background red to signal the alert fired
      document.body.style.animation = 'emergency-flash 0.5s ease';
      setTimeout(() => { document.body.style.animation = ''; }, 600);

      showToast(data.message, 'danger', '<i class="fa-solid fa-triangle-exclamation"></i>');

      // Start the active-alert UI state
      setActiveAlertUI(data.log);

      // Prepend the new log row to the table immediately
      prependLogRow(data.log);

    } else {
      showToast(data.message || 'Failed to trigger alert.', 'danger',
        '<i class="fa-solid fa-circle-exclamation"></i>');
    }

  } catch (err) {
    console.error('doTrigger error:', err);
    closeTriggerOverlay();
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
  } finally {
    confirmBtn.disabled = false;
    confirmBtn.innerHTML = defHtml;
  }
}

/* ================================================================
   CLEAR — POST action=clear to the API
================================================================ */
async function dooClear() {
  if (activeLogId === null) return;

  const clearBtn   = document.getElementById('clearAlertBtn');
  const defHtml    = clearBtn.innerHTML;
  clearBtn.disabled = true;
  clearBtn.innerHTML = '<div class="spinner-sm" style="border-top-color:var(--bad-600);border-color:rgba(191,62,54,0.3);"></div> Clearing…';

  try {
    const res = await fetch(`${API_BASE}emergency/emergency.php`, {
      method:      'POST',
      credentials: 'include',
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token':     csrfToken,
      },
      body: JSON.stringify({
        action:     'clear',
        csrf_token: csrfToken,
        log_id:     activeLogId,
      }),
    });

    const data = await res.json();
    if (data.csrf_token) csrfToken = data.csrf_token;

    if (data.success) {
      showToast('Alert cleared — All zones notified.', 'success', '<i class="fa-solid fa-circle-check"></i>');

      // Stop the timer and hide the active-alert bar
      clearActiveAlertUI();

      // Update the row in the table that was showing "Active" → "Cleared"
      updateLogRowCleared(activeLogId, data.duration_min, data.cleared_at_fmt);

    } else {
      showToast(data.message || 'Failed to clear alert.', 'danger',
        '<i class="fa-solid fa-circle-exclamation"></i>');
      clearBtn.disabled = false;
      clearBtn.innerHTML = defHtml;
    }

  } catch (err) {
    console.error('dooClear error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    clearBtn.disabled = false;
    clearBtn.innerHTML = defHtml;
  }
}

/* ================================================================
   ACTIVE ALERT UI — show the pulsing bar, disable type cards & button
================================================================ */
function setActiveAlertUI(log) {
  activeLogId     = log.log_id;
  activeStartTime = log.triggered_at
    ? new Date(log.triggered_at.replace(' ', 'T')).getTime()  // parse "YYYY-MM-DD HH:MM:SS"
    : Date.now();

  // Show the active bar
  document.getElementById('activeAlertType').textContent = log.emergency_type;
  document.getElementById('activeAlertOp').textContent   = log.operator_name ?? '—';
  document.getElementById('activeAlertBar').classList.add('visible');

  // Disable all type cards and the activate button
  document.querySelectorAll('.emergency-type-card').forEach(c => c.classList.add('disabled'));
  document.getElementById('activateBtn').disabled = true;
  document.getElementById('alertNote').disabled   = true;

  // Start the live elapsed-time counter
  startTimer();
}

function clearActiveAlertUI() {
  // Stop the elapsed-time counter
  if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }

  activeLogId     = null;
  activeStartTime = null;

  // Hide the active bar
  document.getElementById('activeAlertBar').classList.remove('visible');

  // Re-enable type cards and the activate button
  document.querySelectorAll('.emergency-type-card').forEach(c => c.classList.remove('disabled'));
  document.getElementById('activateBtn').disabled = false;
  document.getElementById('alertNote').disabled   = false;
}

/* Live MM:SS elapsed-time counter inside the active alert bar */
function startTimer() {
  if (timerInterval) clearInterval(timerInterval);

  timerInterval = setInterval(() => {
    if (!activeStartTime) return;
    const elapsed = Math.floor((Date.now() - activeStartTime) / 1000);
    const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
    const s = String(elapsed % 60).padStart(2, '0');
    document.getElementById('activeAlertTimer').textContent = m + ':' + s;
  }, 1000);
}

/* ================================================================
   LOAD LOG — GET ?action=log from the API
================================================================ */
async function loadLog() {
  showLogSkeletons();

  try {
    const res = await fetch(`${API_BASE}emergency/emergency.php?action=log&limit=20`, {
      credentials: 'include',
      headers:     { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const body = await res.json();

    if (body.csrf_token) csrfToken = body.csrf_token;

    if (!body.success) {
      showToast(body.message || 'Failed to load log.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
      document.getElementById('logBody').innerHTML = '';
      return;
    }

    renderLog(body.logs);

  } catch (err) {
    console.error('loadLog error:', err);
    showToast('Network error loading log.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    document.getElementById('logBody').innerHTML = '';
  }
}

/* ================================================================
   LOAD ACTIVE — GET ?action=active on page load
   Restores the active-alert bar if the page is refreshed mid-alert.
================================================================ */
async function loadActive() {
  try {
    const res = await fetch(`${API_BASE}emergency/emergency.php?action=active`, {
      credentials: 'include',
      headers:     { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const body = await res.json();

    if (body.csrf_token) csrfToken = body.csrf_token;

    if (body.success && body.active) {
      // An alert is currently live — restore the UI state
      setActiveAlertUI({
        log_id:         parseInt(body.active.log_id),
        emergency_type: body.active.emergency_type,
        operator_name:  body.active.operator_name ?? '—',
        triggered_at:   body.active.triggered_at,
      });
    }

  } catch (err) {
    console.error('loadActive error:', err);
  }
}

/* ================================================================
   RENDER LOG TABLE
================================================================ */

// Icon map — keeps the same icons as the type-selector cards
const TYPE_ICONS = {
  'Fire Alert':    'fa-fire',
  'Flood Warning': 'fa-water',
  'Lockdown':      'fa-lock',
  'Typhoon':       'fa-wind',
  'Earthquake':    'fa-house-chimney-crack',
  'Evacuation':    'fa-person-running',
};

function renderLog(logs) {
  const tbody = document.getElementById('logBody');

  if (logs.length === 0) {
    tbody.innerHTML = `
      <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-400);font-size:0.875rem;">
        No emergency events recorded yet.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = logs.map(log => buildLogRow(log)).join('');
}

function buildLogRow(log) {
  const icon     = TYPE_ICONS[log.emergency_type] ?? 'fa-triangle-exclamation';
  const statusCls = log.status === 'active' ? 'active' : 'cleared';
  const statusTxt = log.status === 'active' ? 'Active' : 'Cleared';
  const duration  = log.duration_min !== null ? log.duration_min + ' min' : '—';
  const note      = log.note ? escapeHtml(log.note) : '—';

  return `
    <tr data-log-id="${log.log_id}">
      <td>${escapeHtml(log.triggered_at_fmt)}</td>
      <td><i class="fa-solid ${icon}" style="margin-right:0.35rem;color:var(--signal-600);"></i>${escapeHtml(log.emergency_type)}</td>
      <td style="color:var(--text-600);font-size:0.8rem;">${note}</td>
      <td>${escapeHtml(log.operator_name)}</td>
      <td>${escapeHtml(duration)}</td>
      <td><span class="status-chip ${statusCls}">${statusTxt}</span></td>
    </tr>`;
}

/* Prepend a single new row at the top of the log table after a trigger */
function prependLogRow(log) {
  const tbody   = document.getElementById('logBody');

  // Remove "no entries" placeholder if present
  const empty = tbody.querySelector('td[colspan]');
  if (empty) empty.closest('tr').remove();

  tbody.insertAdjacentHTML('afterbegin', buildLogRow(log));
}

/* Update an existing row in-place when an alert is cleared */
function updateLogRowCleared(logId, durationMin, clearedAtFmt) {
  const row = document.querySelector(`tr[data-log-id="${logId}"]`);
  if (!row) { loadLog(); return; }  // row not in view — full reload

  // Update duration cell (index 4) and status cell (index 5)
  const cells = row.querySelectorAll('td');
  if (cells[4]) cells[4].textContent = durationMin !== null ? durationMin + ' min' : '—';
  if (cells[5]) cells[5].innerHTML   = '<span class="status-chip cleared">Cleared</span>';
}

/* Skeleton loaders while the log request is in flight */
function showLogSkeletons() {
  document.getElementById('logBody').innerHTML = Array(4).fill(`
    <tr>
      <td><span class="skel-line" style="width:140px;"></span></td>
      <td><span class="skel-line" style="width:100px;"></span></td>
      <td><span class="skel-line" style="width:120px;"></span></td>
      <td><span class="skel-line" style="width:80px;"></span></td>
      <td><span class="skel-line" style="width:40px;"></span></td>
      <td><span class="skel-line" style="width:55px;border-radius:999px;"></span></td>
    </tr>`).join('');
}








document.getElementById('triggerOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('triggerOverlay')) closeTriggerOverlay();
});

/* Keyboard support for type cards (Enter / Space to select) */
document.querySelectorAll('.emergency-type-card').forEach(card => {
  card.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectEmergency(card); }
  });
});

/* ================================================================
   XSS PROTECTION — all server data must pass through this before
   being written into innerHTML
================================================================ */
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}


/* ================================================================
   INIT — runs on page ready
================================================================ */
document.addEventListener('DOMContentLoaded', () => {
  // 1. Check if an alert is already active (e.g. after a page refresh)
  loadActive();
  // 2. Load the full emergency log table
  loadLog();
});
</script>
</body>
</html>
