<div class="panel" id="p-attributes">
  <div style="margin-bottom:16px;">
    <h2 style="font-size:15px;font-weight:600;">Attribute Mapping</h2>
    <p style="color:var(--muted);font-size:12px;margin-top:2px;">Map PrestaShop size and color values to valid AboutYou option IDs before syncing variants.</p>
  </div>
  <div class="card">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
      <select class="fi" id="attr-type-filter" style="max-width:140px;">
        <option value="">All types</option>
        <option value="color">Color</option>
        <option value="size">Size</option>
      </select>
      <input class="fi" id="attr-search" style="max-width:240px;" placeholder="Filter values...">
      <input class="fi" id="attr-category-id" style="max-width:180px;" placeholder="AY Category ID">
      <button class="btn btn-sm" id="attr-refresh">Refresh</button>
      <button class="btn btn-p btn-sm" id="attr-save">Save Mappings</button>
      <span id="attr-save-result" style="font-size:12px;color:var(--muted);"></span>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Type</th><th>PS Group</th><th>PS Value</th><th>Current AY ID</th><th>Search AY</th><th>Search Results</th></tr></thead>
      <tbody id="attributes-body"><tr><td colspan="6" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>
