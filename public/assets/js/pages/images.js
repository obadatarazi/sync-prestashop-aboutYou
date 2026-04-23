'use strict';

async function loadImages() {
  const r = await api('images', { page: 1, per_page: 24 });
  if (!r.ok || !r.data.ok) return;
  const { rows, stats } = r.data.data;

  document.getElementById('image-stats').innerHTML = `
    <div class="sc green"><div class="sl">OK</div><div class="sv" style="color:var(--green)">${stats.ok||0}</div></div>
    <div class="sc red"><div class="sl">Errors</div><div class="sv" style="color:var(--red)">${stats.error||0}</div></div>
    <div class="sc amber"><div class="sl">Pending</div><div class="sv">${stats.pending||0}</div></div>
    <div class="sc blue"><div class="sl">Total</div><div class="sv">${stats.total||0}</div></div>`;

  document.getElementById('images-body').innerHTML = rows.map(i => `
    <tr>
      <td>${esc(i.product_name||'')} <span class="mono">PS#${i.ps_id}</span></td>
      <td class="mono">${esc(i.ps_image_id||'—')}</td>
      <td>${i.width ? `${i.width}×${i.height}` : '—'}</td>
      <td>${statusBadge(i.status)}</td>
      <td class="mono" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(i.local_path||'—')}</td>
      <td class="mono">${(i.processed_at||'—').slice(0,16)}</td>
    </tr>`).join('');
}

function wireImagesPage() {
  document.getElementById('reprocess-images').addEventListener('click', () => { toast('Reprocessing via product sync...'); runSync('products'); });
}
