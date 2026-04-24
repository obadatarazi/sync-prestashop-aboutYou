'use strict';

// Navigation (sidebar clicks)
document.querySelectorAll('.nav[data-page]').forEach(n => {
  n.addEventListener('click', () => goto(n.dataset.page));
});

// Auth
document.getElementById('login-btn').addEventListener('click', doLogin);
document.getElementById('u-pass').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
document.getElementById('logout-btn').addEventListener('click', async () => {
  await api('logout', {});
  location.reload();
});

// Per-page event wiring (each wire* function is defined in its matching
// pages/*.js module and only attaches listeners that existed originally).
wireProductsPage();
wireOrdersPage();
wireImagesPage();
wireLogsPage();
wireSyncPage();
wireDashboardPage();
wireSettingsPage();
wireMappingsPage();
wireAttributesPage();
wireSchedulerPage();

// Top-bar quick sync
document.getElementById('quick-sync').addEventListener('click', () => runSync('all'));

// Attempt to resume an existing session; otherwise the login screen stays up.
void restoreSessionOnLoad();
