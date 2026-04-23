'use strict';

const schedulerJobs = {
  'products:inc': 'Incremental product sync',
  'products': 'Full product sync',
  'stock': 'Stock and price sync',
  'orders': 'Order import',
  'order-status': 'Order status push',
};

function schedulerDefaults() {
  return {
    'products:inc': { enabled: true, cadence: 'hourly', minute: 0, hour: 2, weekday: 1, monthday: 1 },
    'products': { enabled: false, cadence: 'daily', minute: 0, hour: 2, weekday: 1, monthday: 1 },
    'stock': { enabled: true, cadence: 'hourly', minute: 10, hour: 1, weekday: 1, monthday: 1 },
    'orders': { enabled: true, cadence: 'hourly', minute: 5, hour: 1, weekday: 1, monthday: 1 },
    'order-status': { enabled: true, cadence: 'hourly', minute: 15, hour: 1, weekday: 1, monthday: 1 },
  };
}

async function loadScheduler() {
  const tbody = document.getElementById('scheduler-body');
  tbody.innerHTML = '<tr><td colspan="5" class="loading"><span class="spin">⟳</span> Loading...</td></tr>';
  const r = await api('scheduler_get', {}, 'POST');
  const defaults = schedulerDefaults();
  schedulerDraft = { ...defaults, ...(r.ok && r.data.ok ? r.data.data : {}) };
  renderScheduler();
}

function renderScheduler() {
  const tbody = document.getElementById('scheduler-body');
  const phpPath = document.getElementById('scheduler-php-path').value || '/usr/bin/php';
  const syncPath = document.getElementById('scheduler-sync-path').value || '/path/to/bin/sync.php';
  const weekdayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  tbody.innerHTML = Object.entries(schedulerJobs).map(([command, label]) => {
    const cfg = schedulerDraft[command] || schedulerDefaults()[command];
    const cron = cfg.enabled ? cronExpressionForSchedule(cfg) : '# disabled';
    const line = cfg.enabled ? `${cron} ${phpPath} ${syncPath} ${command}` : '# disabled';
    return `<tr>
      <td><div style="font-size:12.5px;font-weight:500;">${esc(label)}</div><div class="mono">${esc(command)}</div></td>
      <td><label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" class="sched-enabled" data-command="${esc(command)}" ${cfg.enabled ? 'checked' : ''} style="accent-color:var(--accent)"><span>${cfg.enabled ? 'On' : 'Off'}</span></label></td>
      <td>
        <select class="fi sched-cadence" data-command="${esc(command)}" style="max-width:140px;">
          ${['hourly','daily','weekly','monthly'].map(v => `<option value="${v}" ${cfg.cadence===v?'selected':''}>${v}</option>`).join('')}
        </select>
      </td>
      <td>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <label style="font-size:11px;color:var(--muted);">Min <input class="fi sched-minute" data-command="${esc(command)}" type="number" min="0" max="59" value="${cfg.minute}" style="width:72px;"></label>
          <label style="font-size:11px;color:var(--muted);">Hour <input class="fi sched-hour" data-command="${esc(command)}" type="number" min="0" max="23" value="${cfg.hour}" style="width:72px;"></label>
          ${cfg.cadence === 'weekly' ? `<label style="font-size:11px;color:var(--muted);">Day <select class="fi sched-weekday" data-command="${esc(command)}" style="width:88px;">${weekdayNames.map((name, idx) => `<option value="${idx}" ${cfg.weekday==idx?'selected':''}>${name}</option>`).join('')}</select></label>` : ''}
          ${cfg.cadence === 'monthly' ? `<label style="font-size:11px;color:var(--muted);">Date <input class="fi sched-monthday" data-command="${esc(command)}" type="number" min="1" max="28" value="${cfg.monthday}" style="width:72px;"></label>` : ''}
        </div>
      </td>
      <td><div class="mono" style="white-space:pre-wrap;">${esc(line)}</div></td>
    </tr>`;
  }).join('');

  tbody.querySelectorAll('.sched-enabled').forEach(el => el.onchange = () => updateSchedulerField(el.dataset.command, 'enabled', el.checked));
  tbody.querySelectorAll('.sched-cadence').forEach(el => el.onchange = () => updateSchedulerField(el.dataset.command, 'cadence', el.value, true));
  tbody.querySelectorAll('.sched-minute').forEach(el => el.oninput = () => updateSchedulerField(el.dataset.command, 'minute', Number(el.value)));
  tbody.querySelectorAll('.sched-hour').forEach(el => el.oninput = () => updateSchedulerField(el.dataset.command, 'hour', Number(el.value)));
  tbody.querySelectorAll('.sched-weekday').forEach(el => el.onchange = () => updateSchedulerField(el.dataset.command, 'weekday', Number(el.value)));
  tbody.querySelectorAll('.sched-monthday').forEach(el => el.oninput = () => updateSchedulerField(el.dataset.command, 'monthday', Number(el.value)));
}

function updateSchedulerField(command, field, value, rerender=false) {
  const current = schedulerDraft[command] || schedulerDefaults()[command];
  schedulerDraft[command] = { ...current, [field]: value };
  if (rerender) {
    renderScheduler();
    return;
  }
  renderScheduler();
}

function cronExpressionForSchedule(cfg) {
  const minute = Math.max(0, Math.min(59, Number(cfg.minute || 0)));
  const hour = Math.max(0, Math.min(23, Number(cfg.hour || 0)));
  const weekday = Math.max(0, Math.min(6, Number(cfg.weekday || 0)));
  const monthday = Math.max(1, Math.min(28, Number(cfg.monthday || 1)));
  switch (cfg.cadence) {
    case 'hourly': return `${minute} * * * *`;
    case 'daily': return `${minute} ${hour} * * *`;
    case 'weekly': return `${minute} ${hour} * * ${weekday}`;
    case 'monthly': return `${minute} ${hour} ${monthday} * *`;
    default: return `${minute} * * * *`;
  }
}

function wireSchedulerPage() {
  document.getElementById('scheduler-refresh').addEventListener('click', loadScheduler);
  document.getElementById('scheduler-php-path').addEventListener('input', renderScheduler);
  document.getElementById('scheduler-sync-path').addEventListener('input', renderScheduler);
  document.getElementById('scheduler-save').addEventListener('click', async () => {
    const el = document.getElementById('scheduler-save-result');
    el.textContent = 'Saving...';
    const r = await api('scheduler_save', { schedules: schedulerDraft });
    if (r.ok && r.data.ok) {
      el.textContent = `Saved ${r.data.data.saved} schedules`;
      el.style.color = 'var(--green)';
      toast('Scheduler saved', 'ok');
    } else {
      el.textContent = r.data?.error || 'Save failed';
      el.style.color = 'var(--red)';
      toast('Scheduler save failed', 'err');
    }
  });
}
