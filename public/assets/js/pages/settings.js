'use strict';

async function loadSettings() {
  const r = await api('settings', {});
  if (!r.ok || !r.data.ok) return;
  settingsCache = r.data.data;
  const groups = {};
  settingsCache.forEach(s => { (groups[s.group_name] = groups[s.group_name]||[]).push(s); });

  const groupLabels = { prestashop:'PrestaShop', aboutyou:'AboutYou Seller Center',
    sync:'Sync Parameters', images:'Image Normalization', notifications:'Notifications' };

  document.getElementById('settings-form').innerHTML = `
    <div class="g2">${Object.entries(groups).map(([g, fields]) => `
      <div class="card">
        <div class="ct" style="margin-bottom:12px;">${esc(groupLabels[g]||g)}</div>
        ${fields.map(f => `
          <div class="fr">
            <label class="fl">${esc(f.label||f.key)}</label>
            ${f.type === 'boolean'
              ? `<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12.5px;">
                   <input type="checkbox" data-key="${esc(f.key)}" ${f.value==='true'?'checked':''} style="accent-color:var(--accent);width:14px;height:14px;">
                   <span>Enabled</span></label>`
              : `<input class="fi" type="${f.type==='password'?'password':'text'}" data-key="${esc(f.key)}" value="${esc(f.value||'')}" placeholder="${esc(f.key)}">`}
          </div>`).join('')}
      </div>`).join('')}
    </div>`;

  if (!document.getElementById('scheduler-sync-path').value) {
    document.getElementById('scheduler-sync-path').value = '/absolute/path/to/SyncBridge/bin/sync.php';
  }
}

function wireSettingsPage() {
  document.getElementById('save-settings').addEventListener('click', async () => {
    const settings = {};
    document.querySelectorAll('[data-key]').forEach(el => {
      settings[el.dataset.key] = el.type === 'checkbox' ? (el.checked ? 'true' : 'false') : el.value;
    });
    const r = await api('settings_save', { settings });
    if (r.ok && r.data.ok) {
      toast(`Saved ${r.data.data.saved} settings`, 'ok');
      await loadSettings();
      startJobPoll();
    } else toast('Save failed', 'err');
  });

  document.getElementById('test-conn').addEventListener('click', async () => {
    const el = document.getElementById('conn-result');
    el.textContent = 'Testing...'; el.style.color = 'var(--muted)';
    const r = await api('status', {});
    if (r.ok && r.data.ok) { el.textContent = '✓ Connected'; el.style.color = 'var(--green)'; }
    else { el.textContent = '✗ Failed'; el.style.color = 'var(--red)'; }
  });
}
