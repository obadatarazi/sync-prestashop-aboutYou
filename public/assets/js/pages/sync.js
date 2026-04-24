'use strict';

async function runSync(command, extra = {}) {
  const badge = document.getElementById('sync-badge');
  if (badge) { badge.className = 'badge b-warn'; badge.textContent = 'Running: ' + command; }
  toast(`Starting ${command} sync...`);

  const r = await api('sync', { command, async: true, ...extra });
  if (r.status === 409) { toast('Another sync is running', 'err'); return; }
  if (!r.ok) { toast('Sync failed to start', 'err'); return; }

  if (badge) { badge.className = 'badge b-ok'; badge.textContent = 'Started'; }
  toast(r.data?.data?.message || 'Sync started', 'ok');

  addSyncLog(`${now()} ⟳ ${command} started`);
  if (currentPage !== 'sync') goto('sync');
}

async function stopSync() {
  if (!selectedRunIds.length) {
    toast('Select at least one running Run ID first', 'err');
    return;
  }
  const preview = selectedRunIds.slice(0, 3).map(id => id.slice(0, 8)).join(', ');
  if (!confirm(`Stop selected sync run(s): ${preview}${selectedRunIds.length > 3 ? '...' : ''}?`)) return;
  const r = await api('sync_stop', { run_ids: selectedRunIds });
  if (!r.ok || !r.data?.ok) {
    toast(r.data?.error || 'Failed to stop sync', 'err');
    return;
  }
  if (r.data.data?.stopped) {
    toast('Running sync stopped', 'ok');
    addSyncLog(`${now()} ■ sync stop requested`);
  } else {
    toast(r.data.data?.message || 'No running sync found');
  }
  await loadDashboard();
  await loadRuns();
}

function updateSyncJob(run) {
  const el = document.getElementById('sync-job-detail');
  if (!el) return;
  if (!run || run.status !== 'running') {
    el.innerHTML = '<div style="color:var(--muted);font-size:12.5px;text-align:center;padding:16px;">No active job</div>';
    const badge = document.getElementById('sync-badge');
    if (badge && badge.textContent.startsWith('Running')) { badge.className = 'badge b-gray'; badge.textContent = 'Idle'; }
    return;
  }
  const pct = run.total_items > 0 ? Math.round(run.done_items / run.total_items * 100) : 0;
  el.innerHTML = `
    <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
      <span style="font-size:13px;font-weight:600;">⟳ ${esc(run.command)}</span>
      <span class="mono">${run.done_items}/${run.total_items} (${pct}%)</span>
    </div>
    <div class="pb" style="margin-bottom:10px;"><div class="pbf b pulse" style="width:${pct}%"></div></div>
    <div class="g3" style="margin-bottom:10px;">
      <div style="text-align:center;padding:8px;background:var(--surface2);border-radius:7px;">
        <div style="font-size:17px;font-weight:700;color:var(--green)">${run.pushed||0}</div><div style="font-size:9px;color:var(--muted)">PUSHED</div></div>
      <div style="text-align:center;padding:8px;background:var(--surface2);border-radius:7px;">
        <div style="font-size:17px;font-weight:700;color:var(--amber)">${run.skipped||0}</div><div style="font-size:9px;color:var(--muted)">SKIPPED</div></div>
      <div style="text-align:center;padding:8px;background:var(--surface2);border-radius:7px;">
        <div style="font-size:17px;font-weight:700;color:var(--red)">${run.failed||0}</div><div style="font-size:9px;color:var(--muted)">FAILED</div></div>
    </div>
    ${syncPid > 0 ? `<div style="font-size:11px;color:var(--muted);margin-bottom:6px;">PID: <span class="mono">${syncPid}</span></div>` : ''}
    ${run.current_product_id ? `<div style="font-size:11.5px;color:var(--muted)">Current: <strong style="color:var(--accent2)">PS#${run.current_product_id}</strong></div>` : ''}
    <div style="font-size:11.5px;color:var(--muted);margin-top:3px;">${esc(run.last_message||'')}</div>`;

  const badge = document.getElementById('sync-badge');
  if (badge) { badge.className = 'badge b-warn'; badge.textContent = `Running: ${run.command}`; }
}

async function loadRuns() {
  const r = await api('sync_runs', {});
  if (!r.ok || !r.data.ok) return;
  const tbody = document.getElementById('runs-body');
  const runs = r.data.data;
  const commandFilter = String(document.getElementById('run-filter-command')?.value || '').trim();
  const statusFilter = String(document.getElementById('run-filter-status')?.value || '').trim();
  const filteredRuns = runs.filter((run) => {
    if (commandFilter && String(run.command || '') !== commandFilter) return false;
    if (statusFilter && String(run.status || '') !== statusFilter) return false;
    return true;
  });
  const runningRunIds = runs
    .filter(run => run.status === 'running')
    .map(run => String(run.run_id || ''));
  selectedRunIds = selectedRunIds.filter(id => runningRunIds.includes(id));
  updateSelectedRunCount();
  tbody.innerHTML = filteredRuns.map(run => `
    <tr>
      <td><input type="checkbox" name="selected-run" value="${esc(run.run_id || '')}" ${selectedRunIds.includes(String(run.run_id || '')) ? 'checked' : ''} ${run.status === 'running' ? '' : 'disabled'}></td>
      <td class="mono">${esc((run.run_id||'').slice(0,8))}</td>
      <td>${esc(run.command)}</td>
      <td>${statusBadge(run.status)}</td>
      <td style="color:var(--green)">${run.pushed||0}</td>
      <td style="color:var(--red)">${run.failed||0}</td>
      <td class="mono">${run.elapsed_sec ? run.elapsed_sec+'s' : '—'}</td>
      <td class="mono">${(run.started_at||'—').slice(0,16)}</td>
    </tr>`).join('') || '<tr><td colspan="8" style="color:var(--muted);padding:14px;text-align:center;">No runs yet</td></tr>';
  tbody.querySelectorAll('input[name="selected-run"]').forEach((input) => {
    input.addEventListener('change', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement)) return;
      const id = target.value;
      if (target.checked) {
        if (!selectedRunIds.includes(id)) selectedRunIds.push(id);
      } else {
        selectedRunIds = selectedRunIds.filter(v => v !== id);
      }
      updateSelectedRunCount();
    });
  });
}

function addSyncLog(text) {
  const el = document.getElementById('sync-log');
  if (!el) return;
  const d = document.createElement('div');
  d.className = 'll';
  d.innerHTML = `<span class="linfo">${esc(text)}</span>`;
  el.appendChild(d);
  el.scrollTop = el.scrollHeight;
  while (el.children.length > 80) el.removeChild(el.firstChild);
}

function wireSyncPage() {
  document.getElementById('refresh-job').addEventListener('click', loadDashboard);
  document.getElementById('stop-sync-btn').addEventListener('click', stopSync);
  document.getElementById('run-filter-command')?.addEventListener('change', loadRuns);
  document.getElementById('run-filter-status')?.addEventListener('change', loadRuns);
}

function updateSelectedRunCount() {
  const el = document.getElementById('run-selected-count');
  if (!el) return;
  el.textContent = `${selectedRunIds.length} selected`;
  el.className = `badge ${selectedRunIds.length ? 'b-warn' : 'b-gray'}`;
}
