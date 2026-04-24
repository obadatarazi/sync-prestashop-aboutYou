'use strict';

async function loadCategoryMappings() {
  const tbody = document.getElementById('mappings-body');
  tbody.innerHTML = '<tr><td colspan="5" class="loading"><span class="spin">⟳</span></td></tr>';
  const r = await api('category_mappings', {}, 'POST');
  if (!r.ok || !r.data.ok) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:var(--red);padding:14px;">Failed to load mappings</td></tr>';
    return;
  }

  categoryMappingsCache = r.data.data || [];
  categoryMappingsDraft = Object.fromEntries(categoryMappingsCache
    .filter(row => row.ay_category_id)
    .map(row => [String(row.ps_category_id), { id: row.ay_category_id, path: row.ay_category_path }]));
  renderCategoryMappings();
}

function renderCategoryMappings() {
  const tbody = document.getElementById('mappings-body');
  const q = (document.getElementById('map-search')?.value || '').toLowerCase().trim();
  const rows = categoryMappingsCache.filter(row =>
    !q || String(row.ps_category_name || '').toLowerCase().includes(q) || String(row.ps_category_id).includes(q)
  );

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:var(--muted);padding:14px;text-align:center;">No categories found</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(row => {
    const current = categoryMappingsDraft[String(row.ps_category_id)] || (row.ay_category_id ? { id: row.ay_category_id, path: row.ay_category_path } : null);
    return `<tr>
      <td>
        <div style="font-size:12.5px;font-weight:500;">${esc(row.ps_category_name || 'Unnamed')}</div>
        <div class="mono">PS Category #${row.ps_category_id}</div>
      </td>
      <td>
        <button class="btn btn-sm" onclick="showCategoryProducts(${row.ps_category_id})" ${row.product_count ? '' : 'disabled'}>
          ${row.product_count || 0} products
        </button>
      </td>
      <td>
        ${current
          ? `<div style="font-size:12.5px;color:var(--text);">${esc(current.path || current.id)}</div><div class="mono">AY#${esc(current.id)}</div>
             <div style="margin-top:4px;"><span class="badge b-info">Ready to validate</span></div>`
          : '<span class="badge b-err">Not mapped</span>'}
      </td>
      <td>
        <div style="display:flex;gap:8px;">
          <input class="fi" id="map-query-${row.ps_category_id}" placeholder="Search AY..." value="${esc(row.ps_category_name || '')}">
          <button class="btn btn-sm" onclick="searchAyCategories(${row.ps_category_id})">Search</button>
        </div>
      </td>
      <td id="map-results-${row.ps_category_id}" style="min-width:320px;color:var(--muted);font-size:12px;">${current ? 'Current mapping loaded' : 'Search AboutYou categories'}</td>
    </tr>`;
  }).join('');
}

async function searchAyCategories(psCategoryId) {
  const query = document.getElementById(`map-query-${psCategoryId}`)?.value || '';
  const container = document.getElementById(`map-results-${psCategoryId}`);
  container.innerHTML = '<span class="spin">⟳</span> Searching...';
  const r = await api('ay_categories_search', { query }, 'POST');
  if (!r.ok || !r.data.ok) {
    container.textContent = r.data?.error || 'Search failed';
    return;
  }
  const genderFilter = (document.getElementById('map-gender-filter')?.value || '').toLowerCase().trim();
  const itemsRaw = r.data.data.items || [];
  const items = itemsRaw.filter(item => {
    if (!genderFilter) return true;
    const haystack = String(item.path || item.name || '').toLowerCase();
    if (genderFilter === 'women') return /(^|[|/\s_-])women([|/\s_-]|$)/i.test(haystack);
    if (genderFilter === 'men') return /(^|[|/\s_-])men([|/\s_-]|$)/i.test(haystack);
    if (genderFilter === 'kids') return /(^|[|/\s_-])kids([|/\s_-]|$)/i.test(haystack);
    return true;
  });
  if (!items.length) {
    container.textContent = genderFilter
      ? `No results matching "${genderFilter}" filter`
      : 'No results';
    return;
  }
  container.innerHTML = items.slice(0, 8).map(item => `
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;margin-bottom:6px;">
      <div style="min-width:0;">
        <div style="font-size:12px;color:var(--text);">${esc(item.path || item.name)}</div>
        <div class="mono">AY#${item.id}</div>
      </div>
      <button class="btn btn-sm btn-p" data-ay-id="${item.id}" data-ay-path="${esc(item.path || item.name)}">Use</button>
    </div>
  `).join('');
  container.querySelectorAll('button').forEach((btn, idx) => {
    const item = items[idx];
    btn.onclick = () => applyCategoryMapping(psCategoryId, { id: item.id, path: item.path || item.name });
  });
}

async function validateSelectedCategoryMapping() {
  const resultEl = document.getElementById('map-validation-result');
  if (!resultEl) return;
  const entries = Object.entries(categoryMappingsDraft || {});
  if (!entries.length) {
    resultEl.textContent = 'Save or select at least one mapping first.';
    resultEl.style.color = 'var(--amber)';
    return;
  }
  // Validate the most recently edited mapping as "selected".
  const [psCategoryId, mapping] = entries[entries.length - 1];
  const ayCategoryId = Number(mapping?.id || 0);
  if (!ayCategoryId) {
    resultEl.textContent = 'Selected mapping has no AY category id.';
    resultEl.style.color = 'var(--red)';
    return;
  }
  resultEl.textContent = 'Validating mapping...';
  resultEl.style.color = 'var(--muted)';
  const r = await api('category_mapping_validate', {
    ps_category_id: Number(psCategoryId),
    ay_category_id: ayCategoryId,
  });
  if (!r.ok || !r.data?.ok) {
    resultEl.textContent = r.data?.error || 'Validation failed';
    resultEl.style.color = 'var(--red)';
    return;
  }
  const d = r.data.data || {};
  const warnings = Array.isArray(d.warnings) ? d.warnings : [];
  const quickFixes = Array.isArray(d.quick_fixes) ? d.quick_fixes : [];
  const badgeClass = d.risk_level === 'high' ? 'b-err' : d.risk_level === 'medium' ? 'b-warn' : 'b-ok';
  const groupScore = Number(d.group_completeness_score || 0);
  const ayCategoryIdNum = Number(d.ay_category_id || 0);
  resultEl.innerHTML = `
    <span class="badge ${badgeClass}" style="margin-right:6px;">Risk: ${esc(String(d.risk_level || 'low'))}</span>
    score=${Number(d.risk_score || 0)} · required_groups=${Number(d.required_groups_count || 0)} · defaults=${Number(d.group_defaults_count || 0)} · sampled=${Number(d.sample_products || 0)}
    <div style="margin-top:8px;">
      <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:4px;">
        <span>Product-group completeness</span>
        <span>${groupScore}%</span>
      </div>
      <div class="pb"><div class="pbf ${groupScore >= 90 ? 'g' : (groupScore >= 60 ? 'a' : 'b')}" style="width:${Math.max(0, Math.min(100, groupScore))}%"></div></div>
    </div>
    ${warnings.length ? `<div style="margin-top:6px;color:var(--amber)">• ${warnings.map(w => esc(String(w))).join('<br>• ')}</div>` : ''}
    ${quickFixes.length ? `<div style="margin-top:7px;color:var(--text)"><strong>Quick fixes:</strong><br>• ${quickFixes.map(x => esc(String(x))).join('<br>• ')}</div>` : ''}
    ${ayCategoryIdNum > 0 ? `<div style="margin-top:8px;"><button class="btn btn-sm" id="map-open-required-defaults">Open Required Group Defaults (AY#${ayCategoryIdNum})</button></div>` : ''}
  `;
  resultEl.style.color = 'var(--muted)';
  if (ayCategoryIdNum > 0) {
    document.getElementById('map-open-required-defaults')?.addEventListener('click', () => {
      if (typeof openRequiredGroupDefaults === 'function') {
        openRequiredGroupDefaults(ayCategoryIdNum);
      } else {
        goto('attributes');
      }
    });
  }
}

async function showCategoryProducts(psCategoryId) {
  const modal = document.getElementById('category-products-modal');
  const title = document.getElementById('category-products-title');
  const subtitle = document.getElementById('category-products-subtitle');
  const list = document.getElementById('category-products-list');
  const assignResult = document.getElementById('category-products-assign-result');
  const ayCategoryInput = document.getElementById('category-products-ay-category-id');
  if (!modal || !title || !subtitle || !list) return;
  currentCategoryProductsPsCategoryId = Number(psCategoryId || 0);
  if (assignResult) {
    assignResult.textContent = '';
  }
  if (ayCategoryInput) {
    ayCategoryInput.value = '';
  }
  currentCategorySuggestionsByPsId = {};
  const row = categoryMappingsCache.find(item => Number(item.ps_category_id) === Number(psCategoryId));
  title.textContent = row?.ps_category_name ? `${row.ps_category_name} products` : `PS Category #${psCategoryId} products`;
  subtitle.textContent = `PS Category #${psCategoryId}`;
  list.innerHTML = '<div class="loading" style="padding:16px;"><span class="spin">⟳</span> Loading products...</div>';
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');

  const r = await api('category_products', { ps_category_id: psCategoryId }, 'POST');
  if (!r.ok || !r.data.ok) {
    list.innerHTML = `<div style="color:var(--red);font-size:12.5px;">${esc(r.data?.error || 'Failed to load products')}</div>`;
    return;
  }
  const rows = r.data.data?.rows || [];
  currentCategoryProductsRows = rows;
  subtitle.textContent = `PS Category #${psCategoryId} · ${rows.length} products`;
  if (!rows.length) {
    list.innerHTML = '<div style="color:var(--muted);font-size:12.5px;">No products found in this category</div>';
    return;
  }
  renderCategoryProductsList();
}

function renderCategoryProductsList() {
  const list = document.getElementById('category-products-list');
  const rows = (currentCategoryProductsRows || []).slice(0, 50);
  if (!list) return;
  if (!rows.length) {
    list.innerHTML = '<div style="color:var(--muted);font-size:12.5px;">No products found in this category</div>';
    return;
  }
  list.innerHTML = rows.map(item => {
    const suggestion = currentCategorySuggestionsByPsId[String(item.ps_id)] || null;
    const riskLevel = String(suggestion?.risk_level || '').toLowerCase();
    const riskBadge = riskLevel
      ? `<span class="badge ${riskLevel === 'high' ? 'b-err' : (riskLevel === 'medium' ? 'b-warn' : 'b-ok')}">Risk: ${esc(riskLevel)}</span>`
      : '';
    const warnings = Array.isArray(suggestion?.policy_warnings) ? suggestion.policy_warnings.slice(0, 2) : [];
    const suggestionCategoryName = String(suggestion?.suggested?.path || '').trim();
    const suggestionLabel = suggestion?.suggested?.id
      ? `Suggested AY#${suggestion.suggested.id}${suggestion.confidence ? ` (${Math.round(Number(suggestion.confidence || 0) * 100)}%)` : ''}`
      : 'No suggestion';
    return `
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;margin-bottom:6px;">
      <div style="display:flex;align-items:center;gap:9px;min-width:0;">
        <div class="img-thumb">${renderProductThumbCell(item)}</div>
        <div style="min-width:0;">
          <div style="font-size:12px;color:var(--text);font-weight:500;">${esc(item.name || 'Unnamed product')}</div>
          <div class="mono">PS#${esc(item.ps_id)} · ${esc(item.reference || '—')} · ${esc(item.sync_status || 'pending')}</div>
          <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">${esc((item.description_short || item.description || '').slice(0, 120) || 'No description')}</div>
          <div style="font-size:11.5px;color:${suggestion?.suggested?.id ? 'var(--green)' : 'var(--muted)'};margin-top:2px;">${esc(suggestionLabel)}</div>
          ${suggestion?.suggested?.id && suggestionCategoryName ? `<div style="font-size:11.5px;color:var(--muted);margin-top:2px;">${esc(suggestionCategoryName)}</div>` : ''}
          ${(riskBadge || warnings.length) ? `<div style="margin-top:5px;display:flex;gap:5px;align-items:center;flex-wrap:wrap;">${riskBadge}${warnings.map((w) => `<span class="badge b-warn">${esc(String(w))}</span>`).join('')}</div>` : ''}
        </div>
      </div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
        ${suggestion?.suggested?.id ? `<button class="btn btn-sm btn-p category-product-apply-suggest-btn" data-ps-id="${Number(item.ps_id || 0)}" data-ay-id="${Number(suggestion.suggested.id || 0)}">Apply suggestion</button>` : ''}
        <button class="btn btn-sm category-product-view-btn" data-ps-id="${Number(item.ps_id || 0)}">View</button>
      </div>
    </div>
  `}).join('');
  list.querySelectorAll('.category-product-view-btn').forEach(btn => {
    btn.onclick = () => {
      const psId = Number(btn.dataset.psId || 0);
      if (psId > 0) {
        closeCategoryProductsModal();
        viewProduct(psId);
      }
    };
  });
  list.querySelectorAll('.category-product-apply-suggest-btn').forEach(btn => {
    btn.onclick = async () => {
      const psId = Number(btn.dataset.psId || 0);
      const ayId = Number(btn.dataset.ayId || 0);
      if (psId <= 0 || ayId <= 0) return;
      await assignAyCategoryToSingleProduct(psId, ayId);
    };
  });
  if (rows.length > 50) {
    list.innerHTML += `<div style="font-size:11.5px;color:var(--muted);">Showing first 50 of ${rows.length} products.</div>`;
  }
}

function closeCategoryProductsModal() {
  const modal = document.getElementById('category-products-modal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  currentCategoryProductsPsCategoryId = 0;
  currentCategoryProductsRows = [];
  currentCategorySuggestionsByPsId = {};
}

async function suggestCategoryMappingsForCurrentCategoryProducts() {
  const psCategoryId = Number(currentCategoryProductsPsCategoryId || 0);
  const result = document.getElementById('category-products-assign-result');
  if (psCategoryId <= 0) return;
  const genderFilter = (document.getElementById('map-gender-filter')?.value || '').toLowerCase().trim();
  if (result) {
    result.textContent = 'Suggesting...';
    result.style.color = 'var(--muted)';
  }
  const r = await api('category_products_suggest_mappings', {
    ps_category_id: psCategoryId,
    gender_filter: genderFilter,
  }, 'POST');
  if (!r.ok || !r.data.ok) {
    if (result) {
      result.textContent = r.data?.error || 'Suggestion failed';
      result.style.color = 'var(--red)';
    }
    return;
  }
  const rows = r.data?.data?.rows || [];
  currentCategorySuggestionsByPsId = Object.fromEntries(rows.map(row => [String(row.ps_id), row]));
  renderCategoryProductsList();
  const matched = rows.filter(row => Number(row?.suggested?.id || 0) > 0).length;
  if (result) {
    result.textContent = `Suggested ${matched}/${rows.length} products`;
    result.style.color = 'var(--green)';
  }
}

async function assignAyCategoryToSingleProduct(psId, ayCategoryId) {
  const result = document.getElementById('category-products-assign-result');
  const r = await api('product_assign_ay_category', {
    ps_id: psId,
    ay_category_id: ayCategoryId,
  });
  if (!r.ok || !r.data.ok) {
    if (result) {
      result.textContent = r.data?.error || 'Product assignment failed';
      result.style.color = 'var(--red)';
    }
    return;
  }
  currentCategoryProductsRows = currentCategoryProductsRows.map(item => (
    Number(item.ps_id) === Number(psId) ? { ...item, ay_category_id: ayCategoryId } : item
  ));
  if (result) {
    result.textContent = `Assigned AY#${ayCategoryId} to product PS#${psId}`;
    result.style.color = 'var(--green)';
  }
  toast(`Product PS#${psId} assigned to AY#${ayCategoryId}`, 'ok');
  renderCategoryProductsList();
}

async function assignAyCategoryToCurrentCategoryProducts() {
  const psCategoryId = Number(currentCategoryProductsPsCategoryId || 0);
  if (psCategoryId <= 0) {
    toast('Open a category product list first', 'err');
    return;
  }
  const ayCategoryInput = document.getElementById('category-products-ay-category-id');
  const result = document.getElementById('category-products-assign-result');
  const ayCategoryId = Number(ayCategoryInput?.value || 0);
  if (ayCategoryId <= 0) {
    if (result) {
      result.textContent = 'Enter a valid AY Category ID';
      result.style.color = 'var(--red)';
    }
    return;
  }
  if (result) {
    result.textContent = 'Assigning...';
    result.style.color = 'var(--muted)';
  }
  const r = await api('category_products_assign_ay_category', {
    ps_category_id: psCategoryId,
    ay_category_id: ayCategoryId,
  });
  if (!r.ok || !r.data.ok) {
    if (result) {
      result.textContent = r.data?.error || 'Assignment failed';
      result.style.color = 'var(--red)';
    }
    return;
  }
  const updated = Number(r.data?.data?.updated || 0);
  if (result) {
    result.textContent = `Assigned AY#${ayCategoryId} to ${updated} products`;
    result.style.color = 'var(--green)';
  }
  toast(`Assigned AY#${ayCategoryId} to ${updated} products`, 'ok');
  await loadCategoryMappings();
}

function applyCategoryMapping(psCategoryId, mapping) {
  categoryMappingsDraft[String(psCategoryId)] = { id: Number(mapping.id), path: String(mapping.path || '') };
  renderCategoryMappings();
  toast(`Mapped PS category ${psCategoryId} to AY#${mapping.id}`, 'ok');
}

function wireMappingsPage() {
  document.getElementById('map-search').addEventListener('input', renderCategoryMappings);
  document.getElementById('map-gender-filter').addEventListener('change', () => {
    toast('Gender path filter applied to next AY searches', 'info');
  });
  document.getElementById('map-refresh').addEventListener('click', loadCategoryMappings);
  document.getElementById('map-validate-selected').addEventListener('click', validateSelectedCategoryMapping);
  document.getElementById('category-products-close').addEventListener('click', closeCategoryProductsModal);
  document.getElementById('category-products-assign-btn').addEventListener('click', assignAyCategoryToCurrentCategoryProducts);
  document.getElementById('category-products-suggest-btn').addEventListener('click', suggestCategoryMappingsForCurrentCategoryProducts);
  document.getElementById('category-products-modal').addEventListener('click', (event) => {
    if (event.target?.id === 'category-products-modal') {
      closeCategoryProductsModal();
    }
  });
  document.getElementById('map-save').addEventListener('click', async () => {
    const el = document.getElementById('map-save-result');
    el.textContent = 'Saving...';
    const r = await api('category_mappings_save', { mappings: categoryMappingsDraft });
    if (r.ok && r.data.ok) {
      el.textContent = `Saved ${r.data.data.saved} mappings`;
      el.style.color = 'var(--green)';
      toast('Category mappings saved', 'ok');
      loadSettings();
    } else {
      el.textContent = 'Save failed';
      el.style.color = 'var(--red)';
      toast('Category mapping save failed', 'err');
    }
  });
}
