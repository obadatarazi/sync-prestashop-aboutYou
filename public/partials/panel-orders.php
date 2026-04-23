<div class="panel" id="p-orders">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div><h2 style="font-size:15px;font-weight:600;">Orders</h2><p style="color:var(--muted);font-size:12px;margin-top:2px;">AY → PS import · Status sync PS → AY</p></div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-sm" id="import-orders-btn">⬇ Import from AY</button>
      <button class="btn btn-p btn-sm" id="push-status-btn">⟳ Push Status to AY</button>
    </div>
  </div>
  <div class="tabs" id="order-tabs">
    <div class="tab active" data-status="">All</div>
    <div class="tab" data-status="imported">Imported</div>
    <div class="tab" data-status="pending">Pending</div>
    <div class="tab" data-status="error">Errors</div>
    <div class="tab" data-status="quarantined">Quarantined</div>
  </div>
  <div class="tw"><table>
    <thead><tr>
      <th><input type="checkbox" id="check-all-orders" style="accent-color:var(--accent)"></th>
      <th>AY Order ID</th><th>PS Order</th><th>Customer</th><th>Total</th><th>AY Status</th><th>Sync Status</th><th>Items</th><th>Date</th><th></th>
    </tr></thead>
    <tbody id="orders-body"><tr><td colspan="10" class="loading"><span class="spin">⟳</span> Loading...</td></tr></tbody>
  </table></div>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
    <span style="font-size:11.5px;color:var(--muted);" id="orders-page-info"></span>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-sm" id="orders-prev">← Prev</button>
      <button class="btn btn-sm" id="orders-next">Next →</button>
    </div>
  </div>
</div>
