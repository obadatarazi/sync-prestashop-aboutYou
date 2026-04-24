<div class="panel active" id="p-dashboard">
  <div class="sg" id="dash-stats">
    <div class="sc blue"><div class="sl">Products Synced</div><div class="sv" id="d-products">—</div><div class="ss">mapped to AY</div></div>
    <div class="sc green"><div class="sl">Orders Imported</div><div class="sv" id="d-orders">—</div><div class="ss">AY → PS</div></div>
    <div class="sc amber"><div class="sl">Images OK</div><div class="sv" id="d-images">—</div><div class="ss">normalized & stored</div></div>
    <div class="sc red"><div class="sl">Errors</div><div class="sv" id="d-errors">—</div><div class="ss">needs attention</div></div>
  </div>
  <div class="g2">
    <div class="card">
      <div class="ch"><div><div class="ct">Sync Pipeline</div><div class="cs">PrestaShop → DB → AboutYou</div></div><span class="badge b-ok" id="pipe-status">Live</span></div>
      <div id="pipe-bars"></div>
    </div>
    <div class="card">
      <div class="ch"><div class="ct">Recent Activity</div><button class="btn btn-sm" onclick="goto('logs')">All Logs →</button></div>
      <div class="slog" id="dash-log"><div class="loading"><span class="spin">⟳</span> Loading...</div></div>
    </div>
  </div>
  <div class="g2">
    <div class="card">
      <div class="ch"><div class="ct">System Warnings</div><button class="btn btn-sm" id="refresh-warnings">Refresh</button></div>
      <div id="system-warnings" style="font-size:12.5px;color:var(--muted);">No warnings</div>
    </div>
    <div class="card">
      <div class="ch"><div class="ct">Sync Metrics (Recent)</div><button class="btn btn-sm" id="refresh-metrics">Refresh</button></div>
      <div id="metrics-summary" style="font-size:12.5px;color:var(--muted);">Loading metrics...</div>
    </div>
  </div>
  <div class="card">
    <div class="ch"><div class="ct">Current Sync Job</div><button class="btn btn-sm" id="refresh-job">Refresh</button></div>
    <div id="current-job"><div style="color:var(--muted);font-size:13px;text-align:center;padding:16px;">No active sync job</div></div>
  </div>
  <div class="card">
    <div class="ch"><div class="ct">AboutYou Policy Snapshot</div><button class="btn btn-sm" id="refresh-policy-snapshot">Refresh Snapshot</button></div>
    <div id="policy-snapshot" style="font-size:12.5px;color:var(--muted);">No snapshot yet.</div>
  </div>
</div>
