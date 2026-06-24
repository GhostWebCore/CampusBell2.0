<?php
/**
 * announcement.php
 *
 * Protected page — require_login.php redirects unauthenticated visitors
 * to login.php before any HTML is sent. All user data comes from the
 * validated session, never from client-supplied values.
 */
declare(strict_types=1);

// Auth guard: halts with redirect or 401 JSON if no valid session
require_once __DIR__ . '/includes/require_login.php';

// Needed for the PHP-injected API base URL
include_once __DIR__ . '/includes/api_base.php';

// csrf_token() so the initial token is embedded server-side in the page
// rather than requiring a separate fetch before the first form submit.
require_once __DIR__ . '/includes/csrf.php';

// ----------------------------------------------------------------
// Session data — escape for safe HTML output
// ----------------------------------------------------------------
$fullName = htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($_SESSION['username']  ?? '',      ENT_QUOTES, 'UTF-8');

// Avatar initials: first letter of each name word, max 2 letters
$nameParts = array_filter(explode(' ', $fullName));
$initials  = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($nameParts, 0, 2))));

// Embed the initial CSRF token directly in the page so the first
// "New Announcement" submit works without a prefetch round-trip.
$initialCsrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>CampusBell — Announcements</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

<link rel="stylesheet" href="assets/css/main.css" />
<style>




/* ============================================================
   MAIN CONTENT
============================================================ */


/* ============================================================
   TOOLBAR / SEARCH / BUTTONS
============================================================ */
.toolbar { display:flex; gap:0.75rem; align-items:center; margin-bottom:1.4rem; flex-wrap:wrap; }
.search-input-wrap { position:relative; flex:1; min-width:200px; }
.search-input-wrap input { width:100%; padding:0.62rem 1rem 0.62rem 2.5rem; border:1.5px solid var(--line-2); border-radius:var(--r-sm); font-size:0.875rem; outline:none; transition:border-color var(--t); background:var(--paper-1); }
.search-input-wrap input:focus { border-color:var(--azure-500); box-shadow:0 0 0 3px var(--azure-100); }
.search-icon { position:absolute; left:0.9rem; top:50%; transform:translateY(-50%); color:var(--text-400); font-size:14px; pointer-events:none; }

/* Filter pill buttons */
.filter-pills { display:flex; gap:0.4rem; flex-wrap:wrap; }
.filter-pill {
  padding:0.38rem 0.9rem; border-radius:var(--r-pill); font-size:0.78rem; font-weight:600;
  border:1.5px solid var(--line-2); background:var(--paper-1); color:var(--text-600);
  cursor:pointer; transition:background var(--t), border-color var(--t), color var(--t);
}
.filter-pill:hover { background:var(--paper); }
.filter-pill.active         { background:var(--ink-900); border-color:var(--ink-900); color:white; }
.filter-pill.active.urgent  { background:var(--signal-500); border-color:var(--signal-500); color:var(--ink-950); }
.filter-pill.active.event   { background:#6A36B5; border-color:#6A36B5; color:white; }
.filter-pill.active.general { background:var(--azure-500); border-color:var(--azure-500); color:white; }

.btn-secondary { padding:0.62rem 1.1rem; border-radius:var(--r-sm); font-size:0.875rem; font-weight:600; border:1.5px solid var(--line-2); background:var(--paper-1); color:var(--text-600); display:flex; align-items:center; gap:0.5rem; transition:background var(--t),border-color var(--t); }
.btn-secondary:hover { background:var(--paper); }
.btn-blue { padding:0.62rem 1.2rem; border-radius:var(--r-sm); font-size:0.875rem; font-weight:600; background:var(--ink-900); color:white; display:flex; align-items:center; gap:0.5rem; transition:background var(--t),transform var(--t); }
.btn-blue:hover { background:var(--signal-600); transform:translateY(-1px); }
.btn-blue:disabled { opacity:0.65; cursor:not-allowed; transform:none; }

/* ============================================================
   ANNOUNCEMENT CARDS
============================================================ */
.announcement-cards { display:flex; flex-direction:column; gap:1rem; }

/* Empty state shown when there are no results */
.ann-empty { text-align:center; padding:3rem 1rem; color:var(--text-400); font-size:0.9rem; }
.ann-empty i { font-size:2rem; margin-bottom:0.75rem; display:block; opacity:0.4; }

/* Skeleton loader placeholder cards */
.ann-skeleton { background:var(--paper-1); border-radius:var(--r-md); border:1px solid var(--line); padding:1.3rem; display:flex; gap:1rem; }
.skel-icon { width:44px; height:44px; border-radius:var(--r-sm); background:var(--line); flex-shrink:0; animation:shimmer 1.4s ease-in-out infinite; }
.skel-body { flex:1; display:flex; flex-direction:column; gap:0.5rem; }
.skel-line { height:12px; border-radius:var(--r-xs); background:var(--line); animation:shimmer 1.4s ease-in-out infinite; }
.skel-line.short { width:40%; }
.skel-line.mid   { width:65%; }
@keyframes shimmer { 0%,100%{opacity:1;} 50%{opacity:0.45;} }

.ann-card { background:var(--paper-1); border-radius:var(--r-md); border:1px solid var(--line); padding:1.3rem; display:flex; gap:1rem; align-items:flex-start; transition:box-shadow var(--t),transform var(--t); animation:fadeIn 0.35s ease; }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px);} to{opacity:1;transform:none;} }
.ann-card:hover { box-shadow:var(--shadow-2); transform:translateY(-1px); }

.ann-type-icon { width:44px; height:44px; border-radius:var(--r-sm); display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.ann-type-icon.general { background:var(--azure-100); color:var(--azure-600); }
.ann-type-icon.urgent  { background:var(--signal-100); color:var(--signal-600); }
.ann-type-icon.event   { background:#EDE3FB; color:#6A36B5; }

.ann-body   { flex:1; min-width:0; }
.ann-header { display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap; margin-bottom:0.35rem; }
.ann-title  { font-weight:600; font-size:0.95rem; color:var(--text-900); }
.ann-badge  { font-size:0.63rem; font-weight:700; padding:2px 9px; border-radius:var(--r-pill); text-transform:uppercase; letter-spacing:0.05em; }
.ann-badge.general { background:var(--azure-100); color:var(--azure-600); }
.ann-badge.urgent  { background:var(--signal-100); color:var(--signal-600); }
.ann-badge.event   { background:#EDE3FB; color:#6A36B5; }
.ann-text   { font-size:0.875rem; color:var(--text-600); line-height:1.65; }
.ann-footer { display:flex; align-items:center; gap:0.5rem; margin-top:0.65rem; flex-wrap:wrap; }
.ann-time, .ann-author { font-size:0.74rem; color:var(--text-400); display:flex; align-items:center; gap:0.3rem; }

/* Load more button */
.load-more-wrap { text-align:center; margin-top:1.25rem; }
.btn-load-more { padding:0.6rem 1.5rem; border-radius:var(--r-sm); font-size:0.85rem; font-weight:600; border:1.5px solid var(--line-2); background:var(--paper-1); color:var(--text-600); transition:background var(--t); }
.btn-load-more:hover { background:var(--paper); }
.btn-load-more:disabled { opacity:0.5; cursor:not-allowed; }


/* Character counter shown below the textarea */
.char-count { font-size:0.72rem; color:var(--text-400); text-align:right; margin-top:0.3rem; }
.char-count.warn  { color:var(--warn-500); }
.char-count.over  { color:var(--bad-500); font-weight:600; }

/* Type selector — styled radio buttons */
.type-selector { display:flex; gap:0.6rem; flex-wrap:wrap; }
.type-option { cursor:pointer; }
.type-option input[type="radio"] { display:none; }
.type-option span {
  display:inline-flex; align-items:center; gap:0.4rem;
  padding:0.42rem 0.9rem; border-radius:var(--r-pill);
  font-size:0.8rem; font-weight:600; border:1.5px solid var(--line-2);
  background:var(--paper-1); color:var(--text-600);
  transition:background var(--t),border-color var(--t),color var(--t);
}
.type-option input:checked + span.general { background:var(--azure-100); border-color:var(--azure-500); color:var(--azure-600); }
.type-option input:checked + span.urgent  { background:var(--signal-100); border-color:var(--signal-500); color:var(--signal-600); }
.type-option input:checked + span.event   { background:#EDE3FB; border-color:#6A36B5; color:#6A36B5; }


.btn-ghost  { padding:0.6rem 1.1rem; border-radius:var(--r-sm); border:1.5px solid var(--line-2); font-size:0.875rem; font-weight:600; color:var(--text-600); transition:background var(--t); }
.btn-ghost:hover { background:var(--paper); }
.btn-submit { padding:0.6rem 1.4rem; border-radius:var(--r-sm); background:var(--signal-500); color:white; font-size:0.875rem; font-weight:600; display:flex; align-items:center; gap:0.4rem; transition:background var(--t); }
.btn-submit:hover { background:var(--signal-600); }
.btn-submit:disabled { opacity:0.65; cursor:not-allowed; }
.spinner-sm { width:14px; height:14px; border-radius:50%; border:2px solid rgba(255,255,255,0.35); border-top-color:white; animation:spin 0.7s linear infinite; }
@keyframes spin { to{transform:rotate(360deg);} }













/* Logout confirm overlay */

</style>
</head>
<body>
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<!-- Logout confirmation overlay -->
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
     NEW ANNOUNCEMENT MODAL
============================================================ -->
<div class="modal-backdrop" id="annModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal-box">
    <div class="modal-header">
      <h2 class="modal-title" id="modalTitle">New Announcement</h2>
      <button class="modal-close" onclick="closeModal()" aria-label="Close modal"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- Title field -->
    <div class="form-group">
      <label class="form-label" for="annTitle">Title <span style="color:var(--bad-500)">*</span></label>
      <input class="form-input" type="text" id="annTitle" maxlength="255" placeholder="e.g. Early Dismissal — Friday" autocomplete="off" />
      <div class="form-error" id="errTitle">Please enter a title.</div>
    </div>

    <!-- Body / message field -->
    <div class="form-group">
      <label class="form-label" for="annBody">Message <span style="color:var(--bad-500)">*</span></label>
      <textarea class="form-textarea" id="annBody" maxlength="5000" placeholder="Write the full announcement here…"></textarea>
      <!-- Live character counter (counts down from 5000) -->
      <div class="char-count" id="bodyCharCount">5000 characters remaining</div>
      <div class="form-error" id="errBody">Please enter the announcement message.</div>
    </div>

    <!-- Type — styled radio group -->
    <div class="form-group">
      <label class="form-label">Type</label>
      <div class="type-selector">
        <label class="type-option">
          <input type="radio" name="annType" value="general" checked />
          <span class="general"><i class="fa-solid fa-circle-info"></i> General</span>
        </label>
        <label class="type-option">
          <input type="radio" name="annType" value="urgent" />
          <span class="urgent"><i class="fa-solid fa-triangle-exclamation"></i> Urgent</span>
        </label>
        <label class="type-option">
          <input type="radio" name="annType" value="event" />
          <span class="event"><i class="fa-solid fa-calendar-star"></i> Event</span>
        </label>
      </div>
    </div>

    <!-- Audience field -->
    <div class="form-group">
      <label class="form-label" for="annAudience">Audience</label>
      <select class="form-select" id="annAudience">
        <option value="All">All Zones</option>
        <option value="A">Library</option>
        <option value="B">BSIT Building</option>
      </select>
    </div>

    <div class="modal-actions">
      <button class="btn-ghost" onclick="closeModal()">Cancel</button>
      <button class="btn-submit" id="submitAnnBtn" onclick="submitAnnouncement()">
        <i class="fa-solid fa-bullhorn"></i> Publish
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
    <div class="sidebar-logo-text">
      <h2>CampusBell</h2>
      <span>IoT Bell System</span>
    </div>
  </div>

  <div class="nav-section">
    <div class="nav-section-label">Main</div>
    <a href='dashboard.php' class="nav-item" aria-current="page">
      <span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span> Dashboard
    </a>
    <a href='announcement.php' class="nav-item active" >
      <span class="nav-icon"><i class="fa-solid fa-bullhorn"></i></span> Announcements
    </a>

    <div class="nav-section-label">Scheduling</div>

    <a href='schedule.php' class="nav-item" >
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

<!-- ============================================================
     MAIN CONTENT
============================================================ -->
<div class="main-content" id="mainContent">

  <header class="topbar">
    <button class="topbar-menu-btn" onclick="toggleSidebar()" aria-label="Open navigation" aria-expanded="false" id="menuBtn">
      <i class="fa-solid fa-bars"></i>
    </button>
    <div class="topbar-title-wrap">
      <span class="topbar-title" id="topbarTitle">Dashboard</span>
      <span class="topbar-crumb" id="topbarCrumb">CampusBell / Dashboard</span>
    </div>
    <div class="topbar-right">
      <div class="status-pill">
        <span class="status-dot"></span>
        <span class="status-label">System Online</span>
      </div>
      <span class="topbar-time" id="liveClock">--:--:--</span>
      <button class="topbar-icon-btn" onclick="showToast('No new notifications','info','<i class=&quot;fa-solid fa-bell&quot;></i>')" aria-label="Notifications">
        <i class="fa-solid fa-bell"></i>
        <span class="dot"></span>
      </button>
      <button class="topbar-logout-btn" onclick="openLogoutOverlay()" aria-label="Sign out">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>Sign out</span>
      </button>
    </div>
  </header>

  <main class="page-content" role="main">
    <h1 class="page-heading">Announcements</h1>
    <p class="page-subheading">Broadcast messages to all zones or selected areas.</p>

    <!-- Toolbar: search + filter pills + new button -->
    <div class="toolbar">
      <div class="search-input-wrap">
        <span class="search-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
        <!-- Debounced search — triggers loadAnnouncements() after 400 ms of inactivity -->
        <input type="search" id="searchInput" placeholder="Search announcements…" aria-label="Search announcements" />
      </div>

      <!-- Filter pills: clicking one sets the active type filter -->
      <div class="filter-pills">
        <button class="filter-pill active" data-type="" onclick="setFilter(this, '')">All</button>
        <button class="filter-pill" data-type="general" onclick="setFilter(this,'general')">General</button>
        <button class="filter-pill urgent" data-type="urgent" onclick="setFilter(this,'urgent')">Urgent</button>
        <button class="filter-pill event"  data-type="event"  onclick="setFilter(this,'event')">Event</button>
      </div>

      <button class="btn-blue" onclick="openModal()"><i class="fa-solid fa-plus"></i> New Announcement</button>
    </div>

    <!-- Cards injected here by renderAnnouncements() -->
    <div class="announcement-cards" id="annCards" aria-live="polite"></div>

    <!-- Load-more button (hidden until there are more pages) -->
    <div class="load-more-wrap" id="loadMoreWrap" style="display:none;">
      <button class="btn-load-more" id="loadMoreBtn" onclick="loadMore()">Load more</button>
    </div>
  </main>

</div><!-- /.main-content -->



<script src='assets/js/main.js' defer ></script>



<script>
/* ================================================================
   CONFIGURATION
   API_BASE is PHP-rendered to keep the base URL consistent with
   the rest of the app (protocol + host + subfolder detection).
================================================================ */
const API_BASE = '<?php echo $API_BASED; ?>';

/* csrfToken is seeded from the server on page load, then kept in
   sync by storing whatever the API returns after each request.
   This means the form always has a valid, single-use token ready. */
let csrfToken = '<?php echo $initialCsrfToken; ?>';

/* Pagination state — updated on every successful list response */
let currentPage   = 1;   // which page was last loaded
let totalItems    = 0;   // total rows matching the current filter/search
let currentFilter = '';  // active type filter ('', 'general', 'urgent', 'event')
let currentSearch = '';  // active keyword search string
let isLoading     = false; // guard against concurrent requests

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
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
}

/* ----------------------------------------------------------------
   MODAL
---------------------------------------------------------------- */
function openModal() {
  // Clear any previous values and errors before showing
  document.getElementById('annTitle').value  = '';
  document.getElementById('annBody').value   = '';
  document.getElementById('annAudience').value = 'All Zones';
  document.querySelector('input[name="annType"][value="general"]').checked = true;
  updateCharCount();
  ['errTitle','errBody'].forEach(id => document.getElementById(id).classList.remove('visible'));

  document.getElementById('annModal').classList.add('open');
  document.getElementById('annTitle').focus();
}
function closeModal() {
  document.getElementById('annModal').classList.remove('open');
}
// Close modal when clicking the backdrop (outside the box)
document.getElementById('annModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
// Close modal on Escape key
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

/* Live character counter for the body textarea */
function updateCharCount() {
  const textarea = document.getElementById('annBody');
  const counter  = document.getElementById('bodyCharCount');
  const remaining = 5000 - textarea.value.length;
  counter.textContent = remaining + ' characters remaining';
  counter.classList.toggle('warn', remaining < 200 && remaining >= 0);
  counter.classList.toggle('over', remaining < 0);
}
document.getElementById('annBody').addEventListener('input', updateCharCount);

/* ----------------------------------------------------------------
   FILTER PILLS
   Clicking a pill resets pagination and reloads the list.
---------------------------------------------------------------- */
function setFilter(btn, type) {
  // Remove active class from all pills, then add to the clicked one
  document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');

  currentFilter = type;
  currentPage   = 1;     // reset to first page on filter change
  loadAnnouncements(false); // false = replace existing cards
}

/* ----------------------------------------------------------------
   SEARCH
   Debounced so we don't fire on every keystroke.
---------------------------------------------------------------- */
let searchTimer = null;
document.getElementById('searchInput').addEventListener('input', function() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    currentSearch = this.value.trim();
    currentPage   = 1;
    loadAnnouncements(false); // replace cards with fresh results
  }, 400); // 400 ms debounce
});

/* ----------------------------------------------------------------
   LOAD ANNOUNCEMENTS
   Sends a GET request to the API with the current filter/search/page.
   append = true  → add to existing cards (load more)
   append = false → replace all cards (new filter or search)
---------------------------------------------------------------- */
async function loadAnnouncements(append = false) {
  if (isLoading) return;
  isLoading = true;

  // Show skeleton loaders so the user gets immediate feedback
  if (!append) {
    showSkeletons();
  }

  // Build the query string from the current state
  const params = new URLSearchParams({ action: 'list', page: currentPage });
  if (currentFilter) params.set('type',  currentFilter);
  if (currentSearch) params.set('q',     currentSearch);

  try {
    const res = await fetch(`${API_BASE}announcements/announcements.php?${params}`, {
      method:      'GET',
      credentials: 'include', // send session cookie so require_login passes
      headers:     { 'X-Requested-With': 'XMLHttpRequest' },
    });

    const body = await res.json();

    // Keep the CSRF token in sync — the list endpoint issues a fresh one
    if (body.csrf_token) csrfToken = body.csrf_token;

    if (!body.success) {
      showToast(body.message || 'Failed to load announcements.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
      if (!append) clearSkeletons();
      return;
    }

    totalItems = body.total;
    renderAnnouncements(body.data, append);
    updateLoadMoreVisibility(body.data.length, body.per_page);

  } catch (err) {
    console.error('loadAnnouncements error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    if (!append) clearSkeletons();
  } finally {
    isLoading = false;
  }
}

/* Show or hide the "Load more" button based on whether there are
   more pages available beyond what is already rendered. */
function updateLoadMoreVisibility(returnedCount, perPage) {
  const container  = document.getElementById('annCards');
  const shownCount = container.querySelectorAll('.ann-card').length;
  const wrap       = document.getElementById('loadMoreWrap');
  wrap.style.display = shownCount < totalItems ? 'block' : 'none';
}

/* Increment page and append the next batch */
function loadMore() {
  currentPage++;
  loadAnnouncements(true);
}

/* ----------------------------------------------------------------
   RENDER CARDS
   Builds DOM from the API response data array.
---------------------------------------------------------------- */
function renderAnnouncements(items, append) {
  const container = document.getElementById('annCards');

  if (!append) {
    container.innerHTML = ''; // clear skeletons / previous results
  }

  if (items.length === 0 && !append) {
    // Empty state: show a friendly "nothing found" message
    container.innerHTML = `
      <div class="ann-empty">
        <i class="fa-solid fa-bullhorn"></i>
        <div>${currentSearch ? 'No announcements match your search.' : 'No announcements yet.'}</div>
      </div>`;
    return;
  }

  // Map type to the correct icon class
  const typeIcons = {
    general: 'fa-circle-info',
    urgent:  'fa-triangle-exclamation',
    event:   'fa-calendar-star',
  };

  items.forEach(ann => {
    // Escape all user-supplied strings before inserting into HTML
    // to prevent XSS from stored announcement content.
    const title      = escapeHtml(ann.title);
    const bodyText   = escapeHtml(ann.body);
    const author     = escapeHtml(ann.author_name);
    const audience   = escapeHtml(ann.audience);
    const type       = escapeHtml(ann.type);
    const timeStr    = escapeHtml(ann.created_at_formatted);
    const icon       = typeIcons[ann.type] ?? 'fa-circle-info';

    const article = document.createElement('article');
    article.className = 'ann-card';
    article.setAttribute('data-id', ann.ann_id);
    article.innerHTML = `
      <div class="ann-type-icon ${type}">
        <i class="fa-solid ${icon}"></i>
      </div>
      <div class="ann-body">
        <div class="ann-header">
          <span class="ann-title">${title}</span>
          <span class="ann-badge ${type}">${type.charAt(0).toUpperCase() + type.slice(1)}</span>
        </div>
        <p class="ann-text">${bodyText}</p>
        <div class="ann-footer">
          <span class="ann-time"><i class="fa-regular fa-clock"></i> ${timeStr}</span>
          <span class="ann-author">· ${author}</span>
          <span class="ann-author">· ${audience}</span>
        </div>
      </div>`;
    container.appendChild(article);
  });
}

/* ----------------------------------------------------------------
   SKELETON LOADERS
   Show three placeholder cards while the first fetch is in flight.
---------------------------------------------------------------- */
function showSkeletons() {
  const container = document.getElementById('annCards');
  container.innerHTML = Array(3).fill(`
    <div class="ann-skeleton">
      <div class="skel-icon"></div>
      <div class="skel-body">
        <div class="skel-line mid"></div>
        <div class="skel-line short"></div>
        <div class="skel-line"></div>
        <div class="skel-line short"></div>
      </div>
    </div>`).join('');
}
function clearSkeletons() {
  document.getElementById('annCards').innerHTML = '';
}

/* ----------------------------------------------------------------
   SUBMIT ANNOUNCEMENT
   POSTs to the API with the CSRF token. On success, prepends the
   new card to the list without requiring a full page reload.
---------------------------------------------------------------- */
async function submitAnnouncement() {
  const titleEl    = document.getElementById('annTitle');
  const bodyEl     = document.getElementById('annBody');
  const audienceEl = document.getElementById('annAudience');
  const submitBtn  = document.getElementById('submitAnnBtn');

  const title    = titleEl.value.trim();
  const bodyText = bodyEl.value.trim();
  const audience = audienceEl.value;
  const type     = document.querySelector('input[name="annType"]:checked')?.value ?? 'general';

  // --- Client-side validation (mirrors server-side rules) ---------
  let valid = true;

  if (!title) {
    document.getElementById('errTitle').classList.add('visible');
    titleEl.focus();
    valid = false;
  } else {
    document.getElementById('errTitle').classList.remove('visible');
  }

  if (!bodyText) {
    document.getElementById('errBody').classList.add('visible');
    if (valid) bodyEl.focus(); // focus the first failing field
    valid = false;
  } else {
    document.getElementById('errBody').classList.remove('visible');
  }

  if (!valid) return;

  // --- Send request -----------------------------------------------
  const defHtml = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<div class="spinner-sm"></div> Publishing…';

  try {
    const res = await fetch(`${API_BASE}announcements/announcements.php`, {
      method:      'POST',
      credentials: 'include', // required for session cookie
      headers:     {
        'Content-Type':      'application/json',
        'X-Requested-With':  'XMLHttpRequest',
        // Also pass CSRF token in the header (API accepts both header and body)
        'X-CSRF-Token':      csrfToken,
      },
      body: JSON.stringify({
        action:     'create',
        csrf_token: csrfToken, // also in body for belt-and-suspenders
        title,
        body:       bodyText,
        type,
        audience,
      }),
    });

    const data = await res.json();

    // Always sync the token — the API returns a fresh one on every response
    if (data.csrf_token) csrfToken = data.csrf_token;

    if (data.success) {
      closeModal();
      showToast('Announcement published!', 'success', '<i class="fa-solid fa-check"></i>');

      // Optimistic UI: prepend the new card immediately using the data
      // the server echoed back, so the user sees it without waiting for
      // a full list reload.
      const container = document.getElementById('annCards');

      // Remove any "empty state" placeholder that might be showing
      const emptyEl = container.querySelector('.ann-empty');
      if (emptyEl) emptyEl.remove();

      const typeIcons = { general:'fa-circle-info', urgent:'fa-triangle-exclamation', event:'fa-calendar-star' };
      const ann = data.ann;

      const article = document.createElement('article');
      article.className = 'ann-card';
      article.setAttribute('data-id', ann.ann_id);
      article.innerHTML = `
        <div class="ann-type-icon ${escapeHtml(ann.type)}">
          <i class="fa-solid ${typeIcons[ann.type] ?? 'fa-circle-info'}"></i>
        </div>
        <div class="ann-body">
          <div class="ann-header">
            <span class="ann-title">${escapeHtml(ann.title)}</span>
            <span class="ann-badge ${escapeHtml(ann.type)}">${escapeHtml(ann.type.charAt(0).toUpperCase() + ann.type.slice(1))}</span>
          </div>
          <p class="ann-text">${escapeHtml(ann.body)}</p>
          <div class="ann-footer">
            <span class="ann-time"><i class="fa-regular fa-clock"></i> ${escapeHtml(ann.created_at_formatted)}</span>
            <span class="ann-author">· ${escapeHtml(ann.author_name)}</span>
            <span class="ann-author">· ${escapeHtml(ann.audience)}</span>
          </div>
        </div>`;

      // Insert at the top of the list (newest-first order)
      container.insertBefore(article, container.firstChild);
      totalItems++;

    } else {
      showToast(data.message || 'Failed to publish announcement.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
      submitBtn.disabled = false;
      submitBtn.innerHTML = defHtml;
    }

  } catch (err) {
    console.error('submitAnnouncement error:', err);
    showToast('Network error. Please try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    submitBtn.disabled = false;
    submitBtn.innerHTML = defHtml;
  } finally {
    // Always re-enable the button after the request settles
    // (the disabled state is only kept on success to prevent double-submit)
    if (!submitBtn.disabled) {
      submitBtn.innerHTML = defHtml;
    } else {
      // Re-enable after the modal closes so it's ready next time
      setTimeout(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = defHtml;
      }, 600);
    }
  }
}

/* ----------------------------------------------------------------
   XSS PROTECTION
   All user-supplied content must pass through escapeHtml() before
   being inserted into innerHTML. This converts the 5 HTML special
   characters into their safe entity equivalents.
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
   INIT
   Load the first page of announcements when the page is ready.
---------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  loadAnnouncements(false);
});











</script>
</body>
</html>
