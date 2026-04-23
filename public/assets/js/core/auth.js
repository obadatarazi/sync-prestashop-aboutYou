'use strict';

async function doLogin() {
  const err = document.getElementById('login-err');
  const user = document.getElementById('u-user').value;
  const pass = document.getElementById('u-pass').value;
  err.textContent = '';
  const r = await api('login', { username: user, password: pass });
  if (!r.ok || !r.data.ok) { err.textContent = r.data.error || 'Login failed'; return; }
  csrf = r.data.data.csrf;
  document.getElementById('whoami').textContent = r.data.data.username;
  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('app-screen').style.display = 'flex';
  await initApp();
}

async function initApp() {
  await loadSettings();
  await loadDashboard();
  startJobPoll();
}

async function restoreSessionOnLoad() {
  const statusResp = await api('status', {});
  if (!statusResp.ok || !statusResp.data?.ok) {
    return;
  }

  const csrfResp = await api('csrf', {});
  if (csrfResp.ok && csrfResp.data?.ok) {
    csrf = csrfResp.data.data?.csrf || '';
  }

  document.getElementById('login-screen').style.display = 'none';
  document.getElementById('app-screen').style.display = 'flex';
  await initApp();
}
