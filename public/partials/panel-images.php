<div class="panel" id="p-images">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div><h2 style="font-size:15px;font-weight:600;">Image Processing</h2><p style="color:var(--muted);font-size:12px;margin-top:2px;">3:4 ratio • Min 1125×1500px • JPEG stored on server</p></div>
    <button class="btn btn-p btn-sm" id="reprocess-images">⟳ Re-process Failed</button>
  </div>
  <div class="sg" id="image-stats"></div>
  <div class="card">
    <div class="ch"><div class="ct">Image Pipeline</div><span class="badge b-info">1125×1500 min</span></div>
    <div style="display:flex;align-items:center;gap:8px;padding:10px;background:var(--surface2);border-radius:8px;font-size:11.5px;margin-bottom:14px;flex-wrap:wrap;">
      <span class="badge b-info">PS URL</span><span style="color:var(--muted);">→ Download →</span>
      <span class="badge b-purple">Crop 3:4</span><span style="color:var(--muted);">→ Scale →</span>
      <span class="badge b-warn">JPEG 92%</span><span style="color:var(--muted);">→ Save →</span>
      <span class="badge b-ok">Public URL → AY</span>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Product</th><th>PS Image ID</th><th>Size</th><th>Status</th><th>Local Path</th><th>Processed</th></tr></thead>
      <tbody id="images-body"><tr><td colspan="6" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
    </table></div>
  </div>
</div>
