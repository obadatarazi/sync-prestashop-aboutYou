'use strict';

async function loadDashboard() {
  const r = await api('status', {});
  if (!r.ok || !r.data.ok) return;
  const d = r.data.data;
  syncPid = Number(d.sync_pid || 0);
  updateTopbarHealth(d);

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
  renderPolicyWarnings(d.policy_warnings || []);

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

  await loadMetricsSummary();
  await loadPolicySnapshot();
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

function renderPolicyWarnings(warnings) {
  const el = document.getElementById('system-warnings');
  if (!el) return;
  if (!Array.isArray(warnings) || !warnings.length) {
    el.innerHTML = '<span style="color:var(--green)">No policy warnings detected.</span>';
    return;
  }
  el.innerHTML = warnings.map(w => `<div style="margin-bottom:6px;color:var(--amber)">• ${esc(String(w))}</div>`).join('');
}

async function loadMetricsSummary() {
  const el = document.getElementById('metrics-summary');
  if (!el) return;
  const r = await api('metrics', { limit: 250 });
  if (!r.ok || !r.data?.ok) {
    el.textContent = 'Metrics unavailable.';
    return;
  }
  const rows = Array.isArray(r.data.data) ? r.data.data : [];
  if (!rows.length) {
    el.textContent = 'No metrics captured yet.';
    return;
  }
  const elapsed = rows.filter(x => x.metric_key === 'elapsed_sec').map(x => Number(x.metric_value || 0)).filter(x => x > 0);
  const failed = rows.filter(x => x.metric_key === 'failed').map(x => Number(x.metric_value || 0));
  const pushed = rows.filter(x => x.metric_key === 'pushed').map(x => Number(x.metric_value || 0));
  const avgElapsed = elapsed.length ? (elapsed.reduce((a, b) => a + b, 0) / elapsed.length) : 0;
  const totalFailed = failed.reduce((a, b) => a + b, 0);
  const totalPushed = pushed.reduce((a, b) => a + b, 0);
  const successRatio = totalPushed + totalFailed > 0 ? Math.round((totalPushed / (totalPushed + totalFailed)) * 100) : 100;
  const sortedElapsed = elapsed.slice().sort((a, b) => a - b);
  const p95 = sortedElapsed.length ? sortedElapsed[Math.min(sortedElapsed.length - 1, Math.floor(sortedElapsed.length * 0.95))] : 0;
  const sparklineSvg = buildRuntimeSparkline(elapsed.slice(-24));
  el.innerHTML = `
    <div style="margin-bottom:8px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
        <span style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;">Runtime trend (last 24)</span>
        <span style="font-size:11px;color:var(--muted);">${elapsed.length} samples</span>
      </div>
      ${sparklineSvg}
    </div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
      <div style="padding:8px;background:var(--surface2);border-radius:7px;"><div style="font-size:10px;color:var(--muted)">Avg runtime</div><div style="font-weight:600">${avgElapsed.toFixed(2)}s</div></div>
      <div style="padding:8px;background:var(--surface2);border-radius:7px;"><div style="font-size:10px;color:var(--muted)">P95 runtime</div><div style="font-weight:600">${p95.toFixed(2)}s</div></div>
      <div style="padding:8px;background:var(--surface2);border-radius:7px;"><div style="font-size:10px;color:var(--muted)">Total pushed</div><div style="font-weight:600;color:var(--green)">${totalPushed}</div></div>
      <div style="padding:8px;background:var(--surface2);border-radius:7px;"><div style="font-size:10px;color:var(--muted)">Total failed</div><div style="font-weight:600;color:var(--red)">${totalFailed}</div></div>
      <div style="padding:8px;background:var(--surface2);border-radius:7px;"><div style="font-size:10px;color:var(--muted)">Success ratio</div><div style="font-weight:600;color:${successRatio >= 90 ? 'var(--green)' : (successRatio >= 75 ? 'var(--amber)' : 'var(--red)')}">${successRatio}%</div></div>
    </div>`;
}

async function loadPolicySnapshot() {
  const el = document.getElementById('policy-snapshot');
  if (!el) return;
  const r = await api('policy_snapshot', {});
  if (!r.ok || !r.data?.ok) {
    el.textContent = 'Policy snapshot unavailable.';
    return;
  }
  const row = r.data.data;
  if (!row) {
    el.textContent = 'No snapshot yet.';
    return;
  }
  let generatedAt = '—';
  try {
    const payload = typeof row.payload_json === 'string' ? JSON.parse(row.payload_json) : row.payload_json;
    generatedAt = payload?.generated_at || '—';
  } catch (_) {}
  el.innerHTML = `
    <div><strong>Source:</strong> ${esc(row.source || 'mcp_docs')}</div>
    <div><strong>Saved:</strong> ${esc(String(row.created_at || '—'))}</div>
    <div><strong>Generated:</strong> ${esc(String(generatedAt))}</div>`;
}

async function refreshPolicySnapshot() {
  const r = await api('policy_snapshot_refresh', {});
  if (!r.ok || !r.data?.ok) {
    toast(r.data?.error || 'Policy snapshot refresh failed', 'err');
    return;
  }
  toast('Policy snapshot refreshed', 'ok');
  await loadPolicySnapshot();
}

function wireDashboardPage() {
  document.getElementById('refresh-warnings')?.addEventListener('click', loadDashboard);
  document.getElementById('refresh-metrics')?.addEventListener('click', loadMetricsSummary);
  document.getElementById('refresh-policy-snapshot')?.addEventListener('click', refreshPolicySnapshot);
}

function updateTopbarHealth(statusData) {
  const ps = document.getElementById('tb-ps');
  const ay = document.getElementById('tb-ay');
  const mode = document.getElementById('tb-mode');
  const connections = statusData?.connections || {};
  if (ps) {
    const ok = !!connections.ps_configured;
    ps.className = `badge ${ok ? 'b-ok' : 'b-err'}`;
    ps.textContent = ok ? '● PS Ready' : '● PS Missing Config';
  }
  if (ay) {
    const ok = !!connections.ay_configured;
    ay.className = `badge ${ok ? 'b-ok' : 'b-err'}`;
    ay.textContent = ok ? '● AY Ready' : '● AY Missing Config';
  }
  if (mode) {
    const running = !!statusData?.current_run;
    mode.className = `badge ${running ? 'b-warn' : 'b-gray'}`;
    mode.textContent = running ? 'RUNNING' : 'LIVE';
  }
}

function buildRuntimeSparkline(values) {
  const points = values.filter((v) => Number.isFinite(v) && v >= 0);
  if (!points.length) {
    return '<div style="height:46px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:11px;background:var(--surface2);border:1px solid var(--border);border-radius:7px;">No runtime data yet</div>';
  }
  if (points.length === 1) {
    const one = points[0].toFixed(2);
    return `<div style="height:46px;display:flex;align-items:center;justify-content:center;color:var(--text);font-size:12px;background:var(--surface2);border:1px solid var(--border);border-radius:7px;">${one}s</div>`;
  }
  const width = 360;
  const height = 46;
  const padX = 4;
  const padY = 4;
  const min = Math.min(...points);
  const max = Math.max(...points);
  const range = Math.max(0.001, max - min);
  const step = (width - padX * 2) / (points.length - 1);
  const coords = points.map((value, idx) => {
    const x = padX + idx * step;
    const y = height - padY - ((value - min) / range) * (height - padY * 2);
    return [x, y];
  });
  const path = coords.map((xy, idx) => `${idx === 0 ? 'M' : 'L'}${xy[0].toFixed(2)},${xy[1].toFixed(2)}`).join(' ');
  const area = `M${coords[0][0].toFixed(2)},${(height - padY).toFixed(2)} ${coords.map((xy) => `L${xy[0].toFixed(2)},${xy[1].toFixed(2)}`).join(' ')} L${coords[coords.length - 1][0].toFixed(2)},${(height - padY).toFixed(2)} Z`;
  const latest = points[points.length - 1].toFixed(2);
  const minLabel = min.toFixed(2);
  const maxLabel = max.toFixed(2);
  return `
    <svg viewBox="0 0 ${width} ${height}" width="100%" height="${height}" preserveAspectRatio="none" style="display:block;background:var(--surface2);border:1px solid var(--border);border-radius:7px;">
      <path d="${area}" fill="rgba(59,99,230,.12)"></path>
      <path d="${path}" fill="none" stroke="var(--accent)" stroke-width="2"></path>
      <circle cx="${coords[coords.length - 1][0].toFixed(2)}" cy="${coords[coords.length - 1][1].toFixed(2)}" r="2.5" fill="var(--accent2)"></circle>
      <text x="6" y="12" font-size="10" fill="var(--muted)">min ${minLabel}s</text>
      <text x="${(width - 90).toFixed(0)}" y="12" font-size="10" fill="var(--muted)">max ${maxLabel}s</text>
      <text x="${(width - 80).toFixed(0)}" y="${(height - 6).toFixed(0)}" font-size="10" fill="var(--text)">latest ${latest}s</text>
    </svg>`;
}
