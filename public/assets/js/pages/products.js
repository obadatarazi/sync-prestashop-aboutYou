'use strict';

let productCategoryDrawerState = {
  mode: 'single',
  psIds: [],
  selected: null,
  psCategoryName: '',
  psCategoryId: 0,
  psCategoryPath: '',
  psCategories: [],
};
let productCategorySearchDebounceTimer = null;
let productRowsByPsId = {};
let missingGroupOptionsCache = {};

function formatProductReason(product) {
  const reason = (product?.readiness_reason || product?.comparison_reason || product?.sync_error || '').trim();
  if (reason !== '') return reason;
  if ((product?.sync_status || '') === 'synced') return 'Synced on AboutYou';
  return 'Needs sync review';
}

function normalizeReasonCode(value) {
  return String(value || '').trim().toLowerCase();
}

function extractReasonCodeFromText(text) {
  const raw = String(text || '');
  const match = raw.match(/\[reason=([a-z0-9_]+)\]/i);
  return normalizeReasonCode(match?.[1] || '');
}

function reasonHint(reasonCode) {
  const code = normalizeReasonCode(reasonCode);
  if (code === '') return '';
  if (['missing_ean', 'invalid_ean', 'duplicate_ean', 'duplicate_ean_external'].includes(code)) return 'EAN conflict detected. Update duplicate EAN(s) in "Live PrestaShop Variants", save, then re-sync.';
  if (['invalid_color', 'invalid_size', 'missing_required_group'].includes(code)) return 'Open "Color & Size Mapping", map missing values, then re-sync.';
  if (['missing_images', 'too_many_images'].includes(code)) return 'Check product images and retry failed images before re-sync.';
  if (['missing_required_text', 'invalid_material_shape', 'invalid_material_fraction'].includes(code)) return 'Review export title/description/material fields in Product Detail.';
  if (['invalid_style_key', 'missing_style_key'].includes(code)) return 'Set or regenerate AY style key and sync again.';
  if (code === 'missing_category_metadata') return 'Set AY category mapping and verify AY metadata access.';
  if (['ay_auth', 'auth'].includes(code)) return 'Check AboutYou API credentials and permissions.';
  if (['ay_rate_limit', 'transport_rate_limited'].includes(code)) return 'Retry later or reduce sync batch size/rate.';
  if (code === 'ay_validation') return 'Open Product Detail, fill missing AY payload fields, then retry.';
  if (['transport_timeout', 'transport_network', 'transport_unknown'].includes(code)) return 'Temporary transport failure. Retry sync for this product.';
  return 'Open product detail, fix mapped data, then retry sync.';
}

function productHint(product) {
  const reasonText = formatProductReason(product).toLowerCase();
  if (reasonText.includes('category cannot be changed for existing product')) {
    return 'AY rejects category change on existing published master. Use a new style key (new master) or switch AY product to draft/rejected before changing category.';
  }
  if (reasonText.includes('size not found')) {
    return 'Mapped sizes exist locally, but AY rejected one or more size IDs for this category. Re-open mapping and verify AY option IDs for current category.';
  }
  const code = normalizeReasonCode(product?.last_error_reason_code || extractReasonCodeFromText(formatProductReason(product)));
  return reasonHint(code);
}

function parseJsonObjectSafe(value) {
  try {
    const parsed = JSON.parse(String(value || '{}'));
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch (e) {
    return {};
  }
}

async function loadProducts() {
  const tbody = document.getElementById('products-body');
  tbody.innerHTML = '<tr><td colspan="12" class="loading"><span class="spin">⟳</span></td></tr>';
  refreshBulkSelectionUi();
  productRowsByPsId = {};
  const search = document.getElementById('prod-search')?.value || '';
  if (productsStatus === 'compare') {
    await loadProductsComparison(search);
    return;
  }
  toggleProductsComparisonUi(false);
  productsCompareRows = [];
  const r = await api('products', {
    page: productsPage, per_page: 20,
    status: productsStatus, search
  });
  if (!r.ok || !r.data.ok) { tbody.innerHTML = '<tr><td colspan="12" style="color:var(--red);padding:14px;">Failed to load</td></tr>'; refreshBulkSelectionUi(); return; }
  const { rows, total, page, per_page } = r.data.data;
  productRowsByPsId = Object.fromEntries((rows || []).map((p) => [String(p.ps_id), p]));
  document.getElementById('products-subtitle').textContent = `${total} products`;
  document.getElementById('products-page-info').textContent = `Page ${page} · ${total} total`;
  document.getElementById('products-prev').disabled = page <= 1;
  document.getElementById('products-next').disabled = page * per_page >= total;

  if (!rows.length) { tbody.innerHTML = '<tr><td colspan="12" style="color:var(--muted);padding:14px;text-align:center;">No products found</td></tr>'; refreshBulkSelectionUi(); return; }

  tbody.innerHTML = rows.map(p => `
    <tr onclick="viewProduct(${p.ps_id})" style="cursor:pointer;">
      <td><input type="checkbox" class="pc" data-psid="${p.ps_id}" style="accent-color:var(--accent)" onclick="event.stopPropagation()"></td>
      <td><div style="display:flex;align-items:center;gap:9px;">
        <div class="img-thumb">${renderProductThumbCell(p)}</div>
        <div><div style="font-size:12.5px;font-weight:500;">${esc(p.name||'—')}</div>
             <div class="mono">PS#${p.ps_id}</div></div></div></td>
      <td class="mono">${esc(p.reference||'—')}</td>
      <td><span class="badge b-info">${p.variant_count||0} vars</span></td>
      <td>€${parseFloat(p.price||0).toFixed(2)}</td>
      <td><span class="badge ${(p.image_count||0)>0 ? 'b-ok':'b-err'}">${p.image_count||0} img</span></td>
      <td class="mono" style="color:var(--accent2)">${esc(p.ay_style_key||'—')}</td>
      <td title="${esc(p.ay_category_path || '')}">
        ${Number(p.ay_category_id || 0) > 0
          ? `<div class="mono">AY#${esc(p.ay_category_id)}</div><div style="font-size:11.5px;color:var(--muted);line-height:1.3;">${esc(p.ay_category_path || 'Mapped')}</div>`
          : '—'}
      </td>
      <td>${statusBadge(p.sync_status)}</td>
      <td class="mono">${(p.last_synced_at||'—').slice(0,10)}</td>
      <td style="max-width:320px;">
        <div class="mono" style="white-space:normal;line-height:1.3;color:var(--muted);">${esc(formatProductReason(p))}</div>
        ${productHint(p) ? `<div style="margin-top:4px;font-size:11px;line-height:1.3;color:var(--accent2);">${esc(productHint(p))}</div>` : ''}
      </td>
      <td>
        <div style="display:flex;gap:6px;">
          <button class="btn btn-sm" onclick="event.stopPropagation();viewProduct(${p.ps_id})">View</button>
          <button class="btn btn-sm" onclick="event.stopPropagation();openProductCategoryDrawerForSingle(${p.ps_id}, ${Number(p.ay_category_id || 0)})">Category</button>
        </div>
      </td>
    </tr>`).join('');
  bindProductRowSelectionHandlers();
  refreshBulkSelectionUi();
}

function toggleProductsComparisonUi(enabled) {
  const tools = document.getElementById('products-compare-tools');
  const summary = document.getElementById('products-compare-summary');
  if (!tools || !summary) return;
  tools.style.display = enabled ? 'flex' : 'none';
  summary.style.display = enabled ? 'grid' : 'none';
  if (!enabled) {
    summary.innerHTML = '';
    document.getElementById('compare-last-run').textContent = '';
  }
}

function renderProductsComparisonSummary(summary = {}) {
  const wrap = document.getElementById('products-compare-summary');
  if (!wrap) return;
  const total = Number(summary.total || 0);
  const synced = Number(summary.synced || 0);
  const notSynced = Number(summary.not_synced || 0);
  const errors = Number(summary.errors || 0);
  const missingKey = Number(summary.missing_style_key || 0);
  wrap.innerHTML = `
    <div class="sc blue"><div class="sl">Total</div><div class="sv">${total}</div><div class="ss">products</div></div>
    <div class="sc green"><div class="sl">Synced</div><div class="sv">${synced}</div><div class="ss">on AboutYou</div></div>
    <div class="sc amber"><div class="sl">Not Synced</div><div class="sv">${notSynced}</div><div class="ss">needs action</div></div>
    <div class="sc red"><div class="sl">Issues</div><div class="sv">${errors}</div><div class="ss">${missingKey} missing style key</div></div>
  `;
}

async function loadProductsComparison(search = '') {
  const tbody = document.getElementById('products-body');
  toggleProductsComparisonUi(true);
  tbody.innerHTML = '<tr><td colspan="12" class="loading"><span class="spin">⟳</span></td></tr>';
  refreshBulkSelectionUi();
  productRowsByPsId = {};
  const r = await api('products_compare', {
    page: productsPage,
    per_page: 20,
    bucket: productsCompareBucket,
    search,
  });
  if (!r.ok || !r.data.ok) {
    tbody.innerHTML = '<tr><td colspan="12" style="color:var(--red);padding:14px;">Failed to load comparison</td></tr>';
    refreshBulkSelectionUi();
    return;
  }
  const { rows = [], total = 0, page = 1, per_page = 20, summary = {} } = r.data.data || {};
  productsCompareRows = rows;
  productRowsByPsId = Object.fromEntries((rows || []).map((p) => [String(p.ps_id), p]));
  renderProductsComparisonSummary(summary);
  const bucketLabel = productsCompareBucket === 'synced' ? 'Synced' : 'Not Synced';
  document.getElementById('products-subtitle').textContent = `Comparison mode · ${summary.synced || 0} synced · ${summary.not_synced || 0} not synced`;
  document.getElementById('products-page-info').textContent = `${bucketLabel} · Page ${page} · ${total} total`;
  document.getElementById('products-prev').disabled = page <= 1;
  document.getElementById('products-next').disabled = page * per_page >= total;

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="12" style="color:var(--muted);padding:14px;text-align:center;">No products in this bucket</td></tr>';
    refreshBulkSelectionUi();
    return;
  }

  tbody.innerHTML = rows.map(p => `
    <tr onclick="viewProduct(${p.ps_id})" style="cursor:pointer;">
      <td><input type="checkbox" class="pc" data-psid="${p.ps_id}" style="accent-color:var(--accent)" onclick="event.stopPropagation()"></td>
      <td><div style="display:flex;align-items:center;gap:9px;">
        <div class="img-thumb">${renderProductThumbCell(p)}</div>
        <div><div style="font-size:12.5px;font-weight:500;">${esc(p.name||'—')}</div>
             <div class="mono">PS#${p.ps_id}</div></div></div></td>
      <td class="mono">${esc(p.reference||'—')}</td>
      <td><span class="badge b-info">${p.variant_count||0} vars</span></td>
      <td>€${parseFloat(p.price||0).toFixed(2)}</td>
      <td><span class="badge ${(p.image_count||0)>0 ? 'b-ok':'b-err'}">${p.image_count||0} img</span></td>
      <td class="mono" style="color:var(--accent2)">${esc(p.ay_style_key||'—')}</td>
      <td title="${esc(p.ay_category_path || '')}">
        ${Number(p.ay_category_id || 0) > 0
          ? `<div class="mono">AY#${esc(p.ay_category_id)}</div><div style="font-size:11.5px;color:var(--muted);line-height:1.3;">${esc(p.ay_category_path || 'Mapped')}</div>`
          : '—'}
      </td>
      <td>${statusBadge(p.sync_status)}</td>
      <td class="mono">${(p.last_synced_at||'—').slice(0,10)}</td>
      <td style="max-width:320px;">
        <div class="mono" style="white-space:normal;line-height:1.3;color:var(--muted);">${esc(formatProductReason(p))}</div>
        ${productHint(p) ? `<div style="margin-top:4px;font-size:11px;line-height:1.3;color:var(--accent2);">${esc(productHint(p))}</div>` : ''}
      </td>
      <td>
        <div style="display:flex;gap:6px;">
          <button class="btn btn-sm" onclick="event.stopPropagation();viewProduct(${p.ps_id})">View</button>
          <button class="btn btn-sm" onclick="event.stopPropagation();openProductCategoryDrawerForSingle(${p.ps_id}, ${Number(p.ay_category_id || 0)})">Category</button>
        </div>
      </td>
    </tr>`).join('');
  bindProductRowSelectionHandlers();
  refreshBulkSelectionUi();
}

function selectedProductIds() {
  return [...document.querySelectorAll('.pc:checked')].map(c => Number(c.dataset.psid || 0)).filter(Boolean);
}

function refreshBulkSelectionUi() {
  const selected = selectedProductIds();
  const bulkBtn = document.getElementById('assign-category-selected-btn');
  const exportBtn = document.getElementById('export-csv-btn');
  if (!bulkBtn) return;
  const count = selected.length;
  bulkBtn.textContent = count > 0
    ? `🏷 Assign AY Category (${count})`
    : '🏷 Assign AY Category';
  bulkBtn.disabled = count <= 0;
  if (exportBtn) {
    exportBtn.textContent = count > 0
      ? `🧾 Export Selected CSV + Payload (${count})`
      : '🧾 Export CSV + Payload';
  }
}

function bindProductRowSelectionHandlers() {
  document.querySelectorAll('.pc').forEach((box) => {
    box.addEventListener('change', refreshBulkSelectionUi);
  });
}

function renderAyPathChips(path, isCurrent = false) {
  const text = String(path || '').trim();
  if (text === '') {
    return '<span class="path-chip leaf">Unknown category</span>';
  }
  const separators = /\s*(?:>|\/|\|)\s*/g;
  const parts = text.split(separators).map((part) => part.trim()).filter(Boolean);
  if (!parts.length) {
    return `<span class="path-chip leaf ${isCurrent ? 'current' : ''}">${esc(text)}</span>`;
  }
  const leafIndex = parts.length - 1;
  return parts.map((part, idx) => {
    const classes = ['path-chip'];
    if (idx === leafIndex) classes.push('leaf');
    if (isCurrent) classes.push('current');
    return `<span class="${classes.join(' ')}">${esc(part)}</span>`;
  }).join('');
}

async function openProductCategoryDrawerForSingle(psId, initialAyCategoryId = 0) {
  const ayId = Number(initialAyCategoryId || 0);
  const row = productRowsByPsId[String(psId)] || null;
  const knownPath = String(row?.ay_category_path || '').trim();
  const psCategoryName = String(row?.category_name || '').trim();
  const psCategoryId = Number(row?.category_ps_id || 0);
  productCategoryDrawerState = {
    mode: 'single',
    psIds: [Number(psId)],
    selected: ayId > 0
      ? { id: ayId, path: knownPath || `AY#${ayId}` }
      : null,
    psCategoryName,
    psCategoryId,
    psCategoryPath: '',
    psCategories: [],
  };
  if (psCategoryId > 0) {
    const psPathResp = await api('ps_category_path', { ps_category_id: psCategoryId }, 'POST');
    if (psPathResp.ok && psPathResp.data?.ok) {
      productCategoryDrawerState.psCategoryPath = String(psPathResp.data?.data?.path || '').trim();
      if (!productCategoryDrawerState.psCategoryName) {
        productCategoryDrawerState.psCategoryName = String(psPathResp.data?.data?.name || '').trim();
      }
    }
  }
  const psCatsResp = await api('ps_product_categories', { ps_id: Number(psId) }, 'POST');
  if (psCatsResp.ok && psCatsResp.data?.ok) {
    productCategoryDrawerState.psCategories = Array.isArray(psCatsResp.data?.data?.items)
      ? psCatsResp.data.data.items
      : [];
  }
  openProductCategoryDrawer(`Product PS#${psId}`);
  await renderCurrentCategoryLabel();
}

async function openProductCategoryDrawerForBulk() {
  const psIds = selectedProductIds();
  if (!psIds.length) {
    toast('Select at least one product', 'err');
    return;
  }
  productCategoryDrawerState = {
    mode: 'bulk',
    psIds,
    selected: null,
    psCategoryName: '',
    psCategoryId: 0,
    psCategoryPath: '',
    psCategories: [],
  };
  openProductCategoryDrawer(`${psIds.length} selected product(s)`);
  await renderCurrentCategoryLabel();
}

function openProductCategoryDrawer(scopeLabel) {
  const drawer = document.getElementById('product-category-drawer');
  const backdrop = document.getElementById('product-category-drawer-backdrop');
  const subtitle = document.getElementById('product-category-drawer-subtitle');
  const searchInput = document.getElementById('product-category-search');
  const clearBtn = document.getElementById('product-category-search-clear');
  const genderFilter = document.getElementById('product-category-gender-filter');
  const results = document.getElementById('product-category-results');
  const psCurrent = document.getElementById('product-category-ps-current');
  const suggestionWrap = document.getElementById('product-category-suggestion');
  if (!drawer || !backdrop || !subtitle || !searchInput || !results || !genderFilter || !psCurrent || !clearBtn || !suggestionWrap) return;
  subtitle.textContent = productCategoryDrawerState.mode === 'bulk'
    ? `Bulk assign to ${scopeLabel}`
    : `Assign AY category for ${scopeLabel}`;
  if (productCategoryDrawerState.mode === 'single') {
    const name = String(productCategoryDrawerState.psCategoryName || '').trim();
    const psCategoryId = Number(productCategoryDrawerState.psCategoryId || 0);
    const path = String(productCategoryDrawerState.psCategoryPath || '').trim();
    const productCats = Array.isArray(productCategoryDrawerState.psCategories)
      ? productCategoryDrawerState.psCategories
      : [];
    if (productCats.length) {
      psCurrent.innerHTML = productCats.map((cat) => {
        const catId = Number(cat?.id || 0);
        const catPath = String(cat?.path || cat?.name || '').trim();
        const isDefault = !!cat?.is_default;
        return `
          <div style="margin-bottom:6px;">
            <div class="path-chip-wrap">${renderAyPathChips(catPath || `PS Category #${catId}`)}</div>
            <div class="mono" style="margin-top:4px;">
              PS Category #${esc(catId)}${isDefault ? ' · default' : ''}
            </div>
          </div>
        `;
      }).join('');
    } else if (path && psCategoryId > 0) {
      psCurrent.textContent = `${path} (PS Category #${psCategoryId})`;
    } else if (path) {
      psCurrent.textContent = path;
    } else if (name && psCategoryId > 0) {
      psCurrent.textContent = `${name} (PS Category #${psCategoryId})`;
    } else if (name) {
      psCurrent.textContent = name;
    } else if (psCategoryId > 0) {
      psCurrent.textContent = `PS Category #${psCategoryId}`;
    } else {
      psCurrent.textContent = 'Not available';
    }
  } else {
    psCurrent.textContent = 'Multiple products selected';
  }
  suggestionWrap.textContent = productCategoryDrawerState.mode === 'single'
    ? 'Loading suggestion...'
    : 'Suggestion available for single-product assignment';
  searchInput.value = '';
  clearBtn.style.display = 'none';
  genderFilter.value = '';
  results.innerHTML = '<div class="loading" style="padding:12px;"><span class="spin">⟳</span> Loading categories...</div>';
  backdrop.style.display = 'block';
  drawer.classList.add('open');
  drawer.setAttribute('aria-hidden', 'false');
  if (productCategoryDrawerState.mode === 'single') {
    loadDrawerCategorySuggestion();
  }
  searchAyCategoriesForDrawer(true);
}

function closeProductCategoryDrawer() {
  const drawer = document.getElementById('product-category-drawer');
  const backdrop = document.getElementById('product-category-drawer-backdrop');
  if (!drawer || !backdrop) return;
  drawer.classList.remove('open');
  drawer.setAttribute('aria-hidden', 'true');
  backdrop.style.display = 'none';
}

async function fetchDrawerCategoriesFromCatalog(query, genderFilter) {
  return api('ay_categories_catalog', {
    query: String(query || ''),
    gender_filter: String(genderFilter || ''),
    limit: 220,
  }, 'POST');
}

async function renderCurrentCategoryLabel() {
  const current = document.getElementById('product-category-current');
  if (!current) return;
  const selected = productCategoryDrawerState.selected;
  if (!selected) {
    current.textContent = 'No AY category selected';
    return;
  }
  current.innerHTML = `
    <div class="path-chip-wrap">${renderAyPathChips(selected.path || ('AY#' + selected.id), true)}</div>
    <div class="mono" style="margin-top:5px;">AY#${esc(selected.id)}</div>
  `;
}

async function searchAyCategoriesForDrawer(allowEmpty = false) {
  const rawQuery = document.getElementById('product-category-search')?.value || '';
  const genderFilter = document.getElementById('product-category-gender-filter')?.value || '';
  const results = document.getElementById('product-category-results');
  if (!results) return;
  if (rawQuery.trim() === '' && !allowEmpty) {
    results.innerHTML = '<div class="loading" style="padding:12px;">Type to filter categories</div>';
    return;
  }
  results.innerHTML = '<div class="loading" style="padding:12px;"><span class="spin">⟳</span> Searching...</div>';
  let r = await fetchDrawerCategoriesFromCatalog(rawQuery.trim(), genderFilter);
  if (!r.ok || !r.data?.ok) {
    results.innerHTML = `<div style="color:var(--red);font-size:12px;">${esc(r.data?.error || 'Category catalog lookup failed')}</div>`;
    return;
  }
  let items = (r.data.data?.items || []).slice(0, 25);
  // Empty catalog fallback: sync once from AY, then retry catalog query.
  if (!items.length && !r.data.data?.last_sync_at) {
    const sync = await api('ay_categories_catalog_sync', {}, 'POST');
    if (sync.ok && sync.data?.ok) {
      r = await fetchDrawerCategoriesFromCatalog(rawQuery.trim(), genderFilter);
      if (r.ok && r.data?.ok) {
        items = (r.data.data?.items || []).slice(0, 25);
      }
    }
  }
  if (!items.length) {
    results.innerHTML = `<div class="loading" style="padding:12px;">${
      genderFilter ? `No categories found for "${esc(genderFilter)}"` : 'No categories found'
    }</div>`;
    return;
  }
  const selectedId = Number(productCategoryDrawerState.selected?.id || 0);
  results.innerHTML = items.map((item) => {
    const itemId = Number(item.id || 0);
    const active = selectedId > 0 && selectedId === itemId;
    const path = String(item.path || item.name || `AY#${itemId}`);
    return `<div class="drawer-option ${active ? 'active' : ''}" data-ay-id="${itemId}" data-ay-path="${encodeURIComponent(path)}">
      <div style="min-width:0;">
        <div class="path-chip-wrap">${renderAyPathChips(path)}</div>
        <div class="drawer-option-meta">AY#${itemId}</div>
      </div>
      ${active ? '<span class="badge b-ok">Selected</span>' : '<span class="badge b-gray">Use</span>'}
    </div>`;
  }).join('');
  results.querySelectorAll('.drawer-option').forEach((el) => {
    el.addEventListener('click', () => {
      const ayId = Number(el.dataset.ayId || 0);
      const ayPath = decodeURIComponent(String(el.dataset.ayPath || ''));
      if (ayId <= 0) return;
      productCategoryDrawerState.selected = { id: ayId, path: ayPath };
      renderCurrentCategoryLabel();
      searchAyCategoriesForDrawer();
    });
  });
}

async function loadDrawerCategorySuggestion() {
  const suggestionWrap = document.getElementById('product-category-suggestion');
  if (!suggestionWrap) return;
  const psId = Number(productCategoryDrawerState.psIds?.[0] || 0);
  if (psId <= 0 || productCategoryDrawerState.mode !== 'single') {
    suggestionWrap.textContent = 'Suggestion available for single-product assignment';
    return;
  }
  const genderFilter = document.getElementById('product-category-gender-filter')?.value || '';
  const r = await api('ay_category_suggest_for_product', {
    ps_id: psId,
    gender_filter: String(genderFilter || ''),
  }, 'POST');
  if (!r.ok || !r.data?.ok) {
    suggestionWrap.innerHTML = `<span style="color:var(--amber);">${esc(r.data?.error || 'Suggestion unavailable')}</span>`;
    return;
  }
  const suggested = r.data.data?.suggested || null;
  const confidence = Number(r.data.data?.confidence || 0);
  if (!suggested || Number(suggested.id || 0) <= 0) {
    suggestionWrap.textContent = 'No strong suggestion for this product';
    return;
  }
  const path = String(suggested.path || `AY#${suggested.id}`);
  suggestionWrap.innerHTML = `
    <div class="path-chip-wrap">${renderAyPathChips(path)}</div>
    <div style="display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;">
      <span class="mono">AY#${esc(suggested.id)} · confidence ${Math.round(confidence * 100)}%</span>
      <button class="btn btn-sm" id="product-category-suggest-use">Use suggestion</button>
    </div>
    <div style="margin-top:5px;font-size:11px;color:var(--muted);">
      You can use <strong>${esc(path)}</strong> as a category for this product.
    </div>
  `;
  document.getElementById('product-category-suggest-use')?.addEventListener('click', () => {
    productCategoryDrawerState.selected = { id: Number(suggested.id), path };
    renderCurrentCategoryLabel();
    searchAyCategoriesForDrawer(true);
  });
}

async function saveProductCategoryFromDrawer() {
  const selected = productCategoryDrawerState.selected;
  if (!selected || Number(selected.id || 0) <= 0) {
    toast('Select an AY category first', 'err');
    return;
  }
  const psIds = productCategoryDrawerState.psIds || [];
  if (!psIds.length) {
    toast('No products selected', 'err');
    return;
  }
  if (productCategoryDrawerState.mode === 'bulk') {
    const r = await api('product_assign_ay_category_bulk', {
      ps_ids: psIds,
      ay_category_id: Number(selected.id),
      ay_category_path: String(selected.path || ''),
    });
    if (!r.ok || !r.data?.ok) {
      toast(r.data?.error || 'Bulk category assignment failed', 'err');
      return;
    }
    toast(`Assigned AY#${selected.id} to ${Number(r.data.data?.updated || 0)} products`, 'ok');
  } else {
    const r = await api('product_assign_ay_category', {
      ps_id: Number(psIds[0]),
      ay_category_id: Number(selected.id),
      ay_category_path: String(selected.path || ''),
    });
    if (!r.ok || !r.data?.ok) {
      toast(r.data?.error || 'Category assignment failed', 'err');
      return;
    }
    toast(`Assigned AY#${selected.id} to product PS#${Number(psIds[0])}`, 'ok');
  }
  closeProductCategoryDrawer();
  await loadProducts();
}

async function recheckProductsOnAboutYou() {
  const selected = [...document.querySelectorAll('.pc:checked')].map(c => Number(c.dataset.psid || 0)).filter(Boolean);
  const fallbackIds = (productsCompareRows || []).map(row => Number(row.ps_id || 0)).filter(Boolean);
  const psIds = selected.length ? selected : fallbackIds;
  if (!psIds.length) {
    toast('No products to recheck in current comparison view', 'err');
    return;
  }
  const r = await api('products_recheck_ay', { ps_product_ids: psIds.slice(0, 100) });
  if (!r.ok || !r.data.ok) {
    toast(r.data?.error || 'Recheck failed', 'err');
    return;
  }
  const summary = r.data.data?.summary || {};
  document.getElementById('compare-last-run').textContent = `Last recheck ${now()} · ${summary.synced || 0} synced · ${summary.not_synced || 0} not synced`;
  toast(`Rechecked ${summary.checked || 0} products`, 'ok');
  await loadProducts();
  await loadDashboard();
}

async function exportProductsCsvWithPayload() {
  const search = document.getElementById('prod-search')?.value || '';
  const selectedIds = selectedProductIds();
  const r = await api('products_export_csv', {
    status: productsStatus,
    search,
    bucket: productsCompareBucket,
    ps_product_ids: selectedIds,
  });
  if (!r.ok || !r.data?.ok) {
    toast(r.data?.error || 'CSV export failed', 'err');
    return;
  }
  const count = Number(r.data?.data?.count || 0);
  const selectedCount = Number(r.data?.data?.selected_count || 0);
  const file = String(r.data?.data?.file || '');
  toast(
    selectedCount > 0
      ? `Exported ${count} selected product(s) to CSV`
      : `Exported ${count} product(s) to CSV`,
    'ok'
  );
  if (file) {
    toast(file, 'info');
  }
}

async function viewProduct(psId) {
  const loadToken = ++productDetailLoadToken;
  const quick = await api('product_detail', { product_id: psId, include_remote: false });
  if (!quick.ok || !quick.data.ok) { toast('Failed to load product', 'err'); return; }
  currentProductDetail = { ...quick.data.data, remote_pending: true };
  renderProductDetail(currentProductDetail);

  const full = await api('product_detail', { product_id: psId, include_remote: true });
  if (loadToken !== productDetailLoadToken || !currentProductDetail?.product || Number(currentProductDetail.product.ps_id) !== Number(psId)) {
    return;
  }
  if (!full.ok || !full.data.ok) {
    currentProductDetail = { ...currentProductDetail, remote_pending: false, ps_fetch_error: full.data?.error || 'Failed to load live PrestaShop/AboutYou metadata' };
    renderProductDetail(currentProductDetail);
    return;
  }
  currentProductDetail = { ...full.data.data, remote_pending: false };
  renderProductDetail(currentProductDetail);
}

function closeProductDetail() {
  productDetailLoadToken++;
  currentProductDetail = null;
  document.getElementById('products-detail-view').style.display = 'none';
  document.getElementById('products-list-view').style.display = '';
}

function renderProductDetail(d) {
  const wrap = document.getElementById('products-detail-view');
  const list = document.getElementById('products-list-view');
  const p = d.product || {};
  const ps = d.ps_product || {};
  const images = d.images || [];
  const combos = d.ps_combinations || [];
  const logs = d.logs || [];
  const errorHistory = d.error_history || [];
  const attrRows = d.attribute_rows || [];
  const colorOptions = d.ay_options?.color || [];
  const sizeOptions = d.ay_options?.size || [];
  const categoryName = d.ps_category ? esc(d.ps_category.name?.[0]?.value || d.ps_category.name || '') : esc(p.category_name || '—');
  const exportTitle = esc(p.export_title || p.name || '');
  const exportDescription = esc(p.export_description || p.description || '');
  const psApiPayload = esc(p.ps_api_payload || '');
  const missingPayload = parseJsonObjectSafe(p.ay_missing_payload_json || '{}');
  const manualRequiredMap = parseJsonObjectSafe(p.ay_manual_required_attributes_json || '{}');
  const manualRequiredRaw = esc(p.ay_manual_required_attributes_json || '{}');
  const missingRequiredGroups = Array.isArray(missingPayload.required_groups) ? missingPayload.required_groups : [];
  const missingSizeText = missingPayload.size_not_found ? '<span class="badge b-err">Size not found in AY payload validation</span>' : '';

  const optionMarkup = (opts, selected) => [`<option value="">Not mapped</option>`].concat(
    opts.map(o => `<option value="${o.id}" ${Number(selected||0)===Number(o.id)?'selected':''}>${esc(o.label)}</option>`)
  ).join('');
  const sizeOptionIds = new Set((sizeOptions || []).map((o) => Number(o.id || 0)).filter((id) => id > 0));
  const colorOptionIds = new Set((colorOptions || []).map((o) => Number(o.id || 0)).filter((id) => id > 0));
  const invalidMapRows = (attrRows || []).filter((row) => {
    const ayId = Number(row?.ay_id || 0);
    if (ayId <= 0) return false;
    if (row?.map_type === 'size') return !sizeOptionIds.has(ayId);
    if (row?.map_type === 'color') return !colorOptionIds.has(ayId);
    return false;
  });

  wrap.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap;">
      <div>
        <button class="btn btn-sm" id="product-detail-back">← Back to products</button>
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-sm" id="product-detail-auto-map">Auto-map color/size</button>
        <button class="btn btn-sm" id="product-detail-push">Push This Product</button>
      </div>
    </div>
    <div class="sg" style="grid-template-columns:repeat(4,1fr);">
      <div class="sc blue"><div class="sl">Product</div><div class="sv" style="font-size:18px">${esc(p.name || '—')}</div><div class="ss">PS#${esc(p.ps_id || '—')} · ${esc(p.reference || '—')}</div></div>
      <div class="sc green"><div class="sl">Variants</div><div class="sv">${combos.length || d.variants?.length || 0}</div><div class="ss">live combinations</div></div>
      <div class="sc amber"><div class="sl">Images</div><div class="sv">${images.length}</div><div class="ss">${images.filter(i => i.status === 'ok').length} processed</div></div>
      <div class="sc red"><div class="sl">Status</div><div class="sv" style="font-size:18px">${esc(p.sync_status || 'pending')}</div><div class="ss">${esc(p.sync_error || 'Ready for review')}</div></div>
    </div>
    <div class="g2">
      <div class="card">
        <div class="ch"><div><div class="ct">Export Details</div><div class="cs">Edit the outgoing AY payload without touching PrestaShop</div></div></div>
        <div class="fr"><label class="fl">Export Title</label><input class="fi" id="pd-export-title" value="${exportTitle}"></div>
        <div class="fr"><label class="fl">Export Description</label><textarea class="fi" id="pd-export-description" rows="7">${exportDescription}</textarea></div>
        <div class="fr"><label class="fl">PrestaShop API Payload (JSON)</label><textarea class="fi" id="pd-ps-api-payload" rows="7" placeholder='{"id":123,"reference":"ABC-123"}'>${psApiPayload}</textarea></div>
        <div class="fr">
          <label class="fl">Manual AY Required Attributes JSON</label>
          <textarea class="fi" id="pd-manual-required-attributes" rows="5" placeholder='{"1712": 555001, "1400": 889900}'>${manualRequiredRaw}</textarea>
          <div style="margin-top:6px;font-size:11px;color:var(--muted);line-height:1.3;">Format: <span class="mono">{group_id: ay_attribute_id}</span>. These values are injected into payload for required AY groups per product.</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
            <button class="btn btn-sm" id="pd-fill-required-from-defaults">Fill from category defaults</button>
            <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted);">
              <input type="checkbox" id="pd-fill-required-overwrite" style="accent-color:var(--accent)">
              Overwrite existing
            </label>
          </div>
        </div>
        <div class="fr">
          <label class="fl">Latest Missing AY Payload Fields</label>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
            <button class="btn btn-sm" id="pd-autofill-missing-required" ${missingRequiredGroups.length ? '' : 'disabled'}>Auto-fill Missing Attributes</button>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;">
            ${missingSizeText || ''}
            ${missingRequiredGroups.length
              ? missingRequiredGroups.map((group) => {
                const gid = Number(group.group_id || 0);
                const gname = String(group.group_name || ('group_' + gid));
                const current = Number(manualRequiredMap[String(gid)] || manualRequiredMap[gid] || 0);
                return `<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px;">
                  <span class="mono">${esc(gname)} (#${gid})</span>
                  <input class="fi pd-missing-group-input" data-group-id="${gid}" style="max-width:180px;" type="number" min="1" value="${current > 0 ? current : ''}" placeholder="AY attribute id">
                  <select class="fi pd-missing-group-select" data-group-id="${gid}" style="min-width:260px;">
                    <option value="">Select AY option...</option>
                  </select>
                  <button class="btn btn-sm pd-missing-group-load" data-group-id="${gid}">Load options</button>
                  <span class="mono pd-missing-group-label" data-group-id="${gid}" style="font-size:11px;color:var(--muted);">${current > 0 ? `Current AY#${current}` : 'No option selected'}</span>
                </div>`;
              }).join('')
              : '<div class="mono">No parsed missing required-group hints recorded yet.</div>'}
          </div>
        </div>
        <div class="g2">
          <div class="fr">
            <label class="fl">AboutYou Category</label>
            <input class="fi" id="pd-category-id" type="number" min="1" value="${esc(p.ay_category_id || d.effective_ay_category_id || '')}" placeholder="AY category id">
            <div style="margin-top:6px;font-size:11px;color:var(--muted);line-height:1.3;">
              ${Number(d.effective_ay_category_id || p.ay_category_id || 0) > 0
                ? `Current effective category: AY#${esc(d.effective_ay_category_id || p.ay_category_id)} · ${esc(d.effective_ay_category_path || p.ay_category_path || 'Mapped')}`
                : 'Set AY category id here, then Save Product Settings'}
            </div>
          </div>
          <div class="fr"><label class="fl">AboutYou Brand ID</label><input class="fi" id="pd-brand-id" type="number" value="${esc(p.ay_brand_id || d.effective_ay_brand_id || '')}"></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <button class="btn btn-p btn-sm" id="product-detail-save">Save Product Settings</button>
          <span class="mono">PS category: ${categoryName || '—'}</span>
          <span class="mono">AY category: ${esc(d.effective_ay_category_id || '—')}</span>
        </div>
        ${d.remote_pending ? `<div style="margin-top:10px;color:var(--info);font-size:12px;">Loading live PrestaShop/AboutYou metadata in background...</div>` : ''}
        ${d.ps_fetch_error ? `<div style="margin-top:10px;color:var(--amber);font-size:12px;">PrestaShop detail fetch warning: ${esc(d.ps_fetch_error)}</div>` : ''}
        ${d.ay_fetch_error ? `<div style="margin-top:10px;color:var(--amber);font-size:12px;">AboutYou metadata warning: ${esc(d.ay_fetch_error)}</div>` : ''}
      </div>
      <div class="card">
        <div class="ch"><div><div class="ct">Images & Sync History</div><div class="cs">Processed images and latest product-specific logs</div></div></div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
          <button class="btn btn-sm" id="product-detail-retry-images">Retry Failed/Pending Images</button>
          <span class="mono">${images.filter(i => i.status === 'error').length} failed · ${images.filter(i => i.status === 'pending').length} pending</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:10px;margin-bottom:14px;">
          ${images.length ? images.map(img => `
            <a href="${esc(img.public_url || img.source_url || '#')}" target="_blank" style="text-decoration:none;color:inherit;">
              <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:8px;">
                <div style="height:88px;border-radius:8px;background:var(--surface3);display:flex;align-items:center;justify-content:center;overflow:hidden;">
                  ${img.public_url || img.source_url ? `<img src="${esc(img.public_url || img.source_url)}" style="max-width:100%;max-height:100%;object-fit:cover;">` : '<span class="mono">No image</span>'}
                </div>
                <div class="mono" style="margin-top:6px;">${esc(img.status || 'pending')}</div>
                ${img.error_message ? `<div style="margin-top:4px;font-size:10px;line-height:1.3;color:var(--red);white-space:normal;">${esc(img.error_message)}</div>` : ''}
              </div>
            </a>`).join('') : `<div class="mono">No processed images yet</div>`}
        </div>
        <div class="slog" style="max-height:250px;">
          ${logs.length ? logs.map(log => `<div class="ll"><span class="lt">${esc(shortDate(log.created_at))}</span><span class="${log.level==='error'?'lerr':log.level==='warning'?'lwarn':'linfo'}">${esc(log.message)}</span></div>`).join('') : '<div class="mono">No product-specific logs yet</div>'}
        </div>
        <div style="margin-top:12px;">
          <div class="ct" style="font-size:12px;margin-bottom:6px;">Readiness blockers</div>
          <div class="slog" style="max-height:220px;">
            ${errorHistory.length ? errorHistory.map(err => {
              const code = normalizeReasonCode(err.reason_code || extractReasonCodeFromText(err.error_message));
              const hint = reasonHint(code);
              return `<div class="ll"><span class="lt">${esc(shortDate(err.created_at))}</span><span class="lerr">${esc((code || 'unknown') + ': ' + (err.error_message || 'sync error'))}${hint ? `<div style="margin-top:3px;font-size:11px;color:var(--accent2);line-height:1.3;">${esc(hint)}</div>` : ''}</span></div>`;
            }).join('') : '<div class="mono">No blocker history recorded</div>'}
          </div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="ch"><div><div class="ct">Color & Size Mapping</div><div class="cs">Fix the exact values used by this product before sending it</div></div></div>
      ${invalidMapRows.length ? `
        <div style="margin-bottom:10px;padding:10px;border:1px solid var(--amber);border-radius:8px;background:rgba(245,158,11,.08);">
          <div style="font-size:12px;font-weight:600;color:var(--amber);margin-bottom:4px;">Invalid mapping for current AY category</div>
          <div style="font-size:11.5px;line-height:1.35;color:var(--text);">
            ${invalidMapRows.map((row) => `${esc((row.map_type || '').toUpperCase())} "${esc(row.ps_label || '')}"`).join(' · ')}
          </div>
        </div>
      ` : ''}
      <div class="tw"><table>
        <thead><tr><th>Type</th><th>PS Value</th><th>Used In Variants</th><th>AY Mapping</th></tr></thead>
        <tbody>
          ${attrRows.length ? attrRows.map((row, idx) => `
            <tr>
              <td><span class="badge ${row.map_type === 'color' ? 'b-purple' : 'b-info'}">${esc(row.map_type)}</span></td>
              <td><div style="font-weight:600;">${esc(row.ps_label)}</div><div class="mono">${esc(row.group_name || '')}</div></td>
              <td class="mono">${esc((row.combo_refs || []).map(c => c.reference || ('#'+c.id)).join(', '))}</td>
              <td>
                <select class="fi pd-attr-map" data-type="${esc(row.map_type)}" data-label="${esc(row.ps_label)}" data-group-id="${Number(row.group_id || 0)}" data-group-name="${esc(row.group_name || '')}" style="${((row.map_type === 'size' && Number(row.ay_id || 0) > 0 && !sizeOptionIds.has(Number(row.ay_id || 0))) || (row.map_type === 'color' && Number(row.ay_id || 0) > 0 && !colorOptionIds.has(Number(row.ay_id || 0)))) ? 'border-color:var(--amber);' : ''}">
                  ${optionMarkup(row.map_type === 'color' ? colorOptions : sizeOptions, row.ay_id)}
                </select>
                ${((row.map_type === 'size' && Number(row.ay_id || 0) > 0 && !sizeOptionIds.has(Number(row.ay_id || 0))) || (row.map_type === 'color' && Number(row.ay_id || 0) > 0 && !colorOptionIds.has(Number(row.ay_id || 0))))
                  ? `<div style="margin-top:4px;font-size:11px;color:var(--amber);line-height:1.3;">Mapped AY id ${esc(row.ay_id)} is not available for current category.</div>`
                  : ''}
              </td>
            </tr>`).join('') : '<tr><td colspan="4" class="loading">No color or size attributes found for this product</td></tr>'}
        </tbody>
      </table></div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <button class="btn btn-p btn-sm" id="product-detail-save-maps">Save Attribute Mappings</button>
        <button class="btn btn-sm" id="product-detail-clear-invalid-maps" ${invalidMapRows.length ? '' : 'disabled'}>Clear Invalid Mappings</button>
        <span class="mono">${colorOptions.length} color options · ${sizeOptions.length} size options from AY category metadata</span>
      </div>
    </div>
    <div class="card">
      <div class="ch"><div><div class="ct">Live PrestaShop Variants</div><div class="cs">What this product currently looks like before export</div></div></div>
      <div class="tw"><table>
        <thead><tr><th>Combo</th><th>Reference</th><th>EAN (editable)</th><th>Qty</th><th>Attributes</th></tr></thead>
        <tbody>
          ${combos.length ? combos.map(combo => `
            <tr>
              <td class="mono">#${esc(combo.id || '—')}</td>
              <td class="mono">${esc(combo.reference || '—')}</td>
              <td><input class="fi pd-variant-ean" data-combo-id="${esc(combo.id || '')}" value="${esc(combo.ean13 || '')}" placeholder="EAN13 / GTIN"></td>
              <td>${esc(combo.quantity || 0)}</td>
              <td>${(combo.attributes || []).map(a => `<span class="badge b-gray">${esc((a.group_name || '') + ': ' + (a.value_name || ''))}</span>`).join(' ') || '<span class="mono">—</span>'}</td>
            </tr>`).join('') : '<tr><td colspan="5" class="loading">No live combinations loaded</td></tr>'}
        </tbody>
      </table></div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <button class="btn btn-p btn-sm" id="product-detail-save-eans">Save Variant EANs</button>
        <span class="mono">Use this when PrestaShop combinations are missing EAN values.</span>
      </div>
    </div>`;

  list.style.display = 'none';
  wrap.style.display = '';

  document.getElementById('product-detail-back').addEventListener('click', closeProductDetail);
  document.getElementById('product-detail-push').addEventListener('click', () => runSync('products', { ps_product_ids: [Number(p.ps_id)] }));
  document.getElementById('product-detail-save').addEventListener('click', saveProductDetailSettings);
  document.getElementById('pd-fill-required-from-defaults')?.addEventListener('click', fillProductRequiredFromCategoryDefaults);
  document.getElementById('pd-autofill-missing-required')?.addEventListener('click', autoFillMissingRequiredAttributes);
  document.querySelectorAll('.pd-missing-group-load').forEach((btn) => {
    btn.addEventListener('click', async (event) => {
      event.preventDefault();
      const groupId = Number(btn.dataset.groupId || 0);
      if (groupId <= 0) return;
      await loadMissingGroupDropdownOptions(groupId, true);
    });
  });
  document.querySelectorAll('.pd-missing-group-select').forEach((sel) => {
    sel.addEventListener('change', () => {
      const groupId = Number(sel.dataset.groupId || 0);
      const targetInput = document.querySelector(`.pd-missing-group-input[data-group-id="${groupId}"]`);
      if (!targetInput) return;
      const selectedId = Number(sel.value || 0);
      targetInput.value = selectedId > 0 ? String(selectedId) : '';
      syncMissingGroupLabel(groupId);
    });
  });
  document.querySelectorAll('.pd-missing-group-input').forEach((input) => {
    input.addEventListener('input', () => {
      const groupId = Number(input.dataset.groupId || 0);
      syncMissingGroupLabel(groupId);
      syncMissingGroupSelect(groupId);
    });
  });
  missingRequiredGroups.forEach((group) => {
    const groupId = Number(group?.group_id || 0);
    if (groupId > 0) {
      syncMissingGroupLabel(groupId);
    }
  });
  document.getElementById('product-detail-save-maps').addEventListener('click', saveProductDetailMappings);
  document.getElementById('product-detail-clear-invalid-maps')?.addEventListener('click', clearInvalidProductDetailMappings);
  document.getElementById('product-detail-auto-map').addEventListener('click', autoMapProductDetailMappings);
  document.getElementById('product-detail-save-eans').addEventListener('click', saveProductVariantEans);
  document.getElementById('product-detail-retry-images').addEventListener('click', retryProductFailedImages);
}

async function saveProductDetailSettings() {
  if (!currentProductDetail?.product?.ps_id) return;
  const psId = Number(currentProductDetail.product.ps_id);
  const psApiPayloadRaw = document.getElementById('pd-ps-api-payload').value;
  const manualRequiredRaw = document.getElementById('pd-manual-required-attributes').value;
  const manualFromHints = {};
  document.querySelectorAll('.pd-missing-group-input').forEach((el) => {
    const groupId = Number(el.dataset.groupId || 0);
    const ayId = Number((el.value || '').trim());
    if (groupId > 0 && ayId > 0) {
      manualFromHints[groupId] = ayId;
    }
  });
  let manualRequiredMap = parseJsonObjectSafe(manualRequiredRaw || '{}');
  manualRequiredMap = { ...manualRequiredMap, ...manualFromHints };
  const manualRequiredSerialized = JSON.stringify(manualRequiredMap, null, 2);
  if (psApiPayloadRaw.trim() !== '') {
    try {
      JSON.parse(psApiPayloadRaw);
    } catch (error) {
      toast(`Invalid PrestaShop API payload JSON: ${error?.message || 'parse error'}`, 'err');
      return;
    }
  }
  const r = await api('product_save', {
    product_id: psId,
    export_title: document.getElementById('pd-export-title').value,
    export_description: document.getElementById('pd-export-description').value,
    ps_api_payload: psApiPayloadRaw,
    ay_manual_required_attributes_json: manualRequiredSerialized,
    ay_category_id: document.getElementById('pd-category-id').value,
    ay_brand_id: document.getElementById('pd-brand-id').value,
  });
  if (!r.ok || !r.data.ok) { toast(r.data?.error || 'Failed to save product settings', 'err'); return; }
  currentProductDetail = r.data.data;
  renderProductDetail(currentProductDetail);
  await loadProducts();
  toast('Product settings saved', 'ok');
}

async function fillProductRequiredFromCategoryDefaults() {
  if (!currentProductDetail?.product) return;
  const categoryId = Number(currentProductDetail.product.ay_category_id || currentProductDetail.effective_ay_category_id || 0);
  if (categoryId <= 0) {
    toast('Set AY category first', 'err');
    return;
  }
  const overwrite = !!document.getElementById('pd-fill-required-overwrite')?.checked;
  const manualEl = document.getElementById('pd-manual-required-attributes');
  if (!manualEl) return;
  const existing = parseJsonObjectSafe(manualEl.value || '{}');
  const r = await api('required_group_defaults', { category_id: categoryId }, 'POST');
  if (!r.ok || !r.data?.ok) {
    toast(r.data?.error || 'Could not load required group defaults', 'err');
    return;
  }
  const rows = Array.isArray(r.data.data) ? r.data.data : [];
  const scoped = rows.filter((row) => Number(row?.ay_category_id || 0) === categoryId || Number(row?.ay_category_id || 0) === 0);
  const next = overwrite ? {} : { ...existing };
  let applied = 0;
  for (const row of scoped) {
    const groupId = Number(row?.ay_group_id || 0);
    const ayId = Number(row?.default_ay_id || 0);
    if (groupId <= 0 || ayId <= 0) continue;
    if (!overwrite && Number(next[groupId] || 0) > 0) continue;
    next[groupId] = ayId;
    applied++;
  }
  manualEl.value = JSON.stringify(next, null, 2);
  toast(`Applied ${applied} required default(s) from category ${categoryId}`, applied > 0 ? 'ok' : 'info');
  if (applied > 0) {
    await saveProductDetailSettings();
  }
}

async function autoFillMissingRequiredAttributes() {
  if (!currentProductDetail?.product) return;
  const psId = Number(currentProductDetail.product.ps_id || 0);
  const categoryId = Number(currentProductDetail.product.ay_category_id || currentProductDetail.effective_ay_category_id || 0);
  if (psId <= 0 || categoryId <= 0) {
    toast('Set AY category first', 'err');
    return;
  }

  const inputs = [...document.querySelectorAll('.pd-missing-group-input')];
  if (!inputs.length) {
    toast('No missing attribute fields detected', 'info');
    return;
  }

  // 1) Prefer configured category defaults.
  const defaultsResp = await api('required_group_defaults', { category_id: categoryId }, 'POST');
  const defaultMap = {};
  if (defaultsResp.ok && defaultsResp.data?.ok) {
    const rows = Array.isArray(defaultsResp.data?.data) ? defaultsResp.data.data : [];
    for (const row of rows) {
      const rowCategory = Number(row?.ay_category_id || 0);
      const groupId = Number(row?.ay_group_id || 0);
      const defaultId = Number(row?.default_ay_id || 0);
      if (groupId <= 0 || defaultId <= 0) continue;
      // Prefer category-specific over global (0).
      if (rowCategory === categoryId || (rowCategory === 0 && !defaultMap[groupId])) {
        defaultMap[groupId] = defaultId;
      }
    }
  }

  let applied = 0;
  for (const input of inputs) {
    const groupId = Number(input.dataset.groupId || 0);
    if (groupId <= 0) continue;
    if (Number((input.value || '').trim()) > 0) continue;

    if (Number(defaultMap[groupId] || 0) > 0) {
      input.value = String(defaultMap[groupId]);
      applied++;
      continue;
    }

    // 2) Fallback to first AY option in this group for current category.
    const optionsResp = await api('ay_attribute_options_by_group', {
      product_id: psId,
      group_id: groupId,
    }, 'POST');
    if (!optionsResp.ok || !optionsResp.data?.ok) {
      continue;
    }
    const items = Array.isArray(optionsResp.data?.data?.items) ? optionsResp.data.data.items : [];
    const first = items[0] || null;
    if (first && Number(first.id || 0) > 0) {
      input.value = String(Number(first.id));
      applied++;
    }
  }

  toast(`Auto-filled ${applied} missing attribute field(s)`, applied > 0 ? 'ok' : 'info');
  if (applied > 0) {
    await saveProductDetailSettings();
  }
}

function missingGroupOptionsCacheKey(psId, groupId) {
  return `${Number(psId || 0)}|${Number(groupId || 0)}`;
}

async function loadMissingGroupDropdownOptions(groupId, forceReload = false) {
  if (!currentProductDetail?.product?.ps_id) return;
  const psId = Number(currentProductDetail.product.ps_id || 0);
  if (psId <= 0 || Number(groupId || 0) <= 0) return;
  const key = missingGroupOptionsCacheKey(psId, groupId);
  let items = missingGroupOptionsCache[key];
  if (!Array.isArray(items) || forceReload) {
    const resp = await api('ay_attribute_options_by_group', {
      product_id: psId,
      group_id: Number(groupId),
    }, 'POST');
    if (!resp.ok || !resp.data?.ok) {
      toast(resp.data?.error || `Could not load AY options for group #${groupId}`, 'err');
      return;
    }
    items = Array.isArray(resp.data?.data?.items) ? resp.data.data.items : [];
    missingGroupOptionsCache[key] = items;
  }
  const select = document.querySelector(`.pd-missing-group-select[data-group-id="${groupId}"]`);
  const input = document.querySelector(`.pd-missing-group-input[data-group-id="${groupId}"]`);
  if (!select) return;
  const currentId = Number(input?.value || 0);
  const optionsHtml = ['<option value="">Select AY option...</option>']
    .concat((items || []).slice(0, 250).map((item) => {
      const id = Number(item.id || 0);
      const label = String(item.label || '');
      return `<option value="${id}" ${id === currentId ? 'selected' : ''}>AY#${id} ${esc(label)}</option>`;
    }));
  select.innerHTML = optionsHtml.join('');
  syncMissingGroupLabel(Number(groupId));
}

function syncMissingGroupSelect(groupId) {
  const select = document.querySelector(`.pd-missing-group-select[data-group-id="${groupId}"]`);
  const input = document.querySelector(`.pd-missing-group-input[data-group-id="${groupId}"]`);
  if (!select || !input) return;
  const currentId = Number(input.value || 0);
  const exists = [...select.options].some((opt) => Number(opt.value || 0) === currentId);
  if (currentId <= 0) {
    select.value = '';
    return;
  }
  if (exists) {
    select.value = String(currentId);
  }
}

function syncMissingGroupLabel(groupId) {
  const labelEl = document.querySelector(`.pd-missing-group-label[data-group-id="${groupId}"]`);
  const input = document.querySelector(`.pd-missing-group-input[data-group-id="${groupId}"]`);
  const select = document.querySelector(`.pd-missing-group-select[data-group-id="${groupId}"]`);
  if (!labelEl || !input) return;
  const currentId = Number(input.value || 0);
  if (currentId <= 0) {
    labelEl.textContent = 'No option selected';
    return;
  }
  if (select) {
    const selectedOpt = [...select.options].find((opt) => Number(opt.value || 0) === currentId);
    if (selectedOpt && String(selectedOpt.textContent || '').trim() !== '') {
      labelEl.textContent = String(selectedOpt.textContent || '').trim();
      return;
    }
  }
  labelEl.textContent = `Current AY#${currentId}`;
}

async function saveProductDetailMappings() {
  if (!currentProductDetail?.product?.ps_id) return;
  const mappings = [...document.querySelectorAll('.pd-attr-map')].map(el => ({
    map_type: el.dataset.type,
    ps_label: el.dataset.label,
    ay_group_id: Number(el.dataset.groupId || 0),
    ay_group_name: String(el.dataset.groupName || ''),
    ay_id: Number(el.value || 0),
  }));
  const r = await api('product_map_attributes_save', { mappings });
  if (!r.ok || !r.data.ok) { toast(r.data?.error || 'Failed to save attribute mappings', 'err'); return; }
  currentProductDetail = (await api('product_detail', { product_id: Number(currentProductDetail.product.ps_id) })).data.data;
  renderProductDetail(currentProductDetail);
  toast(`Saved ${Number(r.data.data.saved || 0)} mapping(s), cleared ${Number(r.data.data.deleted || 0)}`, 'ok');
}

async function clearInvalidProductDetailMappings() {
  const invalidSelects = [...document.querySelectorAll('.pd-attr-map')].filter((el) => {
    const type = String(el.dataset.type || '');
    const ayId = Number(el.value || 0);
    if (ayId <= 0) return false;
    const optionExists = [...el.options].some((opt) => Number(opt.value || 0) === ayId);
    if (optionExists) return false;
    return type === 'size' || type === 'color';
  });
  if (!invalidSelects.length) {
    toast('No invalid mappings to clear', 'info');
    return;
  }
  invalidSelects.forEach((el) => { el.value = ''; });
  await saveProductDetailMappings();
}

async function autoMapProductDetailMappings() {
  if (!currentProductDetail?.product?.ps_id) return;
  const r = await api('product_auto_map_attributes', { product_id: Number(currentProductDetail.product.ps_id) });
  if (!r.ok || !r.data.ok) { toast(r.data?.error || 'Auto-map failed', 'err'); return; }
  currentProductDetail = r.data.data.detail;
  renderProductDetail(currentProductDetail);
  toast(`Auto-mapped ${r.data.data.saved} values`, r.data.data.saved ? 'ok' : 'info');
}

async function saveProductVariantEans() {
  if (!currentProductDetail?.product?.ps_id) return;
  const variantEans = [...document.querySelectorAll('.pd-variant-ean')]
    .map(el => ({
      ps_combo_id: Number(el.dataset.comboId || 0),
      ean13: (el.value || '').trim(),
    }))
    .filter(row => row.ps_combo_id > 0);
  const invalid = variantEans.find(row => row.ean13 !== '' && !isValidEan13(row.ean13));
  if (invalid) {
    toast(`Combination #${invalid.ps_combo_id} has invalid EAN13`, 'err');
    return;
  }
  const r = await api('product_variant_eans_save', {
    product_id: Number(currentProductDetail.product.ps_id),
    variant_eans: variantEans,
  });
  if (!r.ok || !r.data.ok) {
    toast(r.data?.error || 'Failed to save variant EANs', 'err');
    return;
  }
  currentProductDetail = r.data.data.detail;
  renderProductDetail(currentProductDetail);
  toast(`Saved ${r.data.data.saved} variant EAN value(s)`, 'ok');
}

async function retryProductFailedImages() {
  if (!currentProductDetail?.product?.ps_id) return;
  const psId = Number(currentProductDetail.product.ps_id);
  const r = await api('image_retry_failed', { ps_id: psId });
  if (!r.ok || !r.data?.ok) {
    toast(r.data?.error || 'Failed to retry images', 'err');
    return;
  }
  const data = r.data.data || {};
  const retriedError = Number(data.retried_error || 0);
  const retriedPending = Number(data.retried_pending || 0);
  toast(
    `Image retry done: ${Number(data.ok || 0)} ok, ${Number(data.failed || 0)} failed (${retriedError} error + ${retriedPending} pending retried)`,
    Number(data.failed || 0) ? 'info' : 'ok'
  );
  await viewProduct(psId);
}

function wireProductsPage() {
  document.getElementById('prod-refresh').addEventListener('click', loadProducts);
  document.getElementById('prod-search').addEventListener('input', () => { productsPage = 1; loadProducts(); });
  document.getElementById('products-prev').addEventListener('click', () => { if (productsPage > 1) { productsPage--; loadProducts(); } });
  document.getElementById('products-next').addEventListener('click', () => { productsPage++; loadProducts(); });
  document.querySelectorAll('#product-tabs .tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('#product-tabs .tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      productsStatus = t.dataset.status; productsPage = 1; loadProducts();
    });
  });
  document.querySelectorAll('#compare-buckets .tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('#compare-buckets .tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      productsCompareBucket = t.dataset.bucket || 'not_synced';
      productsPage = 1;
      if (productsStatus === 'compare') {
        loadProducts();
      }
    });
  });
  document.getElementById('compare-recheck-btn').addEventListener('click', recheckProductsOnAboutYou);
  document.getElementById('push-selected-btn').addEventListener('click', () => {
    const ids = selectedProductIds();
    if (!ids.length) { toast('Select at least one product', 'err'); return; }
    runSync('products', { ps_product_ids: ids });
  });
  document.getElementById('import-ps-btn').addEventListener('click', () => runSync('products'));
  document.getElementById('export-csv-btn').addEventListener('click', exportProductsCsvWithPayload);
  document.getElementById('check-all-products').addEventListener('change', function() {
    document.querySelectorAll('.pc').forEach(c => c.checked = this.checked);
    refreshBulkSelectionUi();
  });
  document.getElementById('assign-category-selected-btn').addEventListener('click', openProductCategoryDrawerForBulk);
  document.getElementById('product-category-drawer-close').addEventListener('click', closeProductCategoryDrawer);
  document.getElementById('product-category-drawer-backdrop').addEventListener('click', closeProductCategoryDrawer);
  document.getElementById('product-category-clear').addEventListener('click', async () => {
    productCategoryDrawerState.selected = null;
    await renderCurrentCategoryLabel();
  });
  document.getElementById('product-category-save').addEventListener('click', saveProductCategoryFromDrawer);
  document.getElementById('product-category-search').addEventListener('input', () => {
    if (productCategorySearchDebounceTimer) {
      clearTimeout(productCategorySearchDebounceTimer);
    }
    const searchValue = (document.getElementById('product-category-search')?.value || '').trim();
    const clearBtn = document.getElementById('product-category-search-clear');
    if (clearBtn) {
      clearBtn.style.display = searchValue === '' ? 'none' : 'inline-flex';
    }
    productCategorySearchDebounceTimer = setTimeout(() => {
      searchAyCategoriesForDrawer(searchValue === '');
    }, 220);
  });
  document.getElementById('product-category-search-clear').addEventListener('click', () => {
    const input = document.getElementById('product-category-search');
    const clearBtn = document.getElementById('product-category-search-clear');
    if (!input) return;
    input.value = '';
    if (clearBtn) {
      clearBtn.style.display = 'none';
    }
    searchAyCategoriesForDrawer(true);
    input.focus();
  });
  document.getElementById('product-category-gender-filter').addEventListener('change', () => {
    if (productCategoryDrawerState.mode === 'single') {
      loadDrawerCategorySuggestion();
    }
    searchAyCategoriesForDrawer(true);
  });
  refreshBulkSelectionUi();
}
