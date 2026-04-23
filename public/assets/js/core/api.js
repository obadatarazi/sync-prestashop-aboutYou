'use strict';

async function api(action, body = {}, method = 'POST') {
  const payload = { action, ...body };
  if (csrf && action !== 'login') payload.csrf = csrf;
  const resp = await fetch(API, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    credentials: 'same-origin',
  });
  const data = await resp.json().catch(() => ({}));
  return { ok: resp.ok, status: resp.status, data };
}
