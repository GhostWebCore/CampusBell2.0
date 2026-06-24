<?php
/**
 * login.php
 *
 * Public entry point. If the user already has a valid session they
 * shouldn't see the login screen again — send them straight to the
 * dashboard. Otherwise render the form.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
include_once __DIR__ . '/includes/api_base.php';

// --- Already authenticated? Bounce to dashboard. -----------------
if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>CampusBell — IoT Bell System</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

<link rel="stylesheet" href="assets/css/main.css" />
<style>




.page { display: flex; }










/* ============================================================
   LOGIN
============================================================ */
#page-login {
  min-height: 100vh;
  width: 100%;
  align-items: center;
  justify-content: center;
  background: var(--ink-950);
  background-image:
    radial-gradient(900px circle at 18% 12%, rgba(240,138,36,0.16), transparent 55%),
    radial-gradient(700px circle at 84% 88%, rgba(59,111,224,0.18), transparent 55%);
  position: relative;
  overflow: hidden;
  flex-direction: column;
  padding: 1.5rem;
}
.login-grain {
  position: absolute; inset: 0;
  background-image: linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
                     linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
  background-size: 42px 42px;
  pointer-events: none;
  mask-image: radial-gradient(circle at 50% 40%, black 0%, transparent 70%);
}
.login-wrap {
  position: relative; z-index: 1;
  width: 100%; max-width: 920px;
  display: grid; grid-template-columns: 1.05fr 1fr;
  background: var(--paper-1);
  border-radius: var(--r-lg);
  overflow: hidden;
  box-shadow: var(--shadow-3);
  animation: rise 0.5s var(--ease);
}
@keyframes rise { from { opacity:0; transform: translateY(20px); } to { opacity:1; transform:none; } }

.login-aside {
  background: var(--ink-900);
  background-image: radial-gradient(520px circle at 30% 0%, rgba(240,138,36,0.22), transparent 60%);
  padding: 2.75rem 2.25rem;
  display: flex; flex-direction: column; justify-content: space-between;
  color: white;
  position: relative;
}
.login-aside-top { display: flex; align-items: center; gap: 0.7rem; }
.brand-mark {
  width: 42px; height: 42px;
  border-radius: var(--r-sm);
  background: var(--signal-500);
  display: flex; align-items: center; justify-content: center;
  color: var(--ink-950); font-size: 19px;
  flex-shrink: 0;
}
.brand-text h1 { font-family: var(--font-display); font-size: 1.1rem; font-weight: 700; letter-spacing: -0.01em; }
.brand-text span { font-size: 0.7rem; color: rgba(255,255,255,0.55); letter-spacing: 0.04em; text-transform: uppercase; }

.login-pulse-art {
  flex: 1;
  display: flex; align-items: center; justify-content: center;
  margin: 1.5rem 0;
}
.ring-rings { position: relative; width: 140px; height: 140px; display:flex; align-items:center; justify-content:center; }
.ring-rings .core { width: 56px; height: 56px; border-radius: 50%; background: var(--signal-500); display:flex; align-items:center; justify-content:center; color:var(--ink-950); font-size:22px; z-index:2; }
.ring-rings .wave { position: absolute; border-radius: 50%; border: 1.5px solid rgba(240,138,36,0.45); animation: ringwave 2.6s var(--ease) infinite; }
.ring-rings .wave:nth-child(2) { animation-delay: 0.6s; }
.ring-rings .wave:nth-child(3) { animation-delay: 1.2s; }
@keyframes ringwave {
  0%   { width: 56px; height: 56px; opacity: 0.7; }
  100% { width: 150px; height: 150px; opacity: 0; }
}

.login-aside-bottom p { font-size: 0.82rem; color: rgba(255,255,255,0.6); line-height: 1.6; max-width: 320px; }
.login-aside-meta { display:flex; gap:1.25rem; margin-top: 1.25rem; }
.login-aside-meta div { font-family: var(--font-mono); font-size: 0.7rem; color: rgba(255,255,255,0.45); }
.login-aside-meta strong { display:block; font-family: var(--font-display); font-size: 1.2rem; color: white; }

.login-form-side { padding: 2.75rem 2.5rem; display: flex; flex-direction: column; justify-content: center; }
.login-heading { font-family: var(--font-display); font-size: 1.6rem; font-weight: 700; color: var(--text-900); margin-bottom: 0.35rem; letter-spacing: -0.01em; }
.login-sub { font-size: 0.875rem; color: var(--text-600); margin-bottom: 1.85rem; }




.form-input-wrap { position: relative; }
.form-input-wrap .fi-icon {
  position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%);
  color: var(--text-400); font-size: 14px; pointer-events: none;
}
.form-input-i {
  width: 100%; padding: 0.72rem 1rem 0.72rem 2.5rem;
  border: 1.5px solid var(--line-2); border-radius: var(--r-sm);
  font-size: 0.92rem; color: var(--text-900); background: var(--paper);
  transition: border-color var(--t), box-shadow var(--t), background var(--t);
  outline: none;
}


.btn-primary {
  width: 100%; padding: 0.82rem 1.5rem;
  background: var(--signal-500); color: white; border-radius: var(--r-sm);
  font-size: 0.92rem; font-weight: 600; font-family: var(--font-display);
  transition: background var(--t), transform var(--t), box-shadow var(--t);
  margin-top: 0.4rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
}
.btn-primary:hover { background: var(--signal-600); box-shadow: 0 6px 18px rgba(221,115,17,0.32); transform: translateY(-1px); }
.btn-primary:active { transform: translateY(0); }
.btn-primary:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

.login-footer { text-align: center; font-size: 0.76rem; color: var(--text-400); margin-top: 1.5rem; }
.spinner { width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); border-top-color: white; animation: spin 0.7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 760px) {
  .login-wrap { grid-template-columns: 1fr; max-width: 440px; }
  .login-aside { display: none; }
  .login-form-side { padding: 2.25rem 1.5rem; }
}
</style>
</head>
<body>
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<div id="page-login" class="page active">
  <div class="login-grain"></div>
  <div class="login-wrap">

    <div class="login-aside">
      <div class="login-aside-top">
        <div class="brand-mark"><i class="fa-solid fa-bell"></i></div>
        <div class="brand-text">
          <h1>CampusBell</h1>
          <span>IoT Bell Control</span>
        </div>
      </div>

      <div class="login-pulse-art">
        <div class="ring-rings">
          <div class="wave"></div><div class="wave"></div><div class="wave"></div>
          <div class="core"><i class="fa-solid fa-bell"></i></div>
        </div>
      </div>

      <div class="login-aside-bottom">
        <p>Every period, every zone, on schedule. CampusBell keeps your campus running on time, automatically.</p>
        <div class="login-aside-meta">
          <div><strong>6</strong>Zones</div>
          <div><strong>24</strong>Bells / day</div>
          <div><strong>98%</strong>Uptime</div>
        </div>
      </div>
    </div>

    <div class="login-form-side">
      <h2 class="login-heading">Welcome back</h2>
      <p class="login-sub">Sign in to manage your campus bell schedule.</p>

      <div class="form-group">
        <label class="form-label" for="loginEmail">Username</label>
        <div class="form-input-wrap">
          <i class="fa-solid fa-user fi-icon"></i>
          <input class="form-input-i" type="text" id="loginEmail" placeholder="admin" autocomplete="username" aria-required="true" />
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="loginPass">Password</label>
        <div class="form-input-wrap">
          <i class="fa-solid fa-lock fi-icon"></i>
          <input class="form-input-i" type="password" id="loginPass" placeholder="••••••••" autocomplete="current-password" aria-required="true" />
        </div>
      </div>

      <button class="btn-primary" id="loginBtn">
        <span id="loginBtnText">Sign in</span>
      </button>

      <p class="login-footer">© 2026 CampusBell IoT System · v2.4.1</p>
    </div>
  </div>
</div>






<script src='assets/js/main.js' defer ></script>



<script>
// Holds the current single-use CSRF token.
let csrfToken = null;

async function fetchCsrfToken() {
  try {
    const res = await fetch(`<?php echo $API_BASED; ?>auth/csrf_token.php`, {
      credentials: 'include',
    });
    const body = await res.json();
    csrfToken = body.csrf_token ?? null;
  } catch (err) {
    console.error('Could not fetch CSRF token:', err);
  }
}

document.addEventListener('DOMContentLoaded', fetchCsrfToken);

// Allow Enter key on either field to submit.
document.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') handleLogin();
});

document.getElementById('loginBtn').addEventListener('click', handleLogin);

async function handleLogin() {
  const btn   = document.getElementById('loginBtn');
  const email = document.getElementById('loginEmail').value.trim();
  const pass  = document.getElementById('loginPass').value;

  if (!email || !pass) {
    showToast('Please enter your username and password.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    return;
  }

  if (!csrfToken) {
    await fetchCsrfToken();
    if (!csrfToken) {
      showToast('Unable to reach the server. Please check your connection and try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
      return;
    }
  }

  const defHtml = btn.innerHTML;
  btn.innerHTML = '<div class="spinner"></div>';
  btn.disabled = true;

  try {
    const response = await fetch(`<?php echo $API_BASED; ?>auth/login.php`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({
        email:      email,
        pass:       pass,
        csrf_token: csrfToken,
      }),
    });

    const body = await response.json();

    // Refresh our local token whether the attempt succeeded or failed.
    if (body.csrf_token) {
      csrfToken = body.csrf_token;
    } else {
      await fetchCsrfToken();
    }

    if (body.success) {
      showToast(body.message || 'Welcome back!', 'success', '<i class="fa-solid fa-check"></i>');
      setTimeout(() => { window.location.href = 'dashboard.php'; }, 800);
    } else {
      showToast(body.message || 'Sign in failed.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
      btn.innerHTML = defHtml;
      btn.disabled  = false;
    }

  } catch (networkError) {
    console.error(networkError);
    showToast('Unable to reach the server. Please check your connection and try again.', 'danger', '<i class="fa-solid fa-circle-exclamation"></i>');
    btn.innerHTML = defHtml;
    btn.disabled  = false;
  }
}


</script>
</body>
</html>
