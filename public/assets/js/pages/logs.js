'use strict';

async function loadLogs() {
  const tbody = document.getElementById('logs-body');
  const metaEl = document.getElementById('logs-meta');
  tbody.innerHTML = '<tr><td colspan="5" class="loading"><span class="spin">⟳</span></td></tr>';
  const r = await api('logs', {
    level: document.getElementById('log-level')?.value,
    channel: document.getElementById('log-channel')?.value,
    search: document.getElementById('log-search')?.value,
    page: logsPage,
    per_page: logsPerPage,
  });
  if (!r.ok || !r.data.ok) return;
  const payload = r.data.data || {};
  const logs = payload.rows || [];
  const total = Number(payload.total || 0);
  const pages = Math.max(1, Number(payload.pages || 1));
  logsPage = Math.min(Math.max(1, Number(payload.page || logsPage)), pages);
  if (metaEl) {
    metaEl.textContent = `Page ${logsPage} / ${pages} · ${total} item${total === 1 ? '' : 's'}`;
  }
  const prevBtn = document.getElementById('logs-prev');
  const nextBtn = document.getElementById('logs-next');
  if (prevBtn) prevBtn.disabled = logsPage <= 1;
  if (nextBtn) nextBtn.disabled = logsPage >= pages;
  tbody.innerHTML = logs.map(l => {
    const cls = l.level==='error'||l.level==='critical' ? 'b-err' : l.level==='warning' ? 'b-warn' : 'b-ok';
    const ctxFull = parseLogContext(l.context);
    const ctxShort = shortLogContext(ctxFull);
    return `<tr class="log-row" onclick="toggleLogContext(this)">
      <td class="mono">${esc((l.created_at||'').slice(0,19))}</td>
      <td><span class="badge ${cls}">${esc(l.level)}</span></td>
      <td><span style="font-size:11px;background:var(--surface3);padding:2px 6px;border-radius:4px;color:var(--muted);">${esc(l.channel)}</span></td>
      <td style="font-size:12px;max-width:380px;">${esc(l.message)}</td>
      <td class="mono log-context-preview" title="${esc(ctxFull || 'No context')}">${esc(ctxShort)}</td>
    </tr>
    <tr class="log-context-details" style="display:none;">
      <td colspan="5">
        <div class="log-context-wrap">
          <div class="log-context-title">Context details</div>
          <pre class="log-context-full">${esc(ctxFull || 'No additional context')}</pre>
        </div>
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="5" style="color:var(--muted);padding:14px;text-align:center;">No logs found</td></tr>';
}

function toggleLogContext(row) {
  if (!row) return;
  const detailsRow = row.nextElementSibling;
  if (!detailsRow || !detailsRow.classList.contains('log-context-details')) return;
  const isOpen = detailsRow.style.display !== 'none';
  detailsRow.style.display = isOpen ? 'none' : '';
  row.classList.toggle('expanded', !isOpen);
}

async function deleteLogs() {
  if (!confirm('Delete all sync logs and clear log files? This cannot be undone.')) return;
  const r = await api('logs_delete', { clear_files: true });
  if (!r.ok || !r.data?.ok) {
    toast(r.data?.error || 'Failed to delete logs', 'err');
    return;
  }
  const deleted = r.data?.data?.deleted_db_logs ?? 0;
  toast(`Deleted ${deleted} DB log entries`, 'ok');
  await loadLogs();
  await loadDashboard();
  await loadRuns();
}

function wireLogsPage() {
  document.getElementById('log-refresh').addEventListener('click', loadLogs);
  document.getElementById('log-search').addEventListener('input', () => { logsPage = 1; loadLogs(); });
  document.getElementById('log-level').addEventListener('change', () => { logsPage = 1; loadLogs(); });
  document.getElementById('log-channel').addEventListener('change', () => { logsPage = 1; loadLogs(); });
  document.getElementById('logs-prev').addEventListener('click', () => {
    if (logsPage > 1) {
      logsPage -= 1;
      loadLogs();
    }
  });
  document.getElementById('logs-next').addEventListener('click', () => {
    logsPage += 1;
    loadLogs();
  });
  document.getElementById('delete-logs-btn').addEventListener('click', deleteLogs);
}
