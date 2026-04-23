'use strict';

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function parseLogContext(context) {
  if (context == null || context === '') return '';
  if (typeof context === 'string') {
    try {
      const parsed = JSON.parse(context);
      return JSON.stringify(parsed, null, 2);
    } catch {
      return context.trim();
    }
  }
  try {
    return JSON.stringify(context, null, 2);
  } catch {
    return String(context);
  }
}
function shortLogContext(contextText, maxLen = 90) {
  const oneLine = String(contextText || '').replace(/\s+/g, ' ').trim();
  if (!oneLine) return '—';
  return oneLine.length > maxLen ? `${oneLine.slice(0, maxLen - 1)}…` : oneLine;
}
function now() { return new Date().toTimeString().slice(0,8); }
function toast(msg, type='info') {
  const d = document.createElement('div');
  d.className = 'toast';
  d.style.borderLeftColor = type==='ok' ? 'var(--green)' : type==='err' ? 'var(--red)' : 'var(--accent)';
  d.style.borderLeft = '3px solid';
  d.textContent = msg;
  document.getElementById('toasts').appendChild(d);
  setTimeout(() => d.remove(), 3500);
}
function statusBadge(s) {
  const map = {
    synced:'b-ok',pending:'b-gray',error:'b-err',quarantined:'b-err',
    imported:'b-ok',importing:'b-info',status_pushed:'b-ok',running:'b-warn',
    completed:'b-ok',failed:'b-err',ok:'b-ok',processing:'b-info',
  };
  return `<span class="badge ${map[s]||'b-gray'}">${esc(s)}</span>`;
}
function renderProductThumbCell(product) {
  const imageUrl = String(product?.image_thumb_url || '').trim();
  if (!imageUrl) {
    return (Number(product?.image_count || 0) > 0 ? '🖼' : '⚠');
  }
  return `<img src="${esc(imageUrl)}" alt="${esc(product?.name || 'Product')}" loading="lazy" style="width:100%;height:100%;object-fit:cover;border-radius:6px;" onerror="this.onerror=null;this.replaceWith(document.createTextNode('⚠'));"/>`;
}
function euro(v) {
  return '€' + Number(v || 0).toFixed(2);
}
function shortDate(v) {
  return v ? String(v).slice(0, 19).replace('T', ' ') : '—';
}
function isValidEan13(value) {
  if (!/^\d{13}$/.test(String(value || ''))) return false;
  const digits = String(value).split('').map(Number);
  const checkDigit = digits.pop();
  let sum = 0;
  digits.forEach((digit, idx) => {
    sum += digit * (idx % 2 === 0 ? 1 : 3);
  });
  const computed = (10 - (sum % 10)) % 10;
  return computed === checkDigit;
}
