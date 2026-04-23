'use strict';

async function loadDashboard() {
  const r = await api('status', {});
  if (!r.ok || !r.data.ok) return;
  const d = r.data.data;
  syncPid = Number(d.sync_pid || 0);

  // Stats
  const pStats = d.products || {};
  const oStats = d.orders   || {};
  const iStats = d.images   || {};
  document.getElementById('d-products').textContent = pStats.synced ?? '—';
  document.getElementById('d-orders').textContent   = oStats.imported ?? '—';
  document.getElementById('d-images').textContent   = iStats.ok ?? '—';
  document.getElementById('d-errors').textContent   =
    ((pStats.error||0) + (oStats.error||0) + (oStats.quarantined||0) + (iStats.error||0));

  // Nav badges
  const nb = document.getElementById('nb-products');
  nb.textContent = pStats.total ?? '—'; nb.style.display = '';
  const nbo = document.getElementById('nb-orders');
  const oErr = (oStats.error||0) + (oStats.quarantined||0);
  if (oErr) { nbo.textContent = oErr; nbo.style.display = ''; }
  const nbi = document.getElementById('nb-images');
  if (iStats.error) { nbi.textContent = iStats.error; nbi.style.display = ''; }

  // Pipeline bars
  const total = pStats.total || 1;
  document.getElementById('pipe-bars').innerHTML = `
    <div style="margin-bottom:8px;">
      <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--muted);margin-bottom:4px;">
        <span>Products</span><span style="color:var(--text)">${pStats.synced||0} / ${pStats.total||0}</span></div>
      <div class="pb"><div class="pbf b" style="width:${Math.round((pStats.synced||0)/total*100)}%"></div></div>
    </div>
    <div style="margin-bottom:8px;">
      <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--muted);margin-bottom:4px;">
        <span>Images OK</span><span style="color:var(--text)">${iStats.ok||0} / ${iStats.total||0}</span></div>
      <div class="pb"><div class="pbf g" style="width:${iStats.total ? Math.round((iStats.ok||0)/iStats.total*100) : 0}%"></div></div>
    </div>
    <div>
      <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--muted);margin-bottom:4px;">
        <span>Orders</span><span style="color:var(--text)">${oStats.imported||0} / ${oStats.total||0}</span></div>
      <div class="pb"><div class="pbf a" style="width:${oStats.total ? Math.round((oStats.imported||0)/oStats.total*100) : 0}%"></div></div>
    </div>`;

  renderCurrentJob(d.current_run);

  // Recent logs
  const lr = await api('logs', { limit: 30 });
  if (lr.ok && lr.data.ok) {
    const logs = Array.isArray(lr.data.data) ? lr.data.data : (lr.data.data?.rows || []);
    const el = document.getElementById('dash-log');
    if (!logs.length) { el.innerHTML = '<div style="color:var(--muted)">No logs yet</div>'; return; }
    el.innerHTML = logs.slice(0,15).map(l => {
      const cls = l.level === 'error' || l.level === 'critical' ? 'lerr' :
                  l.level === 'warning' ? 'lwarn' : l.level === 'info' ? 'linfo' : '';
      const t = (l.created_at || '').slice(11,19);
      return `<div class="ll"><span class="lt">${esc(t)}</span><span class="${cls}">${esc(l.message)}</span></div>`;
    }).join('');
    el.scrollTop = el.scrollHeight;
  }
}

function renderCurrentJob(run) {
  const el = document.getElementById('current-job');
  if (!run || run.status !== 'running') {
    el.innerHTML = '<div style="color:var(--muted);font-size:12.5px;text-align:center;padding:14px;">No active sync job</div>';
    return;
  }
  const pct = run.total_items > 0 ? Math.round(run.done_items / run.total_items * 100) : 0;
  el.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <span style="font-size:13px;font-weight:600;">⟳ ${esc(run.command)}</span>
      <span style="font-family:var(--mono);font-size:11.5px;color:var(--muted);">${run.done_items}/${run.total_items}</span>
    </div>
    <div class="pb" style="margin-bottom:10px;"><div class="pbf b pulse" style="width:${pct}%"></div></div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px;">
      <div style="text-align:center;padding:8px;background:var(--surface2);border-radius:7px;">
        <div style="font-size:18px;font-weight:700;color:var(--green);">${run.pushed||0}</div>
        <div style="font-size:10px;color:var(--muted);">Pushed</div></div>
      <div style="text-align:center;padding:8px;background:var(--surface2);border-radius:7px;">
        <div style="font-size:18px;font-weight:700;color:var(--amber);">${run.skipped||0}</div>
        <div style="font-size:10px;color:var(--muted);">Skipped</div></div>
      <div style="text-align:center;padding:8px;background:var(--surface2);border-radius:7px;">
        <div style="font-size:18px;font-weight:700;color:var(--red);">${run.failed||0}</div>
        <div style="font-size:10px;color:var(--muted);">Failed</div></div>
    </div>
    <div style="font-size:11.5px;color:var(--muted);">Phase: <strong style="color:var(--text)">${esc(run.current_phase||'—')}</strong></div>
    ${run.current_product_id ? `<div style="font-size:11.5px;color:var(--muted);">Product: <strong style="color:var(--accent2)">PS#${run.current_product_id}</strong></div>` : ''}
    <div style="font-size:11.5px;color:var(--muted);margin-top:4px;">${esc(run.last_message||'')}</div>`;
}

function startJobPoll() {
  if (jobPollTimer) {
    clearInterval(jobPollTimer);
    jobPollTimer = null;
  }
  const settingsMap = Object.fromEntries(settingsCache.map(s => [s.key, s.value]));
  const enabled = String(settingsMap.ui_auto_refresh_enabled ?? 'true') === 'true';
  const intervalSec = Math.max(60, Number(settingsMap.ui_auto_refresh_interval_sec || 3600));
  if (!enabled) {
    return;
  }
  jobPollTimer = setInterval(async () => {
    if (document.hidden) return;
    const r = await api('status', {});
    if (!r.ok) return;
    syncPid = Number(r.data?.data?.sync_pid || 0);
    const run = r.data?.data?.current_run;
    renderCurrentJob(run);
    if (currentPage === 'sync') {
      updateSyncJob(run);
      await loadRuns();
    }
  }, intervalSec * 1000);
}
