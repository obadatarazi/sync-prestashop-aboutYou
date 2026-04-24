<div class="panel" id="p-products">
  <div id="products-list-view">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <div><h2 style="font-size:15px;font-weight:600;">Products</h2><p style="color:var(--muted);font-size:12px;margin-top:2px;" id="products-subtitle">Loading...</p></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
        <button class="btn btn-sm" id="assign-category-selected-btn">🏷 Assign AY Category</button>
        <button class="btn btn-sm" id="import-ps-btn">⬇ Import from PS</button>
        <button class="btn btn-sm" id="export-csv-btn">🧾 Export CSV + Payload</button>
        <button class="btn btn-p btn-sm" id="push-selected-btn">⟳ Push Selected to AY</button>
      </div>
    </div>
    <div class="tabs" id="product-tabs">
      <div class="tab active" data-status="">All</div>
      <div class="tab" data-status="synced">Synced</div>
      <div class="tab" data-status="pending">Pending</div>
      <div class="tab" data-status="error">Errors</div>
      <div class="tab" data-status="compare">Comparison</div>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:12px;">
      <input class="fi" id="prod-search" style="max-width:240px;" placeholder="🔍 Search...">
      <button class="btn btn-sm" id="prod-refresh">Refresh</button>
    </div>
    <div id="products-compare-tools" style="display:none;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
      <div class="tabs" id="compare-buckets" style="margin-bottom:0;">
        <div class="tab active" data-bucket="not_synced">Not Synced</div>
        <div class="tab" data-bucket="synced">Synced</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-sm" id="compare-recheck-btn">Recheck on AboutYou</button>
        <span id="compare-last-run" style="font-size:11.5px;color:var(--muted);"></span>
      </div>
    </div>
    <div class="sg" id="products-compare-summary" style="display:none;grid-template-columns:repeat(4,1fr);margin-bottom:12px;"></div>
    <div class="tw"><table>
      <thead><tr>
        <th><input type="checkbox" id="check-all-products" style="accent-color:var(--accent)"></th>
        <th>Product</th><th>SKUs</th><th>Variants</th><th>Price</th><th>Images</th><th>AY Style Key</th><th>AY Category</th><th>Status</th><th>Synced</th><th>Reason</th><th></th>
      </tr></thead>
      <tbody id="products-body"><tr><td colspan="12" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
    </table></div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
      <span style="font-size:11.5px;color:var(--muted);" id="products-page-info"></span>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-sm" id="products-prev">← Prev</button>
        <button class="btn btn-sm" id="products-next">Next →</button>
      </div>
    </div>
  </div>
  <div id="products-detail-view" style="display:none"></div>

  <div id="product-category-drawer-backdrop" class="drawer-backdrop" style="display:none;"></div>
  <aside id="product-category-drawer" class="drawer" aria-hidden="true">
    <div class="drawer-head">
      <div>
        <div class="ct">Assign AboutYou Category</div>
        <div class="cs" id="product-category-drawer-subtitle">Pick one AY category</div>
      </div>
      <button class="btn btn-sm" id="product-category-drawer-close">Close</button>
    </div>
    <div class="drawer-body">
      <div class="fr">
        <label class="fl">Current selection</label>
        <div id="product-category-current" class="mono">No AY category selected</div>
      </div>
      <div class="fr">
        <label class="fl">PrestaShop current category</label>
        <div id="product-category-ps-current" class="mono">—</div>
      </div>
      <div class="fr">
        <label class="fl">Suggested AY category</label>
        <div id="product-category-suggestion" class="mono">No suggestion yet</div>
      </div>
      <div class="fr">
        <label class="fl">Search AY categories</label>
        <div style="display:flex;gap:8px;align-items:center;">
          <select class="fi" id="product-category-gender-filter" style="max-width:150px;">
            <option value="">All</option>
            <option value="women">Women</option>
            <option value="men">Men</option>
            <option value="kids">Kids</option>
          </select>
          <div style="position:relative;flex:1;">
            <input class="fi" id="product-category-search" placeholder="Type category name or path..." style="padding-right:34px;">
            <button class="btn btn-sm" id="product-category-search-clear" type="button" style="position:absolute;right:4px;top:50%;transform:translateY(-50%);padding:3px 8px;display:none;" aria-label="Clear search">×</button>
          </div>
        </div>
      </div>
      <div id="product-category-results" class="modal-list">
        <div class="loading" style="padding:12px;">Search categories to begin</div>
      </div>
    </div>
    <div class="drawer-foot">
      <button class="btn btn-sm" id="product-category-clear">Clear</button>
      <button class="btn btn-p btn-sm" id="product-category-save">Save Category</button>
    </div>
  </aside>
</div>
