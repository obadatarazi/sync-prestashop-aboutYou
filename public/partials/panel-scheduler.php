<div class="panel" id="p-scheduler">
  <div style="margin-bottom:16px;">
    <h2 style="font-size:15px;font-weight:600;">Scheduler</h2>
    <p style="color:var(--muted);font-size:12px;margin-top:2px;">Configure sync cadence in the admin and copy the exact cron lines to your server.</p>
  </div>
  <div class="card">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
      <input class="fi" id="scheduler-php-path" style="max-width:280px;" placeholder="PHP binary path" value="/usr/bin/php">
      <input class="fi" id="scheduler-sync-path" style="min-width:320px;flex:1;" placeholder="sync.php path">
      <button class="btn btn-sm" id="scheduler-refresh">Refresh</button>
      <button class="btn btn-p btn-sm" id="scheduler-save">Save Schedule</button>
      <span id="scheduler-save-result" style="font-size:12px;color:var(--muted);"></span>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Job</th><th>Enabled</th><th>Cadence</th><th>Time</th><th>Cron Preview</th></tr></thead>
      <tbody id="scheduler-body"><tr><td colspan="5" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>
