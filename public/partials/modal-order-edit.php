<div id="order-edit-modal" class="modal" aria-hidden="true">
  <div class="modal-card" style="width:min(980px,calc(100vw - 28px));">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="order-edit-title">Edit Order</div>
        <div class="modal-sub">Order #<span id="oe-order-id">—</span> · AY <span id="oe-ay-order-id">—</span></div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <span id="order-edit-save-result" style="font-size:12px;color:var(--muted);"></span>
        <button class="btn btn-sm" id="order-edit-close">Close</button>
      </div>
    </div>
    <div class="modal-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Customer Name</div><input id="oe-customer-name" class="fi"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Customer Email</div><input id="oe-customer-email" class="fi"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Total Paid</div><input id="oe-total-paid" class="fi" type="number" step="0.01" min="0"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Total Products</div><input id="oe-total-products" class="fi" type="number" step="0.01" min="0"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Total Shipping</div><input id="oe-total-shipping" class="fi" type="number" step="0.01" min="0"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Discount Total</div><input id="oe-discount-total" class="fi" type="number" step="0.01" min="0"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Currency</div><input id="oe-currency" class="fi" maxlength="3"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Shipping Country ISO</div><input id="oe-shipping-country-iso" class="fi" maxlength="2" placeholder="DE"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Billing Country ISO</div><input id="oe-billing-country-iso" class="fi" maxlength="2" placeholder="DE"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Shipping Method</div><input id="oe-shipping-method" class="fi"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Payment Method</div><input id="oe-payment-method" class="fi"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">AY Status</div><input id="oe-ay-status" class="fi"></label>
        <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Sync Status</div>
          <select id="oe-sync-status" class="fi">
            <option value="pending">pending</option>
            <option value="importing">importing</option>
            <option value="imported">imported</option>
            <option value="status_pushed">status_pushed</option>
            <option value="error">error</option>
            <option value="quarantined">quarantined</option>
          </select>
        </label>
      </div>
      <label style="display:block;margin-bottom:10px;"><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Shipping Address JSON</div><textarea id="oe-shipping-address-json" class="fi" rows="4" placeholder='{"address1":"Street 1","postcode":"10115","city":"Berlin","country_iso":"DE"}'></textarea></label>
      <label style="display:block;margin-bottom:10px;"><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Billing Address JSON</div><textarea id="oe-billing-address-json" class="fi" rows="4" placeholder='{"address1":"Street 1","postcode":"10115","city":"Berlin","country_iso":"DE"}'></textarea></label>
      <label style="display:block;margin-bottom:10px;"><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Error Message</div><textarea id="oe-error-message" class="fi" rows="2"></textarea></label>
      <div class="ct" style="margin-bottom:8px;">Items</div>
      <div id="order-edit-items"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">
        <button class="btn btn-sm" id="order-edit-cancel">Cancel</button>
        <button class="btn btn-sm" id="order-edit-push">Push to PrestaShop</button>
        <button class="btn btn-p btn-sm" id="order-edit-save">Save changes</button>
      </div>
    </div>
  </div>
</div>
