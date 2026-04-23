'use strict';

async function loadQuality() {
  const r = await api('status', {});
  if (!r.ok) return;
  const ps = r.data.data?.products || {};
  const is = r.data.data?.images || {};
  const totalProducts = Number(ps.total || 0);
  const syncErrors = Number(ps.error || 0);
  const cleanProducts = Math.max(0, totalProducts - syncErrors);
  const totalImages = Number(is.total || 0);
  const imageErrors = Number(is.error || 0);
  const imageOk = Number(is.ok || 0);
  const imageHealth = totalImages > 0 ? ((imageOk / totalImages) * 100) : 100;
  const productHealth = totalProducts > 0 ? ((cleanProducts / totalProducts) * 100) : 100;
  const overallHealth = Math.round((imageHealth + productHealth) / 2);

  document.getElementById('quality-stats').innerHTML = `
    <div class="sc ${overallHealth >= 90 ? 'green' : overallHealth >= 70 ? 'amber' : 'red'}">
      <div class="sl">Overall Health</div>
      <div class="sv" style="color:${overallHealth >= 90 ? 'var(--green)' : overallHealth >= 70 ? 'var(--amber)' : 'var(--red)'}">${overallHealth}%</div>
    </div>
    <div class="sc green"><div class="sl">Products Loaded</div><div class="sv">${totalProducts}</div></div>
    <div class="sc ${imageErrors > 0 ? 'amber' : 'green'}"><div class="sl">Images Ready</div><div class="sv">${imageOk}/${totalImages}</div></div>
    <div class="sc ${imageErrors > 0 ? 'red' : 'green'}"><div class="sl">Image Failures</div><div class="sv" style="color:${imageErrors > 0 ? 'var(--red)' : 'var(--green)'}">${imageErrors}</div></div>
    <div class="sc ${syncErrors > 0 ? 'amber' : 'green'}"><div class="sl">Products Blocked</div><div class="sv" style="color:${syncErrors > 0 ? 'var(--amber)' : 'var(--green)'}">${syncErrors}</div></div>`;

  document.getElementById('quality-attrs').innerHTML = `
    <div class="ct" style="margin-bottom:12px;">Attribute Status</div>
    <div style="font-size:11px;color:var(--muted);margin-bottom:10px;">Mapped values required for clean AY export</div>
    <div style="display:flex;flex-wrap:wrap;gap:7px;">
      <span class="badge b-ok">✓ Name</span>
      <span class="badge b-ok">✓ Price</span>
      <span class="badge b-info">→ Brand</span>
      <span class="badge b-info">→ Category</span>
      <span class="badge b-info">→ Size Map</span>
      <span class="badge b-info">→ Color Map</span>
      ${imageErrors > 0 ? `<span class="badge b-warn">⚠ Images (${imageErrors})</span>` : ''}
      ${syncErrors > 0 ? `<span class="badge b-err">✗ Sync Errors (${syncErrors})</span>` : ''}
    </div>`;

  document.getElementById('quality-issues').innerHTML = `
    <div class="ct" style="margin-bottom:12px;">Issues</div>
    <div style="font-size:11px;color:var(--muted);margin-bottom:10px;">Items below can prevent successful sync to AboutYou</div>
    ${imageErrors > 0 ? `<div style="display:flex;gap:10px;align-items:center;padding:10px;background:var(--amber-soft);border:1px solid rgba(180,83,9,.22);border-radius:8px;margin-bottom:8px;">
      <span>⚠</span><div style="flex:1"><div style="font-size:12.5px;font-weight:500;color:var(--amber)">${imageErrors} images failed processing</div><div style="font-size:11px;color:var(--muted)">Usually caused by unreachable or invalid PrestaShop image URLs</div></div>
      <button class="btn btn-sm" onclick="runSync('products')">Retry</button></div>` : ''}
    ${syncErrors > 0 ? `<div style="display:flex;gap:10px;align-items:center;padding:10px;background:var(--red-soft);border:1px solid rgba(185,28,28,.22);border-radius:8px;margin-bottom:8px;">
      <span>✗</span><div style="flex:1"><div style="font-size:12.5px;font-weight:500;color:var(--red)">${syncErrors} products are currently blocked</div><div style="font-size:11px;color:var(--muted)">Open Products and filter by Errors to inspect root causes</div></div>
      <button class="btn btn-sm" onclick="goto('products')">View</button></div>` : ''}
    ${!imageErrors && !syncErrors ? '<div style="color:var(--green);font-size:13px;padding:10px;">✓ No blocking quality issues detected</div>' : ''}`;
}
