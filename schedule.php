<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/require_login.php';
include_once __DIR__ . '/includes/api_base.php';
require_once __DIR__ . '/includes/csrf.php';
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
<title>CampusBell — Schedule Management</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

<link rel="stylesheet" href="assets/css/main.css" />
<style>










/* ============================================================
   TOOLBAR / SEARCH / BUTTONS
============================================================ */
.toolbar{display:flex;gap:0.75rem;align-items:center;margin-bottom:1.4rem;flex-wrap:wrap;}
.search-input-wrap{position:relative;flex:1;min-width:200px;}
.search-input-wrap input{width:100%;padding:0.62rem 1rem 0.62rem 2.5rem;border:1.5px solid var(--line-2);border-radius:var(--r-sm);font-size:0.875rem;outline:none;transition:border-color var(--t);background:var(--paper-1);}
.search-input-wrap input:focus{border-color:var(--azure-500);box-shadow:0 0 0 3px var(--azure-100);}
.search-icon{position:absolute;left:0.9rem;top:50%;transform:translateY(-50%);color:var(--text-400);font-size:14px;pointer-events:none;}
.btn-secondary{padding:0.62rem 1.1rem;border-radius:var(--r-sm);font-size:0.875rem;font-weight:600;border:1.5px solid var(--line-2);background:var(--paper-1);color:var(--text-600);display:flex;align-items:center;gap:0.5rem;transition:background var(--t),border-color var(--t);}
.btn-secondary:hover{background:var(--paper);}
.btn-secondary.active-filter{background:var(--azure-100);border-color:var(--azure-500);color:var(--azure-600);}
.btn-blue{padding:0.62rem 1.2rem;border-radius:var(--r-sm);font-size:0.875rem;font-weight:600;background:var(--ink-900);color:white;display:flex;align-items:center;gap:0.5rem;transition:background var(--t),transform var(--t);}
.btn-blue:hover{background:var(--signal-600);transform:translateY(-1px);}
.btn-blue:disabled{opacity:0.65;cursor:not-allowed;transform:none;}

/* Day-filter pills shown in a dropdown-style row */
.day-filter-row{display:none;gap:0.4rem;flex-wrap:wrap;margin-bottom:1rem;}
.day-filter-row.open{display:flex;}
.day-pill{padding:0.35rem 0.75rem;border-radius:var(--r-pill);font-size:0.76rem;font-weight:600;border:1.5px solid var(--line-2);background:var(--paper-1);color:var(--text-600);cursor:pointer;transition:background var(--t),border-color var(--t),color var(--t);}
.day-pill:hover{background:var(--paper);}
.day-pill.active{background:var(--ink-900);border-color:var(--ink-900);color:white;}

/* ============================================================
   SCHEDULE TABLE
============================================================ */
.schedule-table-wrap{overflow-x:auto;background:var(--paper-1);border-radius:var(--r-md);border:1px solid var(--line);box-shadow:var(--shadow-1);}
.schedule-table{width:100%;border-collapse:collapse;min-width:760px;}
.schedule-table th{text-align:left;font-size:0.7rem;font-weight:700;color:var(--text-400);text-transform:uppercase;letter-spacing:0.05em;padding:0.85rem 1rem;border-bottom:1px solid var(--line);background:var(--paper);}
.schedule-table td{padding:0.8rem 1rem;font-size:0.86rem;border-bottom:1px solid var(--line);color:var(--text-900);}
.schedule-table tbody tr:last-child td{border-bottom:none;}
.schedule-table tbody tr{transition:background var(--t);}
.schedule-table tbody tr:hover{background:var(--paper);}
.schedule-table td:first-child{font-family:var(--font-mono);}

/* Empty / skeleton states inside the table */
.skel-row td{padding:0.8rem 1rem;}
.skel-line{height:12px;border-radius:var(--r-xs);background:var(--line);animation:shimmer 1.4s ease-in-out infinite;display:inline-block;}
@keyframes shimmer{0%,100%{opacity:1;}50%{opacity:0.4;}}

.bell-type-pill{font-size:0.68rem;font-weight:600;padding:3px 10px;border-radius:var(--r-pill);text-transform:capitalize;}
.bell-type-pill.class {background:var(--azure-100);color:var(--azure-600);}
.bell-type-pill.break {background:var(--ok-100);color:var(--ok-500);}
.bell-type-pill.prayer{background:#EDE3FB;color:#6A36B5;}
.bell-type-pill.alert {background:var(--bad-100);color:var(--bad-500);}

.toggle-switch{position:relative;display:inline-block;width:38px;height:22px;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;cursor:pointer;inset:0;background:var(--line-2);border-radius:var(--r-pill);transition:background var(--t);}
.toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;bottom:3px;background:white;border-radius:50%;transition:transform var(--t);box-shadow:var(--shadow-1);}
.toggle-switch input:checked+.toggle-slider{background:var(--ok-500);}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(16px);}

.icon-btn{width:30px;height:30px;border-radius:var(--r-xs);display:inline-flex;align-items:center;justify-content:center;font-size:13px;transition:background var(--t),color var(--t);}
.icon-btn.edit{color:var(--azure-500);}
.icon-btn.edit:hover{background:var(--azure-100);}
.icon-btn.del{color:var(--bad-500);}
.icon-btn.del:hover{background:var(--bad-100);}


/* Day checkboxes */
.day-checkboxes{display:flex;gap:0.45rem;flex-wrap:wrap;}
.day-cb-label{cursor:pointer;}
.day-cb-label input{display:none;}
.day-cb-label span{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:var(--r-xs);border:1.5px solid var(--line-2);font-size:0.75rem;font-weight:700;color:var(--text-600);transition:background var(--t),border-color var(--t),color var(--t);}
.day-cb-label input:checked+span{background:var(--ink-900);border-color:var(--ink-900);color:white;}

/* Zone checkboxes */
.zone-checkboxes{display:flex;gap:0.4rem;flex-wrap:wrap;}
.zone-cb-label{cursor:pointer;}
.zone-cb-label input{display:none;}
.zone-cb-label span{display:inline-flex;align-items:center;padding:0.35rem 0.75rem;border-radius:var(--r-pill);border:1.5px solid var(--line-2);font-size:0.76rem;font-weight:600;color:var(--text-600);transition:background var(--t),border-color var(--t),color var(--t);}
.zone-cb-label input:checked+span{background:var(--azure-100);border-color:var(--azure-500);color:var(--azure-600);}


.btn-ghost{padding:0.6rem 1.1rem;border-radius:var(--r-sm);border:1.5px solid var(--line-2);font-size:0.875rem;font-weight:600;color:var(--text-600);transition:background var(--t);}
.btn-ghost:hover{background:var(--paper);}
.btn-submit{padding:0.6rem 1.4rem;border-radius:var(--r-sm);background:var(--signal-500);color:white;font-size:0.875rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;transition:background var(--t);}
.btn-submit:hover{background:var(--signal-600);}
.btn-submit:disabled{opacity:0.65;cursor:not-allowed;}
.spinner-sm{width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,0.35);border-top-color:white;animation:spin 0.7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}


</style>
</head>
<body>
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<div class="modal-backdrop" id="schedModal" role="dialog" aria-modal="true" aria-labelledby="schedModalTitle">
  <div class="modal-box">
    <div class="modal-header">
      <h2 class="modal-title" id="schedModalTitle">Add Bell</h2>
      <button class="modal-close" onclick="closeModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="fBellName">Bell Name <span style="color:var(--bad-500)">*</span></label>
        <input class="form-input" type="text" id="fBellName" maxlength="120" placeholder="e.g. Period 1 Start" autocomplete="off" />
        <div class="form-error" id="errBellName">Bell name is required.</div>
      </div>
      <div class="form-group">
        <label class="form-label" for="fRingTime">Ring Time (24-hr) <span style="color:var(--bad-500)">*</span></label>
        <input class="form-input" type="time" id="fRingTime" />
        <div class="form-error" id="errRingTime">Please set a ring time.</div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="fBellType">Bell Type</label>
        <select class="form-select" id="fBellType">
          <option value="class">Class</option>
          <option value="break">Break</option>
          <option value="prayer">Prayer</option>
          <option value="alert">Alert</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" for="fDuration">Duration (seconds)</label>
        <input class="form-input" type="number" id="fDuration" min="1" max="60" value="3" />
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Days Active</label>
      <div class="day-checkboxes">
        <label class="day-cb-label"><input type="checkbox" name="fDay" value="1"  checked /><span>Mon</span></label>
        <label class="day-cb-label"><input type="checkbox" name="fDay" value="2"  checked /><span>Tue</span></label>
        <label class="day-cb-label"><input type="checkbox" name="fDay" value="4"  checked /><span>Wed</span></label>
        <label class="day-cb-label"><input type="checkbox" name="fDay" value="8"  checked /><span>Thu</span></label>
        <label class="day-cb-label"><input type="checkbox" name="fDay" value="16" checked /><span>Fri</span></label>
        <label class="day-cb-label"><input type="checkbox" name="fDay" value="32"         /><span>Sat</span></label>
        <label class="day-cb-label"><input type="checkbox" name="fDay" value="64"         /><span>Sun</span></label>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Zones</label>
      <div class="zone-checkboxes">
        <label class="zone-cb-label"><input type="checkbox" name="fZone" value="All" checked /><span>All Zones</span></label>
        <label class="zone-cb-label"><input type="checkbox" name="fZone" value="A" /><span>Library</span></label>
        <label class="zone-cb-label"><input type="checkbox" name="fZone" value="B" /><span>BSIT Building</span></label>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn-submit" id="submitSchedBtn" onclick="submitSchedule()">
        <i class="fa-solid fa-bell"></i> Add Bell
      </button>
    </div>
  </div>
</div>


<div class="overlay-backdrop" id="deleteOverlay" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
  <div class="overlay-box">
    <h3 id="deleteTitle">Delete Bell?</h3>
    <p>This will permanently delete <span id="deleteName" style="font-weight:600;color:var(--text-900)">this bell</span>. This action cannot be undone.</p>
    <div class="overlay-actions">
      <button class="btn-ghost" onclick="closeDeleteOverlay()">Cancel</button>
      <button class="btn-danger" id="confirmDeleteBtn" onclick="performDelete()">
        <i class="fa-solid fa-trash"></i> Delete
      </button>
    </div>
  </div>
</div>

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

    <a href='schedule.php' class="nav-item active" >
      <span class="nav-icon"><i class="fa-solid fa-clock"></i></span> Schedule Management
    </a>
    <a href='event.php' class="nav-item" >
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

<div class="main-content" id="mainContent">
  <header class="topbar">
    <button class="topbar-menu-btn" onclick="toggleSidebar()" aria-label="Open navigation" id="menuBtn">
      <i class="fa-solid fa-bars"></i>
    </button>
    <div class="topbar-title-wrap">
      <span class="topbar-title">Schedule Management</span>
      <span class="topbar-crumb">CampusBell / Schedule</span>
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

  <main class="page-content" id="content-schedule" role="main">
    <h1 class="page-heading">Schedule Management</h1>
    <p class="page-subheading">Define and manage all automated bell ring times.</p>

    <div class="toolbar">
      <div class="search-input-wrap">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="search" id="searchInput" placeholder="Search schedule…" aria-label="Search schedule" />
      </div>
      <button class="btn-secondary" id="dayFilterBtn" onclick="toggleDayFilter()">
        <i class="fa-solid fa-calendar-week"></i> Weekday Filter
      </button>
      <button class="btn-blue" onclick="openModal()"><i class="fa-solid fa-plus"></i> Add Bell</button>
    </div>

    <div class="day-filter-row" id="dayFilterRow">
      <button class="day-pill active" data-day="0" onclick="setDayFilter(this, 0)">All Days</button>
      <button class="day-pill" data-day="1" onclick="setDayFilter(this, 1)">Mon</button>
      <button class="day-pill" data-day="2" onclick="setDayFilter(this, 2)">Tue</button>
      <button class="day-pill" data-day="3" onclick="setDayFilter(this, 3)">Wed</button>
      <button class="day-pill" data-day="4" onclick="setDayFilter(this, 4)">Thu</button>
      <button class="day-pill" data-day="5" onclick="setDayFilter(this, 5)">Fri</button>
      <button class="day-pill" data-day="6" onclick="setDayFilter(this, 6)">Sat</button>
      <button class="day-pill" data-day="7" onclick="setDayFilter(this, 7)">Sun</button>
    </div>

    <div class="schedule-table-wrap" role="region" aria-label="Bell schedule table">
      <table class="schedule-table">
        <thead>
          <tr>
            <th scope="col">Time</th>
            <th scope="col">Bell Name</th>
            <th scope="col">Type</th>
            <th scope="col">Duration</th>
            <th scope="col">Days</th>
            <th scope="col">Zones</th>
            <th scope="col">Active</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody id="scheduleBody"></tbody>
      </table>
    </div>
  </main>
</div>





<script src='assets/js/main.js' defer ></script>








<script>
const API_BASE = '<?php echo $API_BASED; ?>';
let csrfToken = '<?php echo $initialCsrfToken; ?>';

let currentSearch  = '';
let currentDayFilter = 0;   

let pendingDeleteId   = null;
let pendingDeleteName = '';

function tickClock() {
  document.getElementById('liveClock').textContent =
    new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
tickClock();
setInterval(tickClock, 1000);

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('open'); }
function closeSidebar()   { document.getElementById('sidebar').classList.remove('open'); }

function openModal() {
  document.getElementById('fBellName').value = '';
  document.getElementById('fRingTime').value = '';
  document.getElementById('fBellType').value = 'class';
  document.getElementById('fDuration').value = '3';

  document.querySelectorAll('input[name="fDay"]').forEach(cb => {
    cb.checked = ['1','2','4','8','16'].includes(cb.value);
  });
  document.querySelectorAll('input[name="fZone"]').forEach(cb => {
    cb.checked = cb.value === 'All';
  });

  ['errBellName','errRingTime'].forEach(id => {
    document.getElementById(id).classList.remove('visible');
  });

  document.getElementById('schedModal').classList.add('open');
  document.getElementById('fBellName').focus();
}
function closeModal() {
  document.getElementById('schedModal').classList.remove('open');
}

document.getElementById('schedModal').addEventListener('click', e => {
  if (e.target === document.getElementById('schedModal')) closeModal();
});


function toggleDayFilter() {
  const row = document.getElementById('dayFilterRow');
  const btn = document.getElementById('dayFilterBtn');
  const open = row.classList.toggle('open');
  btn.classList.toggle('active-filter', open);
}
function setDayFilter(btn, day) {
  document.querySelectorAll('.day-pill').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  currentDayFilter = day;
  loadSchedule();
}

let searchTimer = null;
document.getElementById('searchInput').addEventListener('input', function() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    currentSearch = this.value.trim();
    loadSchedule();
  }, 400);
});

async function loadSchedule() {
  showSkeletons();
  const params = new URLSearchParams({ action: 'list' });
  if (currentSearch)     params.set('q',   currentSearch);
  if (currentDayFilter)  params.set('day', currentDayFilter);

  try {
    const res = await fetch(`${API_BASE}schedule/schedule.php?${params}`, {
      method:      'GET',
      credentials: 'include',  
      headers:     { 'X-Requested-With': 'XMLHttpRequest' },
    });

    const body = await res.json();

    if (body.csrf_token) csrfToken = body.csrf_token;

    if (!body.success) {
      showToast(body.message || 'Failed to load schedule.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
      clearSkeletons();
      return;
    }

    renderSchedule(body.data);

  } catch (err) {
    console.error('loadSchedule error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    clearSkeletons();
  }
}

function renderSchedule(rows) {
  const tbody = document.getElementById('scheduleBody');
  tbody.innerHTML = '';

  if (rows.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="8" style="text-align:center;padding:2.5rem;color:var(--text-400);font-size:0.875rem;">
          <i class="fa-solid fa-clock" style="margin-right:0.5rem;opacity:0.4;"></i>
          No bell schedules found${currentSearch ? ' matching your search' : ''}.
        </td>
      </tr>`;
    return;
  }

  rows.forEach(r => {
    const tr = document.createElement('tr');

    const name      = escapeHtml(r.bell_name);
    const timeFmt   = escapeHtml(r.ring_time_fmt);
    const bellType  = escapeHtml(r.bell_type);
    const daysLabel = escapeHtml(r.days_label);
    const zones     = escapeHtml(r.zones || 'All');
    const id        = parseInt(r.sched_id);
    const durSec    = parseInt(r.duration_s);
    const isActive  = r.is_active;

    tr.setAttribute('data-id', id);
    tr.innerHTML = `
      <td>${timeFmt}</td>
      <td>${name}</td>
      <td><span class="bell-type-pill ${bellType}">${bellType.charAt(0).toUpperCase() + bellType.slice(1)}</span></td>
      <td>${durSec}s</td>
      <td>${daysLabel}</td>
      <td>${zones}</td>
      <td>
        <!-- Toggle switch: onchange fires toggleSchedule() with the row's DB id -->
        <label class="toggle-switch" aria-label="Toggle ${name}">
          <input type="checkbox" ${isActive ? 'checked' : ''}
            onchange="toggleSchedule(${id}, this.checked, this)" />
          <span class="toggle-slider"></span>
        </label>
      </td>
      <td>
        <button class="icon-btn del" onclick="openDeleteOverlay(${id}, '${name.replace(/'/g, "\\'")}')"
          aria-label="Delete ${name}" title="Delete">
          <i class="fa-solid fa-trash"></i>
        </button>
      </td>`;

    tbody.appendChild(tr);
  });
}

function showSkeletons() {
  const tbody = document.getElementById('scheduleBody');
  tbody.innerHTML = Array(5).fill(`
    <tr class="skel-row">
      <td><span class="skel-line" style="width:60px"></span></td>
      <td><span class="skel-line" style="width:120px"></span></td>
      <td><span class="skel-line" style="width:55px"></span></td>
      <td><span class="skel-line" style="width:25px"></span></td>
      <td><span class="skel-line" style="width:75px"></span></td>
      <td><span class="skel-line" style="width:40px"></span></td>
      <td><span class="skel-line" style="width:38px;height:22px;border-radius:999px"></span></td>
      <td><span class="skel-line" style="width:30px"></span></td>
    </tr>`).join('');
}
function clearSkeletons() {
  document.getElementById('scheduleBody').innerHTML = '';
}


async function submitSchedule() {
  const bellName = document.getElementById('fBellName').value.trim();
  const ringTime = document.getElementById('fRingTime').value;   
  const bellType = document.getElementById('fBellType').value;
  const duration = parseInt(document.getElementById('fDuration').value) || 3;
  const btn      = document.getElementById('submitSchedBtn');

  let valid = true;
  if (!bellName) {
    document.getElementById('errBellName').classList.add('visible');
    document.getElementById('fBellName').focus();
    valid = false;
  } else {
    document.getElementById('errBellName').classList.remove('visible');
  }
  if (!ringTime) {
    document.getElementById('errRingTime').classList.add('visible');
    if (valid) document.getElementById('fRingTime').focus();
    valid = false;
  } else {
    document.getElementById('errRingTime').classList.remove('visible');
  }
  if (!valid) return;
  let daysMask = 0;
  document.querySelectorAll('input[name="fDay"]:checked').forEach(cb => {
    daysMask |= parseInt(cb.value);
  });
  if (daysMask === 0) daysMask = 31;

  const zones = [];
  document.querySelectorAll('input[name="fZone"]:checked').forEach(cb => {
    zones.push(cb.value);
  });
  if (zones.length === 0) zones.push('All');

  const defHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner-sm"></div> Saving…';

  try {
    const res = await fetch(`${API_BASE}schedule/schedule.php`, {
      method:      'POST',
      credentials: 'include',
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token':     csrfToken,   
      },
      body: JSON.stringify({
        action:     'create',
        csrf_token: csrfToken,  
        bell_name:  bellName,
        ring_time:  ringTime,
        duration_s: duration,
        bell_type:  bellType,
        days_mask:  daysMask,
        zones,
      }),
    });

    const data = await res.json();

    if (data.csrf_token) csrfToken = data.csrf_token;

    if (data.success) {
      closeModal();
      showToast('Bell schedule created!', 'success', '<i class="fa-solid fa-check"></i>');
      loadSchedule();
    } else {
      showToast(data.message || 'Failed to create schedule.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    }

  } catch (err) {
    console.error('submitSchedule error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
  } finally {
    btn.disabled = false;
    btn.innerHTML = defHtml;
  }
}

async function toggleSchedule(schedId, isActive, checkboxEl) {
  try {
    const res = await fetch(`${API_BASE}schedule/schedule.php`, {
      method:      'POST',
      credentials: 'include',
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token':     csrfToken,
      },
      body: JSON.stringify({
        action:    'toggle',
        csrf_token: csrfToken,
        sched_id:   schedId,
        is_active:  isActive,
      }),
    });

    const data = await res.json();
    if (data.csrf_token) csrfToken = data.csrf_token;

    if (data.success) {
      showToast(data.message, isActive ? 'success' : 'info',
        isActive ? '<i class="fa-solid fa-bell"></i>' : '<i class="fa-solid fa-bell-slash"></i>');
    } else {
      checkboxEl.checked = !isActive;
      showToast(data.message || 'Toggle failed.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    }

  } catch (err) {
    console.error('toggleSchedule error:', err);
    checkboxEl.checked = !isActive;
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
  }
}


function openDeleteOverlay(schedId, name) {
  pendingDeleteId   = schedId;
  pendingDeleteName = name;
  document.getElementById('deleteName').textContent = name;
  document.getElementById('deleteOverlay').classList.add('open');
}
function closeDeleteOverlay() {
  pendingDeleteId = null;
  document.getElementById('deleteOverlay').classList.remove('open');
}

async function performDelete() {
  if (!pendingDeleteId) return;

  const btn = document.getElementById('confirmDeleteBtn');
  const defHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<div class="spinner-sm"></div> Deleting…';

  try {
    const res = await fetch(`${API_BASE}schedule/schedule.php`, {
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
        sched_id:   pendingDeleteId,
      }),
    });

    const data = await res.json();
    if (data.csrf_token) csrfToken = data.csrf_token;

    closeDeleteOverlay();

    if (data.success) {
      showToast(data.message, 'danger', '<i class="fa-solid fa-trash"></i>');
      loadSchedule(); 
    } else {
      showToast(data.message || 'Delete failed.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    }

  } catch (err) {
    console.error('performDelete error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    closeDeleteOverlay();
  } finally {
    btn.disabled = false;
    btn.innerHTML = defHtml;
  }
}

document.getElementById('deleteOverlay').addEventListener('click', e => {
  if (e.target === document.getElementById('deleteOverlay')) closeDeleteOverlay();
});






function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}



document.addEventListener('DOMContentLoaded', () => {
  loadSchedule();
});
</script>
</body>
</html>
