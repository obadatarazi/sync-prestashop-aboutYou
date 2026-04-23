'use strict';

function goto(page) {
  currentPage = page;
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav').forEach(n => n.classList.remove('active'));
  const panel = document.getElementById('p-' + page);
  if (panel) panel.classList.add('active');
  document.querySelectorAll(`.nav[data-page="${page}"]`).forEach(n => n.classList.add('active'));
  const titles = { dashboard:'Dashboard',onboarding:'Onboarding',products:'Products',
    quality:'Quality Check',images:'Images',orders:'Orders',sync:'Sync Engine',mappings:'Category Mapping',attributes:'Attribute Mapping',
    logs:'Logs',scheduler:'Scheduler',settings:'Settings' };
  document.getElementById('page-title').textContent = titles[page] || page;
  // Lazy load
  if (page === 'products')  loadProducts();
  if (page === 'orders')    loadOrders();
  if (page === 'logs')      loadLogs();
  if (page === 'images')    loadImages();
  if (page === 'sync')      loadRuns();
  if (page === 'mappings')  loadCategoryMappings();
  if (page === 'attributes') loadAttributeMappings();
  if (page === 'settings')  loadSettings();
  if (page === 'scheduler') loadScheduler();
  if (page === 'quality')   loadQuality();
  if (page === 'onboarding') loadOnboarding();
}
