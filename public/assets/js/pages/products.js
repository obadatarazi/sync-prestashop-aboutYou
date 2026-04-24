'use strict';

async function loadProducts() {
  const tbody = document.getElementById('products-body');
  tbody.innerHTML = '<tr><td colspan="11" class="loading"><span class="spin">⟳</span></td></tr>';
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
  if (!r.ok || !r.data.ok) { tbody.innerHTML = '<tr><td colspan="11" style="color:var(--red);padding:14px;">Failed to load</td></tr>'; return; }
  const { rows, total, page, per_page } = r.data.data;
  document.getElementById('products-subtitle').textContent = `${total} products`;
  document.getElementById('products-page-info').textContent = `Page ${page} · ${total} total`;
  document.getElementById('products-prev').disabled = page <= 1;
  document.getElementById('products-next').disabled = page * per_page >= total;

  if (!rows.length) { tbody.innerHTML = '<tr><td colspan="11" style="color:var(--muted);padding:14px;text-align:center;">No products found</td></tr>'; return; }

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
      <td>${statusBadge(p.sync_status)}</td>
      <td class="mono">${(p.last_synced_at||'—').slice(0,10)}</td>
      <td class="mono" style="color:var(--muted)">—</td>
      <td><button class="btn btn-sm" onclick="event.stopPropagation();viewProduct(${p.ps_id})">View</button></td>
    </tr>`).join('');
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
  tbody.innerHTML = '<tr><td colspan="11" class="loading"><span class="spin">⟳</span></td></tr>';
  const r = await api('products_compare', {
    page: productsPage,
    per_page: 20,
    bucket: productsCompareBucket,
    search,
  });
  if (!r.ok || !r.data.ok) {
    tbody.innerHTML = '<tr><td colspan="11" style="color:var(--red);padding:14px;">Failed to load comparison</td></tr>';
    return;
  }
  const { rows = [], total = 0, page = 1, per_page = 20, summary = {} } = r.data.data || {};
  productsCompareRows = rows;
  renderProductsComparisonSummary(summary);
  const bucketLabel = productsCompareBucket === 'synced' ? 'Synced' : 'Not Synced';
  document.getElementById('products-subtitle').textContent = `Comparison mode · ${summary.synced || 0} synced · ${summary.not_synced || 0} not synced`;
  document.getElementById('products-page-info').textContent = `${bucketLabel} · Page ${page} · ${total} total`;
  document.getElementById('products-prev').disabled = page <= 1;
  document.getElementById('products-next').disabled = page * per_page >= total;

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="11" style="color:var(--muted);padding:14px;text-align:center;">No products in this bucket</td></tr>';
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
      <td>${statusBadge(p.sync_status)}</td>
      <td class="mono">${(p.last_synced_at||'—').slice(0,10)}</td>
      <td style="max-width:320px;"><div class="mono" style="white-space:normal;line-height:1.3;color:var(--muted);">${esc(p.comparison_reason || '—')}</div></td>
      <td><button class="btn btn-sm" onclick="event.stopPropagation();viewProduct(${p.ps_id})">View</button></td>
    </tr>`).join('');
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
  const attrRows = d.attribute_rows || [];
  const colorOptions = d.ay_options?.color || [];
  const sizeOptions = d.ay_options?.size || [];
  const categoryName = d.ps_category ? esc(d.ps_category.name?.[0]?.value || d.ps_category.name || '') : esc(p.category_name || '—');
  const exportTitle = esc(p.export_title || p.name || '');
  const exportDescription = esc(p.export_description || p.description || '');
  const psApiPayload = esc(p.ps_api_payload || '');

  const optionMarkup = (opts, selected) => [`<option value="">Not mapped</option>`].concat(
    opts.map(o => `<option value="${o.id}" ${Number(selected||0)===Number(o.id)?'selected':''}>${esc(o.label)}</option>`)
  ).join('');

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
        <div class="g2">
          <div class="fr"><label class="fl">AboutYou Category ID</label><input class="fi" id="pd-category-id" type="number" value="${esc(p.ay_category_id || d.effective_ay_category_id || '')}"></div>
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
          <button class="btn btn-sm" id="product-detail-retry-images">Retry Failed Images</button>
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
      </div>
    </div>
    <div class="card">
      <div class="ch"><div><div class="ct">Color & Size Mapping</div><div class="cs">Fix the exact values used by this product before sending it</div></div></div>
      <div class="tw"><table>
        <thead><tr><th>Type</th><th>PS Value</th><th>Used In Variants</th><th>AY Mapping</th></tr></thead>
        <tbody>
          ${attrRows.length ? attrRows.map((row, idx) => `
            <tr>
              <td><span class="badge ${row.map_type === 'color' ? 'b-purple' : 'b-info'}">${esc(row.map_type)}</span></td>
              <td><div style="font-weight:600;">${esc(row.ps_label)}</div><div class="mono">${esc(row.group_name || '')}</div></td>
              <td class="mono">${esc((row.combo_refs || []).map(c => c.reference || ('#'+c.id)).join(', '))}</td>
              <td>
                <select class="fi pd-attr-map" data-type="${esc(row.map_type)}" data-label="${esc(row.ps_label)}">
                  ${optionMarkup(row.map_type === 'color' ? colorOptions : sizeOptions, row.ay_id)}
                </select>
              </td>
            </tr>`).join('') : '<tr><td colspan="4" class="loading">No color or size attributes found for this product</td></tr>'}
        </tbody>
      </table></div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <button class="btn btn-p btn-sm" id="product-detail-save-maps">Save Attribute Mappings</button>
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
  document.getElementById('product-detail-save-maps').addEventListener('click', saveProductDetailMappings);
  document.getElementById('product-detail-auto-map').addEventListener('click', autoMapProductDetailMappings);
  document.getElementById('product-detail-save-eans').addEventListener('click', saveProductVariantEans);
  document.getElementById('product-detail-retry-images').addEventListener('click', retryProductFailedImages);
}

async function saveProductDetailSettings() {
  if (!currentProductDetail?.product?.ps_id) return;
  const psId = Number(currentProductDetail.product.ps_id);
  const psApiPayloadRaw = document.getElementById('pd-ps-api-payload').value;
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
    ay_category_id: document.getElementById('pd-category-id').value,
    ay_brand_id: document.getElementById('pd-brand-id').value,
  });
  if (!r.ok || !r.data.ok) { toast(r.data?.error || 'Failed to save product settings', 'err'); return; }
  currentProductDetail = r.data.data;
  renderProductDetail(currentProductDetail);
  await loadProducts();
  toast('Product settings saved', 'ok');
}

async function saveProductDetailMappings() {
  if (!currentProductDetail?.product?.ps_id) return;
  const mappings = [...document.querySelectorAll('.pd-attr-map')].map(el => ({
    map_type: el.dataset.type,
    ps_label: el.dataset.label,
    ay_id: Number(el.value || 0),
  })).filter(row => row.ay_id > 0);
  const r = await api('product_map_attributes_save', { mappings });
  if (!r.ok || !r.data.ok) { toast(r.data?.error || 'Failed to save attribute mappings', 'err'); return; }
  currentProductDetail = (await api('product_detail', { product_id: Number(currentProductDetail.product.ps_id) })).data.data;
  renderProductDetail(currentProductDetail);
  toast(`Saved ${r.data.data.saved} attribute mappings`, 'ok');
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
  toast(`Image retry done: ${Number(data.ok || 0)} ok, ${Number(data.failed || 0)} failed`, Number(data.failed || 0) ? 'info' : 'ok');
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
    const ids = [...document.querySelectorAll('.pc:checked')].map(c => +c.dataset.psid);
    if (!ids.length) { toast('Select at least one product', 'err'); return; }
    runSync('products', { ps_product_ids: ids });
  });
  document.getElementById('import-ps-btn').addEventListener('click', () => runSync('products'));
  document.getElementById('check-all-products').addEventListener('change', function() {
    document.querySelectorAll('.pc').forEach(c => c.checked = this.checked);
  });
}
