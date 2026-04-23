<div class="panel" id="p-logs">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <h2 style="font-size:15px;font-weight:600;">Sync Logs</h2>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-sm" id="export-logs">⬇ Export CSV</button>
      <button class="btn btn-danger btn-sm" id="delete-logs-btn">🗑 Delete Logs</button>
    </div>
  </div>
  <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
    <input class="fi" id="log-search" style="flex:1;min-width:200px;" placeholder="🔍 Filter messages...">
    <select class="fi" id="log-level" style="max-width:130px;"><option value="">All levels</option><option>info</option><option>warning</option><option>error</option><option>critical</option></select>
    <select class="fi" id="log-channel" style="max-width:130px;"><option value="">All channels</option><option>sync</option><option>orders</option><option>ay-fix</option></select>
    <button class="btn btn-sm" id="log-refresh">Refresh</button>
  </div>
  <div class="tw"><table>
    <thead><tr><th>Timestamp</th><th>Level</th><th>Channel</th><th>Message</th><th>Context</th></tr></thead>
    <tbody id="logs-body"><tr><td colspan="5" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
  </table></div>
  <div class="log-pager">
    <div class="log-pager-meta" id="logs-meta">Page 1 / 1 · 0 items</div>
    <div style="display:flex;gap:6px;">
      <button class="btn btn-sm" id="logs-prev">← Prev</button>
      <button class="btn btn-sm" id="logs-next">Next →</button>
    </div>
  </div>
</div>
