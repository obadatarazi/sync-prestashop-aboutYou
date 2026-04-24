'use strict';

let requiredGroupDefaultsDraft = [];

async function loadAttributeMappings() {
  const tbody = document.getElementById('attributes-body');
  tbody.innerHTML = '<tr><td colspan="6" class="loading"><span class="spin">⟳</span></td></tr>';
  const r = await api('attribute_mappings', {}, 'POST');
  if (!r.ok || !r.data.ok) {
    tbody.innerHTML = `<tr><td colspan="6" style="color:var(--red);padding:14px;">${esc(r.data?.error || 'Failed to load attributes')}</td></tr>`;
    return;
  }
  attributeMappingsCache = r.data.data || [];
  attributeMappingsDraft = Object.fromEntries(attributeMappingsCache
    .filter(row => row.ay_id)
    .map(row => [`${row.map_type}|${row.ps_label.toLowerCase()}`, { map_type: row.map_type, ps_label: row.ps_label, ay_id: row.ay_id, ay_label: row.ay_label || '' }]));

  if (!document.getElementById('attr-category-id').value) {
    const settingsMap = Object.fromEntries(settingsCache.map(s => [s.key, s.value]));
    document.getElementById('attr-category-id').value = settingsMap.ay_category_id || '';
  }
  renderAttributeMappings();
  await loadRequiredGroupDefaults(Number(document.getElementById('attr-category-id')?.value || 0));
}

function renderAttributeMappings() {
  const tbody = document.getElementById('attributes-body');
  const q = (document.getElementById('attr-search')?.value || '').toLowerCase().trim();
  const typeFilter = document.getElementById('attr-type-filter')?.value || '';
  const rows = attributeMappingsCache.filter(row => {
    if (typeFilter && row.map_type !== typeFilter) return false;
    if (!q) return true;
    return String(row.ps_label || '').toLowerCase().includes(q) || String(row.group_name || '').toLowerCase().includes(q);
  });
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:var(--muted);padding:14px;text-align:center;">No attributes found</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(row => {
    const key = `${row.map_type}|${String(row.ps_label).toLowerCase()}`;
    const current = attributeMappingsDraft[key] || (row.ay_id ? { ay_id: row.ay_id, ay_label: row.ay_label || '' } : null);
    return `<tr>
      <td><span class="badge ${row.map_type === 'color' ? 'b-purple' : 'b-info'}">${esc(row.map_type)}</span></td>
      <td>${esc(row.group_name || '—')}</td>
      <td>
        <div style="font-size:12.5px;font-weight:500;">${esc(row.ps_label || '—')}</div>
        <div class="mono">PS Value #${row.ps_value_id}</div>
      </td>
      <td>${current ? `<div class="mono">AY#${esc(current.ay_id)}</div>` : '<span class="badge b-err">Not mapped</span>'}</td>
      <td>
        <div style="display:flex;gap:8px;">
          <input class="fi" id="attr-query-${row.ps_value_id}" placeholder="Search AY..." value="${esc(row.ps_label || '')}">
          <button class="btn btn-sm attr-search-btn" data-ps-value-id="${row.ps_value_id}" data-map-type="${esc(row.map_type)}" data-ps-label="${esc(row.ps_label || '')}">Search</button>
        </div>
      </td>
      <td id="attr-results-${row.ps_value_id}" style="min-width:320px;color:var(--muted);font-size:12px;">${current ? 'Current mapping loaded' : 'Search AboutYou options'}</td>
    </tr>`;
  }).join('');

  tbody.querySelectorAll('.attr-search-btn').forEach(btn => {
    btn.onclick = () => searchAyAttributeOptions(
      Number(btn.dataset.psValueId || 0),
      btn.dataset.mapType || '',
      btn.dataset.psLabel || ''
    );
  });
}

async function searchAyAttributeOptions(psValueId, mapType, psLabel) {
  const categoryId = Number(document.getElementById('attr-category-id')?.value || 0);
  const query = document.getElementById(`attr-query-${psValueId}`)?.value || '';
  const container = document.getElementById(`attr-results-${psValueId}`);
  container.innerHTML = '<span class="spin">⟳</span> Searching...';
  const r = await api('ay_attribute_options', { type: mapType, category_id: categoryId, query }, 'POST');
  if (!r.ok || !r.data.ok) {
    container.textContent = r.data?.error || 'Search failed';
    return;
  }
  const items = r.data.data || [];
  if (!items.length) {
    container.textContent = 'No results';
    return;
  }
  container.innerHTML = items.slice(0, 8).map(item => `
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;margin-bottom:6px;">
      <div style="min-width:0;">
        <div style="font-size:12px;color:var(--text);">${esc(item.label)}</div>
        <div class="mono">${esc(item.group_name || '')} · AY#${item.id}</div>
      </div>
      <button class="btn btn-sm btn-p">Use</button>
    </div>
  `).join('');
  container.querySelectorAll('button').forEach((btn, idx) => {
    const item = items[idx];
    btn.onclick = () => applyAttributeMapping(mapType, psLabel, { ay_id: item.id, ay_label: item.label });
  });
}

function applyAttributeMapping(mapType, psLabel, mapping) {
  const key = `${mapType}|${String(psLabel).toLowerCase()}`;
  attributeMappingsDraft[key] = { map_type: mapType, ps_label: psLabel, ay_id: Number(mapping.ay_id), ay_label: String(mapping.ay_label || '') };
  renderAttributeMappings();
  toast(`Mapped ${mapType} "${psLabel}" to AY#${mapping.ay_id}`, 'ok');
}

async function loadRequiredGroupDefaults(categoryIdOverride = null) {
  const tbody = document.getElementById('req-defaults-body');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" class="loading"><span class="spin">⟳</span></td></tr>';
  const categoryInput = document.getElementById('req-defaults-category-id');
  const categoryId = categoryIdOverride !== null
    ? Number(categoryIdOverride || 0)
    : Number(categoryInput?.value || document.getElementById('attr-category-id')?.value || 0);
  if (categoryInput && categoryIdOverride !== null) {
    categoryInput.value = String(categoryId || '');
  }
  const r = await api('required_group_defaults', { category_id: categoryId > 0 ? categoryId : undefined });
  if (!r.ok || !r.data?.ok) {
    tbody.innerHTML = `<tr><td colspan="5" style="color:var(--red);padding:14px;">${esc(r.data?.error || 'Failed to load defaults')}</td></tr>`;
    return;
  }
  requiredGroupDefaultsDraft = Array.isArray(r.data.data) ? r.data.data.map((row) => ({
    ay_category_id: Number(row.ay_category_id || 0),
    ay_group_id: Number(row.ay_group_id || 0),
    ay_group_name: String(row.ay_group_name || ''),
    default_ay_id: Number(row.default_ay_id || 0),
    default_label: String(row.default_label || ''),
  })) : [];
  renderRequiredGroupDefaults();
}

function renderRequiredGroupDefaults() {
  const tbody = document.getElementById('req-defaults-body');
  if (!tbody) return;
  if (!requiredGroupDefaultsDraft.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted);padding:14px;text-align:center;">No defaults yet</td></tr>';
    return;
  }
  tbody.innerHTML = requiredGroupDefaultsDraft.map((row, idx) => `
    <tr>
      <td><input class="fi req-defaults-input" data-idx="${idx}" data-key="ay_category_id" type="number" value="${Number(row.ay_category_id || 0)}"></td>
      <td><input class="fi req-defaults-input" data-idx="${idx}" data-key="ay_group_id" type="number" value="${Number(row.ay_group_id || 0)}"></td>
      <td><input class="fi req-defaults-input" data-idx="${idx}" data-key="ay_group_name" value="${esc(row.ay_group_name || '')}"></td>
      <td><input class="fi req-defaults-input" data-idx="${idx}" data-key="default_ay_id" type="number" value="${Number(row.default_ay_id || 0)}"></td>
      <td><input class="fi req-defaults-input" data-idx="${idx}" data-key="default_label" value="${esc(row.default_label || '')}"></td>
    </tr>
  `).join('');
  tbody.querySelectorAll('.req-defaults-input').forEach((input) => {
    input.addEventListener('input', (event) => {
      const t = event.target;
      const idx = Number(t.dataset.idx || 0);
      const key = String(t.dataset.key || '');
      if (!requiredGroupDefaultsDraft[idx] || !key) return;
      const numKeys = ['ay_category_id', 'ay_group_id', 'default_ay_id'];
      requiredGroupDefaultsDraft[idx][key] = numKeys.includes(key) ? Number(t.value || 0) : String(t.value || '');
    });
  });
}

function wireAttributesPage() {
  document.getElementById('attr-search').addEventListener('input', renderAttributeMappings);
  document.getElementById('attr-type-filter').addEventListener('change', renderAttributeMappings);
  document.getElementById('attr-refresh').addEventListener('click', loadAttributeMappings);
  document.getElementById('req-defaults-refresh').addEventListener('click', () => loadRequiredGroupDefaults());
  document.getElementById('req-defaults-category-id').addEventListener('change', () => loadRequiredGroupDefaults());
  document.getElementById('req-defaults-add-row').addEventListener('click', () => {
    const fallbackCategory = Number(document.getElementById('req-defaults-category-id')?.value || document.getElementById('attr-category-id')?.value || 0);
    requiredGroupDefaultsDraft.push({
      ay_category_id: fallbackCategory > 0 ? fallbackCategory : 0,
      ay_group_id: 0,
      ay_group_name: '',
      default_ay_id: 0,
      default_label: '',
    });
    renderRequiredGroupDefaults();
  });
  document.getElementById('req-defaults-save').addEventListener('click', async () => {
    const result = document.getElementById('req-defaults-result');
    const payload = requiredGroupDefaultsDraft
      .filter((row) => Number(row.ay_group_id || 0) > 0 && Number(row.default_ay_id || 0) > 0)
      .map((row) => ({
        ay_category_id: Number(row.ay_category_id || 0),
        ay_group_id: Number(row.ay_group_id || 0),
        ay_group_name: String(row.ay_group_name || ''),
        default_ay_id: Number(row.default_ay_id || 0),
        default_label: String(row.default_label || ''),
      }));
    if (result) {
      result.textContent = 'Saving...';
      result.style.color = 'var(--muted)';
    }
    const r = await api('required_group_defaults_save', { defaults: payload });
    if (!r.ok || !r.data?.ok) {
      if (result) {
        result.textContent = r.data?.error || 'Save failed';
        result.style.color = 'var(--red)';
      }
      toast('Required defaults save failed', 'err');
      return;
    }
    if (result) {
      result.textContent = `Saved ${Number(r.data.data?.saved || 0)} defaults`;
      result.style.color = 'var(--green)';
    }
    toast('Required group defaults saved', 'ok');
    await loadRequiredGroupDefaults();
  });
  document.getElementById('attr-save').addEventListener('click', async () => {
    const el = document.getElementById('attr-save-result');
    el.textContent = 'Saving...';
    const r = await api('attribute_mappings_save', { mappings: Object.values(attributeMappingsDraft) });
    if (r.ok && r.data.ok) {
      el.textContent = `Saved ${r.data.data.saved} mappings`;
      el.style.color = 'var(--green)';
      toast('Attribute mappings saved', 'ok');
    } else {
      el.textContent = r.data?.error || 'Save failed';
      el.style.color = 'var(--red)';
      toast('Attribute mapping save failed', 'err');
    }
  });
}

function openRequiredGroupDefaults(ayCategoryId = 0) {
  goto('attributes');
  const categoryId = Number(ayCategoryId || 0);
  const attrInput = document.getElementById('attr-category-id');
  const defaultsInput = document.getElementById('req-defaults-category-id');
  if (attrInput && categoryId > 0) {
    attrInput.value = String(categoryId);
  }
  if (defaultsInput && categoryId > 0) {
    defaultsInput.value = String(categoryId);
  }
  loadRequiredGroupDefaults(categoryId > 0 ? categoryId : null);
}
