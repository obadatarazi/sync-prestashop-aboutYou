<div class="panel" id="p-sync">
  <div style="margin-bottom:16px;"><h2 style="font-size:15px;font-weight:600;">Sync Engine</h2><p style="color:var(--muted);font-size:12px;margin-top:2px;">Real-time product-by-product sync with live progress</p></div>
  <div class="g2">
    <div class="card">
      <div class="ct" style="margin-bottom:12px;">Run Sync</div>
      <div style="display:flex;flex-direction:column;gap:7px;">
        <button class="btn btn-p" style="justify-content:center;" onclick="runSync('products')">▣ Full Product Sync (PS→DB→AY)</button>
        <button class="btn" style="justify-content:center;" onclick="runSync('products:inc')">▣ Incremental Product Sync</button>
        <button class="btn" style="justify-content:center;" onclick="runSync('stock')">◈ Sync Stock & Prices</button>
        <button class="btn" style="justify-content:center;" onclick="runSync('orders')">☰ Import Orders (AY→PS)</button>
        <button class="btn" style="justify-content:center;" onclick="runSync('order-status')">↑ Push Order Status (PS→AY)</button>
        <div class="divider"></div>
        <button class="btn btn-danger" style="justify-content:center;" onclick="runSync('all')">⟳ Run All</button>
        <button class="btn btn-danger" style="justify-content:center;" id="stop-sync-btn">■ Stop Running Sync</button>
      </div>
    </div>
    <div class="card">
      <div class="ct" style="margin-bottom:12px;">Live Job Status</div>
      <div id="sync-job-detail"></div>
    </div>
  </div>
  <div class="card" style="margin-top:2px;">
    <div class="ch"><div class="ct">Live Trace</div><span class="badge" id="sync-badge">Idle</span></div>
    <div class="slog" id="sync-log"></div>
  </div>
  <div class="card" style="margin-top:14px;">
    <div class="ch">
      <div class="ct">Recent Sync Runs</div>
      <div style="display:flex;gap:8px;align-items:center;">
        <select class="fi" id="run-filter-command" style="width:150px;">
          <option value="">All commands</option>
          <option value="products">products</option>
          <option value="products:inc">products:inc</option>
          <option value="stock">stock</option>
          <option value="orders">orders</option>
          <option value="order-status">order-status</option>
          <option value="all">all</option>
          <option value="retry">retry</option>
        </select>
        <select class="fi" id="run-filter-status" style="width:130px;">
          <option value="">All status</option>
          <option value="running">running</option>
          <option value="completed">completed</option>
          <option value="failed">failed</option>
        </select>
        <span id="run-selected-count" class="badge b-gray">0 selected</span>
      </div>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Select</th><th>Run ID</th><th>Command</th><th>Status</th><th>Pushed</th><th>Failed</th><th>Duration</th><th>Started</th></tr></thead>
      <tbody id="runs-body"><tr><td colspan="8" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>
