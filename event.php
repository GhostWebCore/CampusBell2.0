<?php
/**
 * event.php
 *
 * Protected page — require_login.php redirects unauthenticated
 * visitors to login.php before any HTML is output.
 */
declare(strict_types=1);

// Auth guard: halts with redirect or 401 JSON if no valid session
require_once __DIR__ . '/includes/require_login.php';

// API base URL (protocol + host + subfolder aware)
include_once __DIR__ . '/includes/api_base.php';

// Embed the first CSRF token server-side so the first POST
// doesn't need a separate prefetch round-trip
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
<title>CampusBell — Events Calendar</title>
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

/* ============================================================
   CALENDAR
============================================================ */
.calendar-nav{display:flex;align-items:center;gap:0.9rem;margin-bottom:1.2rem;}
.cal-nav-btn{width:36px;height:36px;border-radius:var(--r-sm);border:1.5px solid var(--line-2);background:var(--paper-1);display:flex;align-items:center;justify-content:center;color:var(--text-600);font-size:14px;transition:background var(--t),border-color var(--t);}
.cal-nav-btn:hover{background:var(--paper);}
.cal-month-title{font-family:var(--font-display);font-size:1.1rem;font-weight:700;color:var(--text-900);min-width:150px;}
.btn-blue{padding:0.62rem 1.2rem;border-radius:var(--r-sm);font-size:0.875rem;font-weight:600;background:var(--ink-900);color:white;display:flex;align-items:center;gap:0.5rem;transition:background var(--t),transform var(--t);}
.btn-blue:hover{background:var(--signal-600);transform:translateY(-1px);}
.btn-blue:disabled{opacity:0.65;cursor:not-allowed;transform:none;}

.calendar-grid-wrap{background:var(--paper-1);border-radius:var(--r-md);border:1px solid var(--line);overflow:hidden;box-shadow:var(--shadow-1);}
.cal-header{display:grid;grid-template-columns:repeat(7,1fr);background:var(--paper);border-bottom:1px solid var(--line);}
.cal-day-name{padding:0.75rem 0.5rem;text-align:center;font-size:0.68rem;font-weight:700;color:var(--text-400);text-transform:uppercase;letter-spacing:0.05em;}
.cal-body{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-cell{min-height:84px;padding:0.5rem;border-right:1px solid var(--line);border-bottom:1px solid var(--line);font-size:0.78rem;cursor:pointer;transition:background var(--t);display:flex;flex-direction:column;gap:0.25rem;position:relative;}
.cal-cell:hover{background:var(--azure-100);}
.cal-cell:nth-child(7n){border-right:none;}
.cal-cell.other-month{color:var(--text-400);background:var(--paper);cursor:default;}
.cal-cell.other-month:hover{background:var(--paper);}
.cal-date{font-weight:600;font-family:var(--font-mono);}
.cal-cell.today{background:var(--signal-100);}
.cal-cell.today .cal-date{color:var(--signal-600);}
.cal-cell.selected{outline:2px solid var(--azure-500);outline-offset:-2px;}
.cal-event-pill{font-size:0.62rem;padding:1px 6px;border-radius:var(--r-pill);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cal-event-pill.blue  {background:var(--azure-100);color:var(--azure-600);}
.cal-event-pill.green {background:var(--ok-100);color:var(--ok-500);}
.cal-event-pill.amber {background:var(--warn-100);color:var(--warn-500);}
.cal-event-pill.purple{background:#EDE3FB;color:#6A36B5;}
@media(max-width:700px){.cal-cell{min-height:56px;font-size:0.7rem;}.cal-event-pill{display:none;}}

/* Calendar loading shimmer */
.cal-loading{display:flex;align-items:center;justify-content:center;min-height:200px;color:var(--text-400);font-size:0.875rem;gap:0.6rem;}
.cal-spinner{width:18px;height:18px;border-radius:50%;border:2px solid var(--line-2);border-top-color:var(--azure-500);animation:spin 0.7s linear infinite;flex-shrink:0;}
@keyframes spin{to{transform:rotate(360deg);}}

/* ============================================================
   DAY DETAIL POPOVER
   Appears when clicking a calendar cell that has events.
============================================================ */
.day-popover{
  display:none;position:fixed;z-index:300;
  background:var(--paper-1);border:1px solid var(--line);
  border-radius:var(--r-md);box-shadow:var(--shadow-3);
  padding:1rem 1.1rem;min-width:240px;max-width:320px;
  animation:popover-in 0.15s var(--ease);
}
.day-popover.open{display:block;}
@keyframes popover-in{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:none;}}
.day-popover-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;}
.day-popover-title{font-family:var(--font-display);font-size:0.92rem;font-weight:700;color:var(--text-900);}
.day-popover-close{width:24px;height:24px;border-radius:var(--r-xs);display:flex;align-items:center;justify-content:center;color:var(--text-400);font-size:13px;transition:background var(--t);}
.day-popover-close:hover{background:var(--paper);color:var(--text-900);}
.day-popover-list{display:flex;flex-direction:column;gap:0.5rem;}
.day-popover-item{display:flex;align-items:flex-start;gap:0.6rem;padding:0.45rem 0.5rem;border-radius:var(--r-xs);transition:background var(--t);}
.day-popover-item:hover{background:var(--paper);}
.day-popover-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:4px;}
.day-popover-dot.blue  {background:var(--azure-500);}
.day-popover-dot.green {background:var(--ok-500);}
.day-popover-dot.amber {background:var(--warn-500);}
.day-popover-dot.purple{background:#6A36B5;}
.day-popover-body{flex:1;min-width:0;}
.day-popover-event-title{font-size:0.83rem;font-weight:600;color:var(--text-900);}
.day-popover-desc{font-size:0.76rem;color:var(--text-600);margin-top:1px;line-height:1.45;}
.day-popover-impact{font-size:0.68rem;font-weight:600;padding:2px 8px;border-radius:var(--r-pill);margin-top:4px;display:inline-block;}
.day-popover-impact.none     {background:var(--line);color:var(--text-400);}
.day-popover-impact.modified {background:var(--warn-100);color:var(--warn-500);}
.day-popover-impact.suspended{background:var(--bad-100);color:var(--bad-500);}
.day-popover-impact.override {background:var(--signal-100);color:var(--signal-600);}
.day-popover-delete{width:24px;height:24px;border-radius:var(--r-xs);display:flex;align-items:center;justify-content:center;color:var(--bad-500);font-size:12px;flex-shrink:0;transition:background var(--t);}
.day-popover-delete:hover{background:var(--bad-100);}
.day-popover-add{width:100%;margin-top:0.65rem;padding:0.45rem 0;border-top:1px solid var(--line);font-size:0.78rem;font-weight:600;color:var(--azure-500);display:flex;align-items:center;justify-content:center;gap:0.4rem;transition:color var(--t);}
.day-popover-add:hover{color:var(--azure-600);}

/* ============================================================
   UPCOMING EVENTS LIST
============================================================ */
.activity-list{display:flex;flex-direction:column;gap:0.2rem;}
.activity-item{display:flex;align-items:center;gap:0.8rem;padding:0.65rem 0.6rem;border-radius:var(--r-xs);font-size:0.84rem;transition:background var(--t);}
.activity-item:hover{background:var(--paper);}
.activity-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
.activity-icon.purple{background:#EDE3FB;color:#6A36B5;}
.activity-icon.info   {background:var(--azure-100);color:var(--azure-500);}
.activity-icon.success{background:var(--ok-100);color:var(--ok-500);}
.activity-icon.warning{background:var(--warn-100);color:var(--warn-500);}
.activity-icon.amber  {background:var(--warn-100);color:var(--warn-500);}
.activity-icon.blue   {background:var(--azure-100);color:var(--azure-500);}
.activity-icon.green  {background:var(--ok-100);color:var(--ok-500);}
.activity-text{color:var(--text-900);flex:1;}
.activity-del-btn{width:26px;height:26px;border-radius:var(--r-xs);display:flex;align-items:center;justify-content:center;color:var(--text-400);font-size:12px;flex-shrink:0;transition:background var(--t),color var(--t);}
.activity-del-btn:hover{background:var(--bad-100);color:var(--bad-500);}

/* Skeleton for upcoming list */
.skel-line{height:12px;border-radius:var(--r-xs);background:var(--line);animation:shimmer 1.4s ease-in-out infinite;display:inline-block;}
@keyframes shimmer{0%,100%{opacity:1;}50%{opacity:0.4;}}


/* Color picker — styled radio buttons */
.color-picker{display:flex;gap:0.6rem;flex-wrap:wrap;}
.color-opt{cursor:pointer;}
.color-opt input{display:none;}
.color-opt span{display:inline-flex;align-items:center;gap:0.4rem;padding:0.38rem 0.85rem;border-radius:var(--r-pill);border:1.5px solid var(--line-2);font-size:0.78rem;font-weight:600;color:var(--text-600);transition:background var(--t),border-color var(--t),color var(--t);}
.color-dot{width:8px;height:8px;border-radius:50%;}
.color-opt input:checked+span.opt-blue  {background:var(--azure-100);border-color:var(--azure-500);color:var(--azure-600);}
.color-opt input:checked+span.opt-green {background:var(--ok-100);border-color:var(--ok-500);color:var(--ok-500);}
.color-opt input:checked+span.opt-amber {background:var(--warn-100);border-color:var(--warn-500);color:var(--warn-500);}
.color-opt input:checked+span.opt-purple{background:#EDE3FB;border-color:#6A36B5;color:#6A36B5;}

.btn-ghost{padding:0.6rem 1.1rem;border-radius:var(--r-sm);border:1.5px solid var(--line-2);font-size:0.875rem;font-weight:600;color:var(--text-600);transition:background var(--t);}
.btn-ghost:hover{background:var(--paper);}
.btn-submit{padding:0.6rem 1.4rem;border-radius:var(--r-sm);background:var(--signal-500);color:white;font-size:0.875rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;transition:background var(--t);}
.btn-submit:hover{background:var(--signal-600);}
.btn-submit:disabled{opacity:0.65;cursor:not-allowed;}
.spinner-sm{width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,0.35);border-top-color:white;animation:spin 0.7s linear infinite;}

/* ============================================================
   LOGOUT OVERLAY
============================================================ */

</style>
</head>
<body>
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<!-- ============================================================
     DAY DETAIL POPOVER
     Positioned by JS near the clicked calendar cell
============================================================ -->
<div class="day-popover" id="dayPopover" role="dialog" aria-label="Day events">
  <div class="day-popover-header">
    <span class="day-popover-title" id="popoverTitle">Events</span>
    <button class="day-popover-close" onclick="closePopover()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="day-popover-list" id="popoverList"></div>
  <!-- Quick "add event on this date" shortcut inside the popover -->
  <button class="day-popover-add" id="popoverAddBtn" onclick="openModalForDate()">
    <i class="fa-solid fa-plus"></i> Add event on this day
  </button>
</div>

<!-- ============================================================
     ADD EVENT MODAL
============================================================ -->
<div class="modal-backdrop" id="eventModal" role="dialog" aria-modal="true" aria-labelledby="eventModalTitle">
  <div class="modal-box">
    <div class="modal-header">
      <h2 class="modal-title" id="eventModalTitle">Add Event</h2>
      <button class="modal-close" onclick="closeModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="form-row">
      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label" for="fTitle">Event Title <span style="color:var(--bad-500)">*</span></label>
        <input class="form-input" type="text" id="fTitle" maxlength="180" placeholder="e.g. Recognition Day" autocomplete="off" />
        <div class="form-error" id="errTitle">Event title is required.</div>
      </div>
    </div>

    <div class="form-row">
      <!-- Date field — pre-filled when opened from a calendar cell click -->
      <div class="form-group">
        <label class="form-label" for="fDate">Date <span style="color:var(--bad-500)">*</span></label>
        <input class="form-input" type="date" id="fDate" />
        <div class="form-error" id="errDate">Please select a date.</div>
      </div>
      <!-- Bell impact -->
      <div class="form-group">
        <label class="form-label" for="fBellImpact">Bell Impact</label>
        <select class="form-select" id="fBellImpact">
          <option value="none">None</option>
          <option value="modified">Modified schedule</option>
          <option value="suspended">Suspended (no bells)</option>
          <option value="override">Override</option>
        </select>
      </div>
    </div>

    <!-- Description -->
    <div class="form-group">
      <label class="form-label" for="fDesc">Description</label>
      <textarea class="form-textarea" id="fDesc" maxlength="3000" placeholder="Optional — details about the event, bell adjustments, etc."></textarea>
    </div>

    <!-- Colour picker -->
    <div class="form-group">
      <label class="form-label">Colour Tag</label>
      <div class="color-picker">
        <label class="color-opt"><input type="radio" name="fColor" value="blue" checked />
          <span class="opt-blue"><span class="color-dot" style="background:var(--azure-500)"></span>Blue</span></label>
        <label class="color-opt"><input type="radio" name="fColor" value="green" />
          <span class="opt-green"><span class="color-dot" style="background:var(--ok-500)"></span>Green</span></label>
        <label class="color-opt"><input type="radio" name="fColor" value="amber" />
          <span class="opt-amber"><span class="color-dot" style="background:var(--warn-500)"></span>Amber</span></label>
        <label class="color-opt"><input type="radio" name="fColor" value="purple" />
          <span class="opt-purple"><span class="color-dot" style="background:#6A36B5"></span>Purple</span></label>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn-submit" id="submitEventBtn" onclick="submitEvent()">
        <i class="fa-solid fa-calendar-plus"></i> Add Event
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
    <a href='dashboard.php' class="nav-item" aria-current="page">
      <span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span> Dashboard
    </a>
    <a href='announcement.php' class="nav-item" >
      <span class="nav-icon"><i class="fa-solid fa-bullhorn"></i></span> Announcements
    </a>

    <div class="nav-section-label">Scheduling</div>

    <a href='schedule.php' class="nav-item" >
      <span class="nav-icon"><i class="fa-solid fa-clock"></i></span> Schedule Management
    </a>
    <a href='event.php' class="nav-item active" >
      <span class="nav-icon"><i class="fa-solid fa-calendar-days"></i></span> Events Calendar
    </a>

    <div class="nav-section-label">Control</div>
    <a href='emergency.php' class="nav-item" >
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
      <span class="topbar-title">Events Calendar</span>
      <span class="topbar-crumb">CampusBell / Events</span>
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

  <main class="page-content" id="content-calendar" role="main">
    <h1 class="page-heading">Events Calendar</h1>
    <p class="page-subheading">View and plan campus events and special bell schedules.</p>

    <div class="calendar-nav">
      <button class="cal-nav-btn" onclick="changeMonth(-1)" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
      <h2 class="cal-month-title" id="calMonthTitle">Loading…</h2>
      <button class="cal-nav-btn" onclick="changeMonth(1)" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button>
      <button class="btn-blue" style="margin-left:auto;" onclick="openModal()">
        <i class="fa-solid fa-plus"></i> Add Event
      </button>
    </div>

    <div class="calendar-grid-wrap" role="grid" aria-label="Monthly calendar">
      <div class="cal-header" role="row">
        <div class="cal-day-name" role="columnheader">Sun</div>
        <div class="cal-day-name" role="columnheader">Mon</div>
        <div class="cal-day-name" role="columnheader">Tue</div>
        <div class="cal-day-name" role="columnheader">Wed</div>
        <div class="cal-day-name" role="columnheader">Thu</div>
        <div class="cal-day-name" role="columnheader">Fri</div>
        <div class="cal-day-name" role="columnheader">Sat</div>
      </div>
      <!-- Calendar cells injected by renderCalendar() -->
      <div class="cal-body" id="calBody" role="rowgroup">
        <div class="cal-loading" style="grid-column:1/-1;">
          <span class="cal-spinner"></span> Loading calendar…
        </div>
      </div>
    </div>

    <!-- Upcoming events list — injected by renderUpcoming() -->
    <div class="card" style="margin-top:1.25rem;">
      <div class="card-title" style="margin-bottom:1rem;">Upcoming Events</div>
      <div class="activity-list" id="upcomingList">
        <!-- Skeleton placeholders while loading -->
        <div class="activity-item">
          <span class="activity-icon info" style="opacity:0.3;"><i class="fa-solid fa-calendar-day"></i></span>
          <span><span class="skel-line" style="width:220px;"></span></span>
        </div>
        <div class="activity-item">
          <span class="activity-icon info" style="opacity:0.3;"><i class="fa-solid fa-calendar-day"></i></span>
          <span><span class="skel-line" style="width:180px;"></span></span>
        </div>
      </div>
    </div>
  </main>
</div><!-- /.main-content -->




<script src='assets/js/main.js' defer ></script>


<script>
/* ================================================================
   CONFIGURATION
================================================================ */
// PHP-rendered so the base URL is always correct for this deployment
const API_BASE = '<?php echo $API_BASED; ?>';

// CSRF token seeded server-side — ready for the first POST immediately
let csrfToken = '<?php echo $initialCsrfToken; ?>';

// Calendar state — track which month is currently displayed
let calYear  = new Date().getFullYear();
let calMonth = new Date().getMonth() + 1;   // 1-based to match PHP date('n')

// In-memory event map for the currently displayed month.
// Shape: { "YYYY-M-D": [ { event_id, text, color, bell_impact, … }, … ] }
let calEvents = {};

// Tracks which date was clicked — used by "Add event on this day" shortcut
let selectedDateStr = ''; // "YYYY-MM-DD" format for the <input type="date">

/* ----------------------------------------------------------------
   LIVE CLOCK
---------------------------------------------------------------- */
function tickClock() {
  document.getElementById('liveClock').textContent =
    new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
tickClock();
setInterval(tickClock, 1000);

/* ----------------------------------------------------------------
   SIDEBAR
---------------------------------------------------------------- */
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }
function closeSidebar()   { document.getElementById('sidebar').classList.remove('open'); }

/* ----------------------------------------------------------------
   MODAL
---------------------------------------------------------------- */
function openModal(prefillDate = '') {
  // Reset all fields
  document.getElementById('fTitle').value = '';
  document.getElementById('fDesc').value  = '';
  document.getElementById('fBellImpact').value = 'none';
  document.querySelector('input[name="fColor"][value="blue"]').checked = true;

  // Pre-fill the date if coming from a cell click or popover shortcut
  document.getElementById('fDate').value = prefillDate || '';

  ['errTitle','errDate'].forEach(id => document.getElementById(id).classList.remove('visible'));
  document.getElementById('eventModal').classList.add('open');
  document.getElementById('fTitle').focus();
}

// Called by the "Add event on this day" button inside the popover
function openModalForDate() {
  closePopover();
  openModal(selectedDateStr);
}

function closeModal() {
  document.getElementById('eventModal').classList.remove('open');
}
document.getElementById('eventModal').addEventListener('click', e => {
  if (e.target === document.getElementById('eventModal')) closeModal();
});

/* ----------------------------------------------------------------
   LOAD CALENDAR DATA for the current month from the API
---------------------------------------------------------------- */
async function loadCalendar() {
  // Show loading state in the grid
  document.getElementById('calBody').innerHTML =
    '<div class="cal-loading" style="grid-column:1/-1;"><span class="cal-spinner"></span> Loading…</div>';

  const params = new URLSearchParams({ action: 'list', year: calYear, month: calMonth });

  try {
    const res = await fetch(`${API_BASE}events/events.php?${params}`, {
      credentials: 'include',
      headers:     { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const body = await res.json();

    if (body.csrf_token) csrfToken = body.csrf_token;

    if (!body.success) {
      showToast(body.message || 'Failed to load events.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
      document.getElementById('calBody').innerHTML = '';
      return;
    }

    // Replace the in-memory map with this month's data
    calEvents = body.events;
    renderCalendar();

  } catch (err) {
    console.error('loadCalendar error:', err);
    showToast('Network error loading calendar.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    document.getElementById('calBody').innerHTML = '';
  }
}

/* ----------------------------------------------------------------
   RENDER CALENDAR GRID from calEvents map
---------------------------------------------------------------- */
function renderCalendar() {
  const bodyEl   = document.getElementById('calBody');
  const titleEl  = document.getElementById('calMonthTitle');

  const date     = new Date(calYear, calMonth - 1, 1);
  const today    = new Date();

  // Update the month/year label in the nav bar
  titleEl.textContent = date.toLocaleString('default', { month: 'long', year: 'numeric' });

  const firstDayOfWeek = date.getDay();            // 0=Sun … 6=Sat
  const daysInMonth    = new Date(calYear, calMonth, 0).getDate();
  const daysInPrev     = new Date(calYear, calMonth - 1, 0).getDate();

  let cells = '';

  // ---- Trailing cells from the previous month ----------------
  for (let i = firstDayOfWeek - 1; i >= 0; i--) {
    cells += `<div class="cal-cell other-month" aria-hidden="true">
      <div class="cal-date">${daysInPrev - i}</div></div>`;
  }

  // ---- Current month cells -----------------------------------
  for (let d = 1; d <= daysInMonth; d++) {
    const isToday  = d === today.getDate() && calMonth - 1 === today.getMonth() && calYear === today.getFullYear();

    // Build the lookup key in the same non-padded format the API returns
    const key      = calYear + '-' + calMonth + '-' + d;
    const events   = calEvents[key] || [];

    // Render up to 3 event pills; extra events show a "+N more" indicator
    const maxVisible = 3;
    const pillsHtml  = events.slice(0, maxVisible)
      .map(e => `<span class="cal-event-pill ${escapeHtml(e.color)}">${escapeHtml(e.text)}</span>`)
      .join('');
    const morePill   = events.length > maxVisible
      ? `<span class="cal-event-pill blue">+${events.length - maxVisible} more</span>`
      : '';

    // Zero-pad the date for the ISO date string used by <input type="date">
    const isoDate  = `${calYear}-${String(calMonth).padStart(2,'0')}-${String(d).padStart(2,'0')}`;

    cells += `
      <div class="cal-cell${isToday ? ' today' : ''}"
           role="gridcell"
           aria-label="${d} ${titleEl.textContent}${events.length ? ', ' + events.length + ' event(s)' : ''}"
           tabindex="0"
           data-date="${isoDate}"
           onclick="handleCellClick(event, '${isoDate}', ${d})">
        <div class="cal-date">${d}</div>
        ${pillsHtml}${morePill}
      </div>`;
  }

  // ---- Leading cells for the next month ----------------------
  const totalCells  = 42;
  const filledCells = firstDayOfWeek + daysInMonth;
  for (let d = 1; d <= totalCells - filledCells; d++) {
    cells += `<div class="cal-cell other-month" aria-hidden="true">
      <div class="cal-date">${d}</div></div>`;
  }

  bodyEl.innerHTML = cells;
}

/* ----------------------------------------------------------------
   CELL CLICK — show popover if there are events, else open add modal
---------------------------------------------------------------- */
function handleCellClick(event, isoDate, dayNum) {
  event.stopPropagation();  // prevent the document click listener from closing the popover immediately

  selectedDateStr = isoDate;

  // Highlight the selected cell
  document.querySelectorAll('.cal-cell.selected').forEach(c => c.classList.remove('selected'));
  event.currentTarget.classList.add('selected');

  const key    = calYear + '-' + calMonth + '-' + dayNum;
  const events = calEvents[key] || [];

  if (events.length === 0) {
    // No events on this day — go straight to the "Add Event" modal
    closePopover();
    openModal(isoDate);
    return;
  }

  showPopover(event.currentTarget, isoDate, events);
}

/* ----------------------------------------------------------------
   DAY DETAIL POPOVER
---------------------------------------------------------------- */
function showPopover(cellEl, isoDate, events) {
  const popover  = document.getElementById('dayPopover');
  const title    = document.getElementById('popoverTitle');
  const list     = document.getElementById('popoverList');

  // Format the date for the popover header: "June 20, 2026"
  title.textContent = new Date(isoDate + 'T00:00:00').toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });

  // Build the event list inside the popover
  list.innerHTML = events.map(e => `
    <div class="day-popover-item" data-event-id="${e.event_id}">
      <span class="day-popover-dot ${escapeHtml(e.color)}"></span>
      <div class="day-popover-body">
        <div class="day-popover-event-title">${escapeHtml(e.text)}</div>
        ${e.description ? `<div class="day-popover-desc">${escapeHtml(e.description)}</div>` : ''}
        ${e.bell_impact !== 'none'
          ? `<span class="day-popover-impact ${escapeHtml(e.bell_impact)}">${escapeHtml(e.bell_impact)}</span>`
          : ''}
      </div>
      <button class="day-popover-delete"
              onclick="deleteEvent(${e.event_id}, '${escapeHtml(e.text).replace(/'/g,"\\'")}', '${isoDate}')"
              aria-label="Delete ${escapeHtml(e.text)}" title="Delete event">
        <i class="fa-solid fa-trash"></i>
      </button>
    </div>`).join('');

  // Position the popover near the clicked cell without going off-screen
  const rect    = cellEl.getBoundingClientRect();
  const pWidth  = 300;   // approximate popover width (matches CSS max-width)
  const pHeight = 200;   // approximate popover height for collision check

  let left = rect.right + 8;
  let top  = rect.top;

  // Flip horizontally if it would overflow the right edge
  if (left + pWidth > window.innerWidth - 16) {
    left = rect.left - pWidth - 8;
  }
  // Clamp vertically so it doesn't go below the viewport
  if (top + pHeight > window.innerHeight - 16) {
    top = window.innerHeight - pHeight - 16;
  }

  popover.style.left = left + 'px';
  popover.style.top  = top  + 'px';
  popover.classList.add('open');
}

function closePopover() {
  document.getElementById('dayPopover').classList.remove('open');
  document.querySelectorAll('.cal-cell.selected').forEach(c => c.classList.remove('selected'));
}

// Close popover when clicking anywhere outside it
document.addEventListener('click', e => {
  const popover = document.getElementById('dayPopover');
  if (popover.classList.contains('open') && !popover.contains(e.target)) {
    closePopover();
  }
});

/* ----------------------------------------------------------------
   CHANGE MONTH — fetch fresh data from the API
---------------------------------------------------------------- */
function changeMonth(dir) {
  closePopover();
  calMonth += dir;
  if (calMonth > 12) { calMonth = 1;  calYear++; }
  if (calMonth < 1)  { calMonth = 12; calYear--; }
  loadCalendar();
  loadUpcoming();  // refresh the upcoming list for context
}

/* ----------------------------------------------------------------
   LOAD UPCOMING EVENTS LIST
---------------------------------------------------------------- */
async function loadUpcoming() {
  const params = new URLSearchParams({ action: 'upcoming', limit: 5 });

  try {
    const res = await fetch(`${API_BASE}events/events.php?${params}`, {
      credentials: 'include',
      headers:     { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const body = await res.json();

    if (body.csrf_token) csrfToken = body.csrf_token;
    if (!body.success)   return;

    renderUpcoming(body.upcoming);

  } catch (err) {
    console.error('loadUpcoming error:', err);
  }
}

/* Render the "Upcoming Events" list below the calendar */
function renderUpcoming(items) {
  const list = document.getElementById('upcomingList');

  // Icon class mapping: color token → activity-icon modifier
  const iconClass = { blue:'blue', green:'success', amber:'warning', purple:'purple' };

  if (items.length === 0) {
    list.innerHTML = `<p style="font-size:0.875rem;color:var(--text-400);padding:0.5rem 0.6rem;">No upcoming events.</p>`;
    return;
  }

  list.innerHTML = items.map(ev => `
    <div class="activity-item">
      <span class="activity-icon ${iconClass[ev.color] ?? 'info'}">
        <i class="fa-solid fa-calendar-day"></i>
      </span>
      <span class="activity-text">
        <strong>${escapeHtml(ev.date_formatted)}</strong> — ${escapeHtml(ev.event_title)}
        ${ev.description ? `<span style="color:var(--text-400);font-size:0.8rem;"> · ${escapeHtml(ev.description.substring(0, 60))}${ev.description.length > 60 ? '…' : ''}</span>` : ''}
      </span>
      <button class="activity-del-btn"
              onclick="deleteEvent(${ev.event_id}, '${escapeHtml(ev.event_title).replace(/'/g,"\\'")}', null)"
              aria-label="Delete ${escapeHtml(ev.event_title)}" title="Delete event">
        <i class="fa-solid fa-trash"></i>
      </button>
    </div>`).join('');
}

/* ----------------------------------------------------------------
   SUBMIT — create a new event via POST
---------------------------------------------------------------- */
async function submitEvent() {
  const title      = document.getElementById('fTitle').value.trim();
  const dateVal    = document.getElementById('fDate').value;
  const desc       = document.getElementById('fDesc').value.trim();
  const bellImpact = document.getElementById('fBellImpact').value;
  const color      = document.querySelector('input[name="fColor"]:checked')?.value ?? 'blue';
  const btn        = document.getElementById('submitEventBtn');

  // ---- Client-side validation (mirrors server rules) -----------
  let valid = true;
  if (!title) {
    document.getElementById('errTitle').classList.add('visible');
    document.getElementById('fTitle').focus();
    valid = false;
  } else {
    document.getElementById('errTitle').classList.remove('visible');
  }
  if (!dateVal) {
    document.getElementById('errDate').classList.add('visible');
    if (valid) document.getElementById('fDate').focus();
    valid = false;
  } else {
    document.getElementById('errDate').classList.remove('visible');
  }
  if (!valid) return;

  // ---- Send POST request ---------------------------------------
  const defHtml = btn.innerHTML;
  btn.disabled  = true;
  btn.innerHTML = '<div class="spinner-sm"></div> Saving…';

  try {
    const res = await fetch(`${API_BASE}events/events.php`, {
      method:      'POST',
      credentials: 'include',
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token':     csrfToken,  // also sent in body below
      },
      body: JSON.stringify({
        action:      'create',
        csrf_token:  csrfToken,
        event_title: title,
        description: desc,
        event_date:  dateVal,
        color,
        bell_impact: bellImpact,
      }),
    });

    const data = await res.json();
    if (data.csrf_token) csrfToken = data.csrf_token;

    if (data.success) {
      closeModal();
      showToast('Event added!', 'success', '<i class="fa-solid fa-check"></i>');

      // Optimistic update: add the new event to the in-memory map
      // so it appears immediately on the grid without a full reload.
      // The API returns the map_key in "YYYY-M-D" format.
      const key = data.event.map_key;
      if (!calEvents[key]) calEvents[key] = [];
      calEvents[key].push({
        event_id:    data.event.event_id,
        text:        data.event.text,
        description: data.event.description,
        color:       data.event.color,
        bell_impact: data.event.bell_impact,
        date:        data.event.date,
      });

      // Re-render the grid and upcoming list with the updated data
      renderCalendar();
      loadUpcoming();

    } else {
      showToast(data.message || 'Failed to add event.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    }

  } catch (err) {
    console.error('submitEvent error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
  } finally {
    btn.disabled  = false;
    btn.innerHTML = defHtml;
  }
}

/* ----------------------------------------------------------------
   DELETE — soft-delete an event, update UI optimistically
   isoDate is passed so we know which map key to update;
   null when called from the upcoming list (we do a full reload).
---------------------------------------------------------------- */
async function deleteEvent(eventId, name, isoDate) {
  if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;

  try {
    const res = await fetch(`${API_BASE}events/events.php`, {
      method:      'POST',
      credentials: 'include',
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token':     csrfToken,
      },
      body: JSON.stringify({
        action:     'delete',
        csrf_token: csrfToken,
        event_id:   eventId,
      }),
    });

    const data = await res.json();
    if (data.csrf_token) csrfToken = data.csrf_token;

    if (data.success) {
      showToast(data.message, 'danger', '<i class="fa-solid fa-trash"></i>');

      if (isoDate) {
        // Remove from the in-memory map by matching event_id
        const [y, m, d] = isoDate.split('-');
        const key = parseInt(y) + '-' + parseInt(m) + '-' + parseInt(d);
        if (calEvents[key]) {
          calEvents[key] = calEvents[key].filter(e => e.event_id !== eventId);
          if (calEvents[key].length === 0) delete calEvents[key];
        }
        closePopover();
        renderCalendar();
      }

      loadUpcoming(); // always refresh the upcoming list after a delete

    } else {
      showToast(data.message || 'Failed to delete event.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    }

  } catch (err) {
    console.error('deleteEvent error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
  }
}

/* ----------------------------------------------------------------
   LOGOUT
---------------------------------------------------------------- */


/* ----------------------------------------------------------------
   XSS PROTECTION — all server/user data must pass through this
   before being inserted into innerHTML
---------------------------------------------------------------- */
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}


/* ----------------------------------------------------------------
   INIT — load calendar and upcoming list on page ready
---------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  loadCalendar();
  loadUpcoming();
});
</script>
</body>
</html>
