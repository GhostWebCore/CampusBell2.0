<?php
/**
 * dashboard.php
 *
 * Protected page — require_login.php redirects unauthenticated visitors
 * to login.php before any HTML is sent. All dynamic data is fetched
 * via a single AJAX call to api/dashboard/dashboard.php on page load.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/require_login.php';
include_once __DIR__ . '/includes/api_base.php';
require_once __DIR__ . '/includes/csrf.php';

// ----------------------------------------------------------------
// Session data — XSS-escaped for safe HTML output
// ----------------------------------------------------------------
$fullName  = htmlspecialchars($_SESSION['full_name'] ?? 'User',    ENT_QUOTES, 'UTF-8');
$username  = htmlspecialchars($_SESSION['username']  ?? '',         ENT_QUOTES, 'UTF-8');
$userId    = htmlspecialchars((string)($_SESSION['user_id'] ?? ''), ENT_QUOTES, 'UTF-8');

$nameParts = array_filter(explode(' ', $fullName));
$initials  = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($nameParts, 0, 2))));

$loginTime = !empty($_SESSION['login_time'])
    ? date('M j, Y \a\t g:i A', (int)$_SESSION['login_time'])
    : 'This session';

$initialCsrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>CampusBell — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

<link rel="stylesheet" href="assets/css/main.css" />
<style>



/* ============================================================
   MAIN CONTENT
============================================================ */


/* ============================================================
   CARD
============================================================ */
.card{background:var(--paper-1);border-radius:var(--r-md);border:1px solid var(--line);padding:1.3rem;box-shadow:var(--shadow-1);}
.card-title{font-family:var(--font-display);font-size:0.92rem;font-weight:700;color:var(--text-900);margin-bottom:0.25rem;letter-spacing:-0.005em;}
.card-value{font-family:var(--font-display);font-size:2.1rem;font-weight:700;color:var(--text-900);line-height:1.05;}
.card-meta{font-size:0.78rem;color:var(--text-400);margin-top:0.3rem;}

/* ============================================================
   PROFILE CARD
============================================================ */
.profile-card{background:var(--ink-900);background-image:radial-gradient(500px circle at 80% -20%,rgba(59,111,224,0.25),transparent 60%);border-radius:var(--r-md);padding:1.5rem 1.6rem;margin-bottom:1.5rem;color:white;display:flex;align-items:center;gap:1.4rem;flex-wrap:wrap;}
.profile-avatar{width:58px;height:58px;border-radius:50%;background:var(--azure-500);display:flex;align-items:center;justify-content:center;font-size:1.35rem;font-weight:700;color:white;flex-shrink:0;border:2.5px solid rgba(255,255,255,0.2);}
.profile-info{flex:1;min-width:0;}
.profile-name{font-family:var(--font-display);font-size:1.25rem;font-weight:700;margin-bottom:0.2rem;}
.profile-username{font-size:0.8rem;color:rgba(255,255,255,0.5);font-family:var(--font-mono);}
.profile-meta{display:flex;gap:1.5rem;margin-top:1rem;flex-wrap:wrap;}
.profile-meta-item{font-size:0.75rem;color:rgba(255,255,255,0.45);}
.profile-meta-item strong{display:block;font-family:var(--font-display);font-size:0.9rem;color:rgba(255,255,255,0.85);margin-bottom:1px;}

/* ============================================================
   EMERGENCY ALERT BANNER — shown when an active alert exists
============================================================ */
.em-alert-banner{display:none;align-items:center;gap:1rem;flex-wrap:wrap;background:var(--bad-500);color:white;border-radius:var(--r-md);padding:1rem 1.25rem;margin-bottom:1.4rem;animation:alert-in 0.3s var(--ease);}
.em-alert-banner.visible{display:flex;}
@keyframes alert-in{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:none;}}
.em-alert-pulse{width:12px;height:12px;border-radius:50%;background:white;flex-shrink:0;animation:em-pulse 1.2s ease-in-out infinite;}
@keyframes em-pulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.3;transform:scale(0.65);}}
.em-alert-text{flex:1;font-family:var(--font-display);font-size:0.95rem;font-weight:700;}
.em-alert-link{font-size:0.8rem;background:rgba(255,255,255,0.2);padding:0.35rem 0.9rem;border-radius:var(--r-pill);font-weight:600;transition:background var(--t);white-space:nowrap;}
.em-alert-link:hover{background:rgba(255,255,255,0.35);}

/* ============================================================
   STAT CARDS
============================================================ */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem;}
.stat-card{position:relative;overflow:hidden;transition:transform var(--t),box-shadow var(--t);cursor:default;}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-2);}
.stat-icon-wrap{width:38px;height:38px;border-radius:var(--r-xs);display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:0.85rem;}
.stat-card.signal .stat-icon-wrap{background:var(--signal-100);color:var(--signal-600);}
.stat-card.ok     .stat-icon-wrap{background:var(--ok-100);color:var(--ok-500);}
.stat-card.warn   .stat-icon-wrap{background:var(--warn-100);color:var(--warn-500);}
.stat-card.bad    .stat-icon-wrap{background:var(--bad-100);color:var(--bad-500);}

/* Skeleton shimmer for stat cards while loading */
.skel{display:inline-block;height:12px;border-radius:var(--r-xs);background:var(--line);animation:shimmer 1.4s ease-in-out infinite;}
@keyframes shimmer{0%,100%{opacity:1;}50%{opacity:0.4;}}
.skel-val{height:32px;width:60px;border-radius:var(--r-xs);}
.skel-txt{height:11px;width:80px;}
.skel-sub{height:10px;width:110px;margin-top:4px;}

/* ============================================================
   NEXT BELL COUNTDOWN BLOCK
============================================================ */
.countdown-block{display:flex;align-items:center;gap:1.4rem;padding:1.15rem 1.4rem;background:var(--ink-900);background-image:radial-gradient(420px circle at 90% -10%,rgba(240,138,36,0.28),transparent 60%);border-radius:var(--r-md);margin-bottom:1.5rem;color:white;position:relative;overflow:hidden;}
.countdown-rings{position:relative;width:58px;height:58px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.countdown-rings .wave{position:absolute;border-radius:50%;border:1.5px solid rgba(240,138,36,0.5);animation:ringwave2 2.4s var(--ease) infinite;}
.countdown-rings .wave:nth-child(2){animation-delay:0.8s;}
@keyframes ringwave2{0%{width:30px;height:30px;opacity:0.8;}100%{width:58px;height:58px;opacity:0;}}
.countdown-rings .core{width:30px;height:30px;border-radius:50%;background:var(--signal-500);display:flex;align-items:center;justify-content:center;color:var(--ink-950);font-size:14px;z-index:2;}
.countdown-label{font-size:0.78rem;color:rgba(255,255,255,0.55);margin-bottom:0.2rem;letter-spacing:0.02em;}
.countdown-time{font-family:var(--font-mono);font-size:1.7rem;font-weight:600;letter-spacing:0.01em;}
.countdown-sub{font-size:0.79rem;color:rgba(255,255,255,0.5);margin-top:0.15rem;}
/* "No more bells today" variant */
.countdown-block.done .countdown-rings .core{background:var(--ink-600);}
.countdown-block.done .countdown-rings .wave{display:none;}

/* ============================================================
   DASH GRID (activity + upcoming)
============================================================ */
.dash-grid{display:grid;grid-template-columns:2fr 1fr;gap:1rem;}
@media(max-width:980px){.dash-grid{grid-template-columns:1fr;}}
.section-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;}
.section-link{font-size:0.78rem;color:var(--azure-500);font-weight:600;display:flex;align-items:center;gap:0.3rem;transition:color var(--t);}
.section-link:hover{color:var(--azure-600);}

/* Activity list */
.activity-list{display:flex;flex-direction:column;gap:0.2rem;}
.activity-item{display:flex;align-items:center;gap:0.8rem;padding:0.62rem 0.6rem;border-radius:var(--r-xs);font-size:0.84rem;transition:background var(--t);}
.activity-item:hover{background:var(--paper);}
.activity-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
.activity-icon.success{background:var(--ok-100);color:var(--ok-500);}
.activity-icon.info   {background:var(--azure-100);color:var(--azure-500);}
.activity-icon.warning{background:var(--warn-100);color:var(--warn-500);}
.activity-icon.danger {background:var(--bad-100);color:var(--bad-500);}
.activity-text{color:var(--text-900);flex:1;}
.activity-time{margin-left:auto;font-size:0.74rem;color:var(--text-400);font-family:var(--font-mono);flex-shrink:0;}

/* Upcoming events list */
.upcoming-list{display:flex;flex-direction:column;gap:0.5rem;}
.upcoming-item{display:flex;align-items:center;gap:0.7rem;padding:0.55rem 0.65rem;background:var(--paper);border-radius:var(--r-xs);font-size:0.83rem;}
.upcoming-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.upcoming-dot.blue  {background:var(--azure-500);}
.upcoming-dot.green {background:var(--ok-500);}
.upcoming-dot.amber {background:var(--warn-500);}
.upcoming-dot.purple{background:#6A36B5;}
.upcoming-date{font-family:var(--font-mono);font-size:0.72rem;color:var(--text-400);flex-shrink:0;min-width:36px;}
.upcoming-title{flex:1;color:var(--text-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

/* ============================================================
   OVERLAY — Logout
============================================================ */

</style>
</head>
<body>
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<!-- Logout overlay -->
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

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<nav class="sidebar" id="sidebar" aria-label="Main navigation">
  <div class="sidebar-header">
    <div class="sidebar-logo-icon"><i class="fa-solid fa-bell"></i></div>
    <div class="sidebar-logo-text"><h2>CampusBell</h2><span>IoT Bell System</span></div>
  </div>
  <div class="nav-section">
    <div class="nav-section-label">Main</div>
    <a href="dashboard.php" class="nav-item active" aria-current="page">
      <span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span> Dashboard
    </a>
    <a href="announcement.php" class="nav-item">
      <span class="nav-icon"><i class="fa-solid fa-bullhorn"></i></span> Announcements
    </a>
    <div class="nav-section-label">Scheduling</div>
    <a href="schedule.php" class="nav-item">
      <span class="nav-icon"><i class="fa-solid fa-clock"></i></span> Schedule Management
    </a>
    <a href="event.php" class="nav-item">
      <span class="nav-icon"><i class="fa-solid fa-calendar-days"></i></span> Events Calendar
    </a>
    <div class="nav-section-label">Control</div>
    <a href="emergency.php" class="nav-item emergency-nav">
      <span class="nav-icon"><i class="fa-solid fa-triangle-exclamation"></i></span> Emergency Override
    </a>
  </div>
  <div class="sidebar-footer">
    <div class="user-chip" id="userChip" onclick="toggleUserDropdown()" aria-haspopup="true" aria-expanded="false">
      <div class="user-avatar"><?php echo $initials; ?></div>
      <div class="user-info">
        <div class="user-name"><?php echo $fullName; ?></div>
        <div class="user-role">System Administrator</div>
      </div>
      <i class="fa-solid fa-chevron-down"></i>
    </div>
    <div class="user-dropdown" id="userDropdown">
      <div class="user-dropdown-header">
        <div class="uname"><?php echo $fullName; ?></div>
        <div class="uid">@<?php echo $username; ?></div>
        <div class="ulast"><i class="fa-regular fa-clock" style="margin-right:4px;"></i>Signed in <?php echo $loginTime; ?></div>
      </div>
      <div class="user-dropdown-item" onclick="showToast('Settings coming soon.','info','<i class=&quot;fa-solid fa-gear&quot;></i>');closeUserDropdown();">
        <i class="fa-solid fa-gear"></i> Settings
      </div>
      <div class="user-dropdown-item danger" onclick="openLogoutOverlay();closeUserDropdown();">
        <i class="fa-solid fa-right-from-bracket"></i> Sign out
      </div>
    </div>
  </div>
</nav>

<!-- ============================================================
     MAIN CONTENT
============================================================ -->
<div class="main-content" id="mainContent">
  <header class="topbar">
    <button class="topbar-menu-btn" onclick="toggleSidebar()" aria-label="Open navigation" aria-expanded="false" id="menuBtn">
      <i class="fa-solid fa-bars"></i>
    </button>
    <div class="topbar-title-wrap">
      <span class="topbar-title">Dashboard</span>
      <span class="topbar-crumb">CampusBell / Dashboard</span>
    </div>
    <div class="topbar-right">
      <div class="status-pill"><span class="status-dot"></span><span class="status-label">System Online</span></div>
      <span class="topbar-time" id="liveClock">--:--:--</span>
      <button class="topbar-icon-btn" onclick="showToast('No new notifications','info','<i class=&quot;fa-solid fa-bell&quot;></i>')" aria-label="Notifications">
        <i class="fa-solid fa-bell"></i><span class="dot"></span>
      </button>
      <button class="topbar-logout-btn" onclick="openLogoutOverlay()" aria-label="Sign out">
        <i class="fa-solid fa-right-from-bracket"></i><span>Sign out</span>
      </button>
    </div>
  </header>

  <main class="page-content" role="main">
    <h1 class="page-heading">Dashboard</h1>
    <p class="page-subheading">Overview of your campus bell system for today.</p>

    <!-- Active emergency banner — hidden until API confirms one is live -->
    <div class="em-alert-banner" id="emAlertBanner" role="alert" aria-live="assertive">
      <span class="em-alert-pulse"></span>
      <span class="em-alert-text" id="emAlertText">Emergency alert active</span>
      <a href="emergency.php" class="em-alert-link">View &rarr;</a>
    </div>

    <!-- Profile card — server-rendered from session, no AJAX needed -->
    <div class="profile-card">
      <div class="profile-avatar"><?php echo $initials; ?></div>
      <div class="profile-info">
        <div class="profile-name"><?php echo $fullName; ?></div>
        <div class="profile-username">@<?php echo $username; ?> &nbsp;·&nbsp; ID: <?php echo $userId; ?></div>
        <div class="profile-meta">
          <div class="profile-meta-item"><strong>System Administrator</strong>Role</div>
          <div class="profile-meta-item"><strong><?php echo $loginTime; ?></strong>Session started</div>
          <div class="profile-meta-item"><strong>Active</strong>Account status</div>
        </div>
      </div>
    </div>

    <!-- Next bell countdown — driven by real schedule data from API -->
    <div class="countdown-block" id="countdownBlock" aria-label="Next bell countdown">
      <div class="countdown-rings">
        <div class="wave"></div><div class="wave"></div>
        <div class="core"><i class="fa-solid fa-bell"></i></div>
      </div>
      <div>
        <div class="countdown-label" id="countdownLabel">Next bell rings in</div>
        <div class="countdown-time" id="nextBellCountdown">--:--:--</div>
        <div class="countdown-sub" id="countdownSub">Loading schedule…</div>
      </div>
    </div>

    <!-- Stat cards — values injected by renderStats() -->
    <div class="stats-grid">
      <!-- Bells today -->
      <div class="card stat-card signal" tabindex="0">
        <div class="stat-icon-wrap"><i class="fa-solid fa-bell"></i></div>
        <div class="card-value" id="statBellsToday">
          <span class="skel skel-val"></span>
        </div>
        <div class="card-title">Bell Rings Today</div>
        <div class="card-meta" id="statBellsMeta"><span class="skel skel-sub"></span></div>
      </div>
      <!-- Active schedules -->
      <div class="card stat-card ok" tabindex="0">
        <div class="stat-icon-wrap"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="card-value" id="statSchedules">
          <span class="skel skel-val"></span>
        </div>
        <div class="card-title">Active Schedules</div>
        <div class="card-meta" id="statSchedulesMeta"><span class="skel skel-sub"></span></div>
      </div>
      <!-- Announcements -->
      <div class="card stat-card warn" tabindex="0">
        <div class="stat-icon-wrap"><i class="fa-solid fa-bullhorn"></i></div>
        <div class="card-value" id="statAnnouncements">
          <span class="skel skel-val"></span>
        </div>
        <div class="card-title">Announcements</div>
        <div class="card-meta" id="statAnnouncementsMeta"><span class="skel skel-sub"></span></div>
      </div>
      <!-- Upcoming events -->
      <div class="card stat-card bad" tabindex="0">
        <div class="stat-icon-wrap"><i class="fa-solid fa-calendar-star"></i></div>
        <div class="card-value" id="statEvents">
          <span class="skel skel-val"></span>
        </div>
        <div class="card-title">Upcoming Events</div>
        <div class="card-meta" id="statEventsMeta"><span class="skel skel-sub"></span></div>
      </div>
    </div>

    <!-- Bottom grid: recent activity + upcoming events -->
    <div class="dash-grid">
      <!-- Recent activity -->
      <div class="card">
        <div class="section-title-row">
          <div class="card-title" style="margin-bottom:0;">Recent Activity</div>
          <a href="schedule.php" class="section-link">View schedule <i class="fa-solid fa-arrow-right" style="font-size:11px;"></i></a>
        </div>
        <!-- Skeleton rows while loading -->
        <div class="activity-list" id="activityList">
          <?php for ($i = 0; $i < 5; $i++): ?>
          <div class="activity-item">
            <span class="activity-icon info" style="opacity:0.3;"><i class="fa-solid fa-circle"></i></span>
            <span class="activity-text"><span class="skel" style="width:<?= [180,220,160,200,170][$i] ?>px;"></span></span>
            <span class="activity-time"><span class="skel" style="width:50px;"></span></span>
          </div>
          <?php endfor; ?>
        </div>
      </div>

      <!-- Upcoming events -->
      <div class="card">
        <div class="section-title-row">
          <div class="card-title" style="margin-bottom:0;">Upcoming Events</div>
          <a href="event.php" class="section-link">Calendar <i class="fa-solid fa-arrow-right" style="font-size:11px;"></i></a>
        </div>
        <div class="upcoming-list" id="upcomingList">
          <?php for ($i = 0; $i < 4; $i++): ?>
          <div class="upcoming-item">
            <span class="skel" style="width:8px;height:8px;border-radius:50%;"></span>
            <span class="skel" style="width:32px;height:10px;"></span>
            <span class="skel" style="width:<?= [110,90,120,80][$i] ?>px;height:10px;"></span>
          </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </main>
</div>



<script src='assets/js/main.js' defer ></script>



<script>
/* ================================================================
   CONFIG — PHP-rendered, so URLs are always correct
================================================================ */
const API_BASE  = '<?php echo $API_BASED; ?>';
let   csrfToken = '<?php echo $initialCsrfToken; ?>';

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
   COUNTDOWN TIMER
   Drives the "next bell" block. nextBellSeconds is set by
   loadDashboard() from real API data and counts down live.
================================================================ */
let nextBellSeconds = null;   // null = not yet loaded
let countdownHandle = null;

function startCountdown(secondsUntil, bellName, zones) {
  // Clear any previous timer
  if (countdownHandle) clearInterval(countdownHandle);
  nextBellSeconds = secondsUntil;

  const block  = document.getElementById('countdownBlock');
  const label  = document.getElementById('countdownLabel');
  const sub    = document.getElementById('countdownSub');

  block.classList.remove('done');
  label.textContent = 'Next bell rings in';
  sub.textContent   = bellName + (zones ? ' · ' + zones : '');

  function tick() {
    if (nextBellSeconds <= 0) {
      // Bell has fired — show "Rings now!" briefly then reload the data
      document.getElementById('nextBellCountdown').textContent = 'Ringing!';
      clearInterval(countdownHandle);
      setTimeout(loadDashboard, 3000);  // reload to get the next bell
      return;
    }
    const h = Math.floor(nextBellSeconds / 3600);
    const m = Math.floor((nextBellSeconds % 3600) / 60);
    const s = nextBellSeconds % 60;
    document.getElementById('nextBellCountdown').textContent =
      String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    nextBellSeconds--;
  }

  tick();
  countdownHandle = setInterval(tick, 1000);
}

function showNoBells() {
  if (countdownHandle) clearInterval(countdownHandle);
  const block = document.getElementById('countdownBlock');
  block.classList.add('done');
  document.getElementById('countdownLabel').textContent   = 'No more bells today';
  document.getElementById('nextBellCountdown').textContent = '--:--:--';
  document.getElementById('countdownSub').textContent     = 'Schedule resumes tomorrow';
}

/* ================================================================
   LOAD DASHBOARD — single API call for all dynamic data
================================================================ */
async function loadDashboard() {
  try {
    const res = await fetch(`${API_BASE}dashboard/dashboard.php`, {
      credentials: 'include',
      headers:     { 'X-Requested-With': 'XMLHttpRequest' },
    });

    const data = await res.json();
    if (data.csrf_token) csrfToken = data.csrf_token;

    if (!data.success) {
      showToast(data.message || 'Failed to load dashboard data.', 'danger',
        '<i class="fa-solid fa-circle-exclamation"></i>');
      return;
    }

    renderStats(data.stats);
    renderCountdown(data.next_bell);
    renderActivity(data.recent_activity);
    renderUpcoming(data.upcoming_events);
    renderEmergencyBanner(data.stats.active_emergency);

  } catch (err) {
    console.error('loadDashboard error:', err);
    showToast('Network error loading dashboard.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
  }
}

/* ================================================================
   RENDER STAT CARDS
================================================================ */
function renderStats(stats) {
  // Bells today
  document.getElementById('statBellsToday').textContent = stats.bells_today;
  document.getElementById('statBellsMeta').textContent  =
    stats.bells_completed + ' completed · ' + stats.bells_pending + ' pending';

  // Active schedules
  document.getElementById('statSchedules').textContent     = stats.active_schedules;
  document.getElementById('statSchedulesMeta').textContent = 'Enabled bell schedules';

  // Announcements
  document.getElementById('statAnnouncements').textContent     = stats.total_announcements;
  document.getElementById('statAnnouncementsMeta').textContent = 'Published announcements';

  // Upcoming events (this month)
  document.getElementById('statEvents').textContent     = stats.upcoming_events;
  document.getElementById('statEventsMeta').textContent = 'Events remaining this month';
}

/* ================================================================
   RENDER NEXT BELL COUNTDOWN
================================================================ */
function renderCountdown(nextBell) {
  if (!nextBell || nextBell.seconds_until <= 0) {
    showNoBells();
    return;
  }
  startCountdown(
    nextBell.seconds_until,
    nextBell.bell_name,
    nextBell.zones
  );
}

/* ================================================================
   RENDER RECENT ACTIVITY LIST
================================================================ */
function renderActivity(items) {
  const list = document.getElementById('activityList');

  if (!items || items.length === 0) {
    list.innerHTML = `<p style="font-size:0.875rem;color:var(--text-400);padding:0.5rem 0.6rem;">No activity recorded today yet.</p>`;
    return;
  }

  list.innerHTML = items.map(item => `
    <div class="activity-item">
      <span class="activity-icon ${escapeHtml(item.color_class)}">
        <i class="${escapeHtml(item.icon_class)}"></i>
      </span>
      <span class="activity-text">${escapeHtml(item.text)}</span>
      <span class="activity-time">${escapeHtml(item.time_fmt)}</span>
    </div>`).join('');
}

/* ================================================================
   RENDER UPCOMING EVENTS LIST
================================================================ */
function renderUpcoming(events) {
  const list = document.getElementById('upcomingList');

  if (!events || events.length === 0) {
    list.innerHTML = `<p style="font-size:0.875rem;color:var(--text-400);padding:0.5rem 0;">No upcoming events this month.</p>`;
    return;
  }

  list.innerHTML = events.map(ev => `
    <div class="upcoming-item">
      <span class="upcoming-dot ${escapeHtml(ev.color)}"></span>
      <span class="upcoming-date">${escapeHtml(ev.date_fmt)}</span>
      <span class="upcoming-title" title="${escapeHtml(ev.event_title)}">${escapeHtml(ev.event_title)}</span>
    </div>`).join('');
}

/* ================================================================
   RENDER EMERGENCY BANNER
   Show a red pulsing banner at the top if any alert is active.
================================================================ */
function renderEmergencyBanner(isActive) {
  const banner = document.getElementById('emAlertBanner');
  if (isActive) {
    document.getElementById('emAlertText').textContent = '⚠ Emergency alert is currently active — All zones broadcasting';
    banner.classList.add('visible');
  } else {
    banner.classList.remove('visible');
  }
}



/* ================================================================
   SIDEBAR
================================================================ */
function toggleSidebar() {
  const s   = document.getElementById('sidebar');
  const btn = document.getElementById('menuBtn');
  s.classList.toggle('open');
  btn.setAttribute('aria-expanded', s.classList.contains('open'));
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('menuBtn').setAttribute('aria-expanded', 'false');
}

/* ================================================================
   USER DROPDOWN
================================================================ */
function toggleUserDropdown() {
  const chip   = document.getElementById('userChip');
  const drop   = document.getElementById('userDropdown');
  const isOpen = drop.classList.toggle('open');
  chip.classList.toggle('open', isOpen);
  chip.setAttribute('aria-expanded', isOpen);
}
function closeUserDropdown() {
  document.getElementById('userDropdown').classList.remove('open');
  document.getElementById('userChip').classList.remove('open');
  document.getElementById('userChip').setAttribute('aria-expanded', 'false');
}
document.addEventListener('click', e => {
  if (!document.getElementById('userChip').contains(e.target) &&
      !document.getElementById('userDropdown').contains(e.target)) closeUserDropdown();
});


/* ================================================================
   XSS PROTECTION
================================================================ */
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}


/* ================================================================
   INIT
================================================================ */
document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();
  // Auto-refresh every 60 seconds so stats stay current
  setInterval(loadDashboard, 60_000);
});
</script>
</body>
</html>
