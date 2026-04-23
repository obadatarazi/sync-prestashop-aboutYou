<div class="panel" id="p-products">
  <div id="products-list-view">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <div><h2 style="font-size:15px;font-weight:600;">Products</h2><p style="color:var(--muted);font-size:12px;margin-top:2px;" id="products-subtitle">Loading...</p></div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-sm" id="import-ps-btn">⬇ Import from PS</button>
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
        <th>Product</th><th>SKUs</th><th>Variants</th><th>Price</th><th>Images</th><th>AY Style Key</th><th>Status</th><th>Synced</th><th>Reason</th><th></th>
      </tr></thead>
      <tbody id="products-body"><tr><td colspan="11" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
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
</div>
