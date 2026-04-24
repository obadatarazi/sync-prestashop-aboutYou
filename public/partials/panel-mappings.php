<div class="panel" id="p-mappings">
  <div style="margin-bottom:16px;">
    <h2 style="font-size:15px;font-weight:600;">Category Mapping</h2>
    <p style="color:var(--muted);font-size:12px;margin-top:2px;">Map each PrestaShop category to the correct AboutYou category before bulk sync.</p>
  </div>
  <div class="card">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
      <input class="fi" id="map-search" style="max-width:280px;" placeholder="Filter PrestaShop categories...">
      <select class="fi" id="map-gender-filter" style="max-width:160px;">
        <option value="">All paths</option>
        <option value="women">Women only</option>
        <option value="men">Men only</option>
        <option value="kids">Kids only</option>
      </select>
      <button class="btn btn-sm" id="map-refresh">Refresh</button>
      <button class="btn btn-p btn-sm" id="map-save">Save Mappings</button>
      <button class="btn btn-sm" id="map-validate-selected">Validate Selected Mapping</button>
      <span id="map-save-result" style="font-size:12px;color:var(--muted);"></span>
    </div>
    <div id="map-validation-result" style="font-size:12px;color:var(--muted);margin-bottom:10px;"></div>
    <div class="tw"><table>
      <thead><tr><th>PS Category</th><th>Products</th><th>Current AY Mapping</th><th>Search AY</th><th>Search Results</th></tr></thead>
      <tbody id="mappings-body"><tr><td colspan="5" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>
