function showToast(msg, type = 'info', icon = '<i class="fa-solid fa-circle-info"></i>') {
  const container = document.getElementById('toastContainer');

  const toast = document.createElement('div');
  toast.className = 'toast ' + type;
  toast.innerHTML = `
    <span class="toast-icon">${icon}</span>
    <span>${msg}</span>
  `;

  container.appendChild(toast);

  setTimeout(() => {
    toast.remove();
  }, 3100);
}




















function openLogoutOverlay() {
  document.getElementById('logoutOverlay')?.classList.add('open');
}

function closeLogoutOverlay() {
  document.getElementById('logoutOverlay')?.classList.remove('open');
}

async function performLogout() {
  const btn = document.getElementById('confirmLogoutBtn');
  if (!btn) return;

  btn.disabled = true;
  btn.innerHTML = '<div class="spinner-sm"></div> Signing out…';

  try {
    const res = await fetch(`${API_BASE}auth/logout.php`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    const body = await res.json();

    if (body.success) {
      showToast(
        'Signed out successfully.',
        'success',
        '<i class="fa-solid fa-check"></i>'
      );

      setTimeout(() => {
        window.location.href = 'login.php';
      }, 900);

    } else {
      showToast(
        'Sign out failed. Please try again.',
        'danger',
        '<i class="fa-solid fa-circle-exclamation"></i>'
      );

      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Sign out';
    }

  } catch (err) {
    console.error(err);

    showToast(
      'Network error. Please try again.',
      'danger',
      '<i class="fa-solid fa-circle-exclamation"></i>'
    );

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Sign out';
  }
}

/* Backdrop click closes modal */
document.addEventListener('click', (e) => {
  const overlay = document.getElementById('logoutOverlay');
  if (overlay && e.target === overlay) closeLogoutOverlay();
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeLogoutOverlay();
});