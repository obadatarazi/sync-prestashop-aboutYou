'use strict';

async function loadOnboarding() {
  let ps = {};
  let is = {};
  let statusError = '';
  try {
    const r = await api('status', {});
    if (r.ok && r.data?.ok) {
      ps = r.data?.data?.products || {};
      is = r.data?.data?.images || {};
    } else {
      statusError = r.data?.error || 'Unable to load account health';
    }
  } catch (e) {
    statusError = e?.message || 'Unable to load account health';
  }

  const setupStepsEl = document.getElementById('setup-steps');
  const healthGridEl = document.getElementById('health-grid');
  const requiredDataEl = document.getElementById('required-data');
  if (!setupStepsEl || !healthGridEl || !requiredDataEl) return;

  const steps = [
    { label: 'Connect PrestaShop', desc: 'Set PS_BASE_URL and PS_API_KEY', done: true },
    { label: 'Connect AboutYou', desc: 'Set AY_API_KEY and AY_BRAND_ID', done: true },
    { label: 'Configure IDs', desc: 'Set default AY IDs', done: false, cur: true, action: "goto('settings')", btn: 'Configure →' },
    { label: 'Map Categories', desc: 'Map each PrestaShop category to an AboutYou category', done: false, action: "goto('mappings')", btn: 'Map →' },
    { label: 'Map Attributes', desc: 'Map PrestaShop size and color values to AboutYou options', done: false, action: "goto('attributes')", btn: 'Map →' },
    { label: 'Import Product Catalog', desc: 'Fetch all products from PrestaShop into DB', done: (ps.total||0) > 0, action: "runSync('products')", btn: 'Import →' },
    { label: 'Run Quality Check', desc: 'Verify images, EANs and attributes', done: false, action: "goto('quality')", btn: 'Check →' },
    { label: 'Push to AboutYou', desc: 'Send all products to AY Seller Center', done: (ps.synced||0) > 0, action: "runSync('products')", btn: 'Push →' },
  ];

  setupStepsEl.innerHTML = steps.map((s, i) => `
    <div class="step ${s.done?'done':''} ${s.cur&&!s.done?'cur':''}">
      <div class="snum">${s.done ? '✓' : i+1}</div>
      <div style="flex:1"><div style="font-size:13px;font-weight:500;margin-bottom:2px;">${esc(s.label)}</div>
        <div style="font-size:11.5px;color:var(--muted);">${esc(s.desc)}</div></div>
      ${!s.done && s.btn ? `<button class="btn btn-sm ${s.cur?'btn-p':''}" onclick="${s.action}">${esc(s.btn)}</button>` : ''}
    </div>`).join('');

  healthGridEl.innerHTML = `
    <div style="text-align:center;padding:10px;background:var(--surface2);border-radius:8px;">
      <div style="font-size:20px;font-weight:700;color:var(--green)">${ps.total||0}</div>
      <div style="font-size:10px;color:var(--muted);">Products</div></div>
    <div style="text-align:center;padding:10px;background:var(--surface2);border-radius:8px;">
      <div style="font-size:20px;font-weight:700;color:${is.error?'var(--amber)':'var(--green)'}">${is.ok||0}</div>
      <div style="font-size:10px;color:var(--muted);">Images OK</div></div>
    <div style="text-align:center;padding:10px;background:var(--surface2);border-radius:8px;">
      <div style="font-size:20px;font-weight:700;color:var(--red)">${ps.error||0}</div>
      <div style="font-size:10px;color:var(--muted);">Errors</div></div>`;
  const safeSettings = Array.isArray(settingsCache) ? settingsCache : [];
  const settingsMap = Object.fromEntries(safeSettings.map(s => [s.key, s.value]));
  const catMap = (() => { try { return JSON.parse(settingsMap.ay_category_map || '{}'); } catch { return {}; } })();
  const mappedCount = Object.keys(catMap).length;
  const defaultAyCategoryId = settingsMap.ay_category_id || '';

  requiredDataEl.innerHTML = `
    ${statusError ? `<div style="margin-bottom:8px;padding:8px 10px;border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.08);border-radius:8px;font-size:11.5px;color:var(--amber);">${esc(statusError)}</div>` : ''}
    <div style="display:flex;flex-direction:column;gap:8px;">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px;background:var(--surface2);border-radius:8px;">
        <div>
          <div style="font-size:12.5px;font-weight:500;">AboutYou category mapping</div>
          <div style="font-size:11px;color:var(--muted);">${mappedCount} mapped categories saved</div>
        </div>
        <button class="btn btn-sm ${mappedCount ? '' : 'btn-p'}" onclick="goto('mappings')">${mappedCount ? 'Manage →' : 'Map now →'}</button>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px;background:var(--surface2);border-radius:8px;">
        <div>
          <div style="font-size:12.5px;font-weight:500;">Attribute mapping</div>
          <div style="font-size:11px;color:var(--muted);">Default AY category: ${esc(defaultAyCategoryId || 'not set')}</div>
        </div>
        <button class="btn btn-sm" onclick="goto('attributes')">Open →</button>
      </div>
    </div>`;
}
