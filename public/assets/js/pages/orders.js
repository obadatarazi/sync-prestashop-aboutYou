'use strict';

async function loadOrders() {
  const tbody = document.getElementById('orders-body');
  tbody.innerHTML = '<tr><td colspan="10" class="loading"><span class="spin">⟳</span></td></tr>';
  const r = await api('orders', { page: ordersPage, per_page: 20, status: ordersStatus });
  if (!r.ok || !r.data.ok) { tbody.innerHTML = '<tr><td colspan="10" style="color:var(--red);padding:14px;">Failed to load</td></tr>'; return; }
  const { rows, total, page, per_page } = r.data.data;
  ordersCache = rows || [];
  document.getElementById('orders-page-info').textContent = `Page ${page} · ${total} total`;
  document.getElementById('orders-prev').disabled = page <= 1;
  document.getElementById('orders-next').disabled = page * per_page >= total;

  if (!rows.length) { tbody.innerHTML = '<tr><td colspan="10" style="color:var(--muted);padding:14px;text-align:center;">No orders found</td></tr>'; return; }

  tbody.innerHTML = rows.map(o => `
    <tr>
      <td><input type="checkbox" class="oc" data-id="${o.id}" style="accent-color:var(--accent)"></td>
      <td class="mono">${esc((o.ay_order_id||'').slice(0,28))}</td>
      <td>${o.ps_order_id ? `<span class="badge b-ok">PS#${o.ps_order_id}</span>` : '<span class="badge b-gray">—</span>'}</td>
      <td>${esc(o.customer_name||o.customer_email||'—')}</td>
      <td>${o.total_paid ? '€'+parseFloat(o.total_paid).toFixed(2) : '—'}</td>
      <td>${esc(o.ay_status||'—')}</td>
      <td>${statusBadge(o.sync_status)}</td>
      <td>${o.item_count||0}</td>
      <td class="mono">${(o.created_at||'—').slice(0,10)}</td>
      <td><button class="btn btn-sm ${o.sync_status==='quarantined'?'btn-danger':''}" onclick="openOrderEditor(${Number(o.id||0)})">${o.sync_status==='quarantined'?'Retry / Edit':'View / Edit'}</button></td>
    </tr>`).join('');
}

function closeOrderEditorModal() {
  const modal = document.getElementById('order-edit-modal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  currentOrderEditId = 0;
  deletedOrderItemIds = [];
}

function renderOrderEditorItems(items) {
  const container = document.getElementById('order-edit-items');
  if (!container) return;
  if (!items.length) {
    container.innerHTML = '<div style="color:var(--muted);font-size:12px;">No items</div>';
    return;
  }
  container.innerHTML = items.map(item => {
    const itemId = Number(item.id || 0);
    const match = orderItemProductMap[String(itemId)] || null;
    const productBadge = match?.matched
      ? `<span class="badge b-ok">PS#${Number(match.product?.ps_id || 0)} · ${esc(match.product?.name || 'Matched')}</span>`
      : '<span class="badge b-err">No product match (SKU/EAN)</span>';
    return `
    <div class="oe-item-row" data-item-id="${itemId}" style="display:grid;grid-template-columns:90px 1.2fr 1fr 90px 120px 110px 130px 100px;gap:8px;align-items:end;margin-bottom:8px;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);">
      <div>
        <div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Item ID</div>
        <div class="mono">${itemId}</div>
        <div style="margin-top:6px;">${productBadge}</div>
      </div>
      <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">SKU</div><input class="fi oe-item-sku" data-item-id="${itemId}" value="${esc(item.sku || '')}"></label>
      <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">EAN</div><input class="fi oe-item-ean" data-item-id="${itemId}" value="${esc(item.ean13 || '')}"></label>
      <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Qty</div><input class="fi oe-item-qty" data-item-id="${itemId}" type="number" min="1" value="${Number(item.quantity || 1)}"></label>
      <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Unit Price</div><input class="fi oe-item-price" data-item-id="${itemId}" type="number" min="0" step="0.01" value="${Number(item.unit_price || 0)}"></label>
      <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Discount</div><input class="fi oe-item-discount" data-item-id="${itemId}" type="number" min="0" step="0.01" value="${Number(item.discount_amount || 0)}"></label>
      <label><div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Item Status</div><input class="fi oe-item-status" data-item-id="${itemId}" value="${esc(item.item_status || 'open')}"></label>
      <div>
        <div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Actions</div>
        <button class="btn btn-sm btn-danger oe-item-delete" data-item-id="${itemId}" type="button">Delete</button>
      </div>
    </div>
  `;}).join('');
  container.querySelectorAll('.oe-item-delete').forEach(btn => {
    btn.onclick = () => {
      const itemId = Number(btn.dataset.itemId || 0);
      if (itemId <= 0) return;
      deletedOrderItemIds.push(itemId);
      const row = container.querySelector(`.oe-item-row[data-item-id="${itemId}"]`);
      if (row) row.remove();
      if (!container.querySelector('.oe-item-row')) {
        container.innerHTML = '<div style="color:var(--muted);font-size:12px;">No items</div>';
      }
    };
  });
}

async function resolveOrderEditorItemProducts() {
  const rows = [...document.querySelectorAll('#order-edit-items .oe-item-row')];
  const items = rows.map(row => {
    const itemId = Number(row.dataset.itemId || 0);
    return {
      id: itemId,
      sku: document.querySelector(`.oe-item-sku[data-item-id="${itemId}"]`)?.value || '',
      ean13: document.querySelector(`.oe-item-ean[data-item-id="${itemId}"]`)?.value || '',
    };
  }).filter(item => item.id > 0);
  if (!items.length) return;
  const r = await api('order_item_products_resolve', { items }, 'POST');
  if (!r.ok || !r.data?.ok) return;
  orderItemProductMap = Object.fromEntries((r.data.data || []).map(row => [String(row.id), row]));
  const currentItems = rows.map(row => {
    const itemId = Number(row.dataset.itemId || 0);
    return {
      id: itemId,
      sku: document.querySelector(`.oe-item-sku[data-item-id="${itemId}"]`)?.value || '',
      ean13: document.querySelector(`.oe-item-ean[data-item-id="${itemId}"]`)?.value || '',
      quantity: Number(document.querySelector(`.oe-item-qty[data-item-id="${itemId}"]`)?.value || 1),
      unit_price: Number(document.querySelector(`.oe-item-price[data-item-id="${itemId}"]`)?.value || 0),
      discount_amount: Number(document.querySelector(`.oe-item-discount[data-item-id="${itemId}"]`)?.value || 0),
      item_status: document.querySelector(`.oe-item-status[data-item-id="${itemId}"]`)?.value || 'open',
    };
  });
  renderOrderEditorItems(currentItems);
  wireOrderItemResolveTriggers();
}

function wireOrderItemResolveTriggers() {
  document.querySelectorAll('#order-edit-items .oe-item-sku, #order-edit-items .oe-item-ean').forEach(input => {
    input.addEventListener('change', () => { resolveOrderEditorItemProducts(); });
    input.addEventListener('blur', () => { resolveOrderEditorItemProducts(); });
  });
}

async function openOrderEditor(orderId) {
  const order = (ordersCache || []).find(row => Number(row.id || 0) === Number(orderId));
  if (!order) {
    toast('Order not found in current page. Refresh and try again.', 'err');
    return;
  }
  currentOrderEditId = Number(orderId || 0);
  deletedOrderItemIds = [];
  orderItemProductMap = {};
  document.getElementById('order-edit-title').textContent = `Edit Order ${order.ay_order_id || order.id}`;
  document.getElementById('oe-order-id').textContent = String(order.id || '—');
  document.getElementById('oe-ay-order-id').textContent = String(order.ay_order_id || '—');
  document.getElementById('oe-customer-name').value = String(order.customer_name || '');
  document.getElementById('oe-customer-email').value = String(order.customer_email || '');
  document.getElementById('oe-total-paid').value = Number(order.total_paid || 0);
  document.getElementById('oe-total-products').value = Number(order.total_products || 0);
  document.getElementById('oe-total-shipping').value = Number(order.total_shipping || 0);
  document.getElementById('oe-discount-total').value = Number(order.discount_total || 0);
  document.getElementById('oe-currency').value = String(order.currency || 'EUR');
  document.getElementById('oe-shipping-country-iso').value = String(order.shipping_country_iso || '');
  document.getElementById('oe-billing-country-iso').value = String(order.billing_country_iso || '');
  document.getElementById('oe-shipping-method').value = String(order.shipping_method || '');
  document.getElementById('oe-payment-method').value = String(order.payment_method || '');
  document.getElementById('oe-shipping-address-json').value = String(order.shipping_address_json || '');
  document.getElementById('oe-billing-address-json').value = String(order.billing_address_json || '');
  document.getElementById('oe-ay-status').value = String(order.ay_status || 'open');
  document.getElementById('oe-sync-status').value = String(order.sync_status || 'pending');
  document.getElementById('oe-error-message').value = String(order.error_message || '');
  document.getElementById('order-edit-save-result').textContent = '';

  const modal = document.getElementById('order-edit-modal');
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');

  const itemsWrap = document.getElementById('order-edit-items');
  itemsWrap.innerHTML = '<div class="loading" style="padding:10px;"><span class="spin">⟳</span> Loading items...</div>';
  const itemsResp = await api('order_items', { order_id: Number(orderId || 0) }, 'POST');
  if (!itemsResp.ok || !itemsResp.data?.ok) {
    itemsWrap.innerHTML = `<div style="color:var(--red);font-size:12px;">${esc(itemsResp.data?.error || 'Failed to load items')}</div>`;
    return;
  }
  renderOrderEditorItems(itemsResp.data.data || []);
  wireOrderItemResolveTriggers();
  await resolveOrderEditorItemProducts();
}

async function saveOrderEditor() {
  if (!currentOrderEditId) return;
  const result = document.getElementById('order-edit-save-result');
  result.textContent = 'Saving...';
  result.style.color = 'var(--muted)';

  const itemIds = [...document.querySelectorAll('#order-edit-items .oe-item-sku')].map(el => Number(el.dataset.itemId || 0));
  const items = itemIds.map(itemId => ({
    id: itemId,
    sku: document.querySelector(`.oe-item-sku[data-item-id="${itemId}"]`)?.value || '',
    ean13: document.querySelector(`.oe-item-ean[data-item-id="${itemId}"]`)?.value || '',
    quantity: Number(document.querySelector(`.oe-item-qty[data-item-id="${itemId}"]`)?.value || 1),
    unit_price: Number(document.querySelector(`.oe-item-price[data-item-id="${itemId}"]`)?.value || 0),
    discount_amount: Number(document.querySelector(`.oe-item-discount[data-item-id="${itemId}"]`)?.value || 0),
    item_status: document.querySelector(`.oe-item-status[data-item-id="${itemId}"]`)?.value || 'open',
  }));

  const payload = {
    order_id: currentOrderEditId,
    order: {
      customer_name: document.getElementById('oe-customer-name').value,
      customer_email: document.getElementById('oe-customer-email').value,
      total_paid: Number(document.getElementById('oe-total-paid').value || 0),
      total_products: Number(document.getElementById('oe-total-products').value || 0),
      total_shipping: Number(document.getElementById('oe-total-shipping').value || 0),
      discount_total: Number(document.getElementById('oe-discount-total').value || 0),
      currency: document.getElementById('oe-currency').value,
      shipping_country_iso: document.getElementById('oe-shipping-country-iso').value,
      billing_country_iso: document.getElementById('oe-billing-country-iso').value,
      shipping_method: document.getElementById('oe-shipping-method').value,
      payment_method: document.getElementById('oe-payment-method').value,
      shipping_address_json: document.getElementById('oe-shipping-address-json').value,
      billing_address_json: document.getElementById('oe-billing-address-json').value,
      ay_status: document.getElementById('oe-ay-status').value,
      sync_status: document.getElementById('oe-sync-status').value,
      error_message: document.getElementById('oe-error-message').value,
    },
    items,
    deleted_item_ids: deletedOrderItemIds,
  };

  const r = await api('order_save', payload, 'POST');
  if (!r.ok || !r.data?.ok) {
    result.textContent = r.data?.error || 'Save failed';
    result.style.color = 'var(--red)';
    return;
  }

  result.textContent = 'Saved';
  result.style.color = 'var(--green)';
  toast('Order details updated', 'ok');
  await loadOrders();
}

async function pushEditedOrderToPrestashop() {
  if (!currentOrderEditId) return;
  const result = document.getElementById('order-edit-save-result');
  result.textContent = 'Pushing to PrestaShop...';
  result.style.color = 'var(--muted)';
  const r = await api('order_push', { order_id: currentOrderEditId }, 'POST');
  if (!r.ok || !r.data?.ok) {
    result.textContent = r.data?.error || 'Push failed';
    result.style.color = 'var(--red)';
    return;
  }
  const psOrderId = Number(r.data?.data?.ps_order_id || 0);
  const carrierId = Number(r.data?.data?.carrier_id || 0);
  result.textContent = psOrderId > 0
    ? `Pushed as PS#${psOrderId}${carrierId > 0 ? ` · Carrier#${carrierId}` : ''}`
    : 'Pushed';
  result.style.color = 'var(--green)';
  toast(
    psOrderId > 0
      ? `Order pushed to PrestaShop as PS#${psOrderId}${carrierId > 0 ? ` (carrier #${carrierId})` : ''}`
      : 'Order pushed to PrestaShop',
    'ok'
  );
  await loadOrders();
}

async function retryOrder(ayId) {
  toast(`Retrying order ${ayId}...`);
  const r = await api('sync', { command: 'orders' });
  if (r.ok) { toast('Order sync started', 'ok'); loadOrders(); }
}

function wireOrdersPage() {
  document.getElementById('import-orders-btn').addEventListener('click', () => runSync('orders'));
  document.getElementById('push-status-btn').addEventListener('click', () => runSync('order-status'));
  document.getElementById('orders-prev').addEventListener('click', () => { if (ordersPage > 1) { ordersPage--; loadOrders(); } });
  document.getElementById('orders-next').addEventListener('click', () => { ordersPage++; loadOrders(); });
  document.getElementById('order-edit-close').addEventListener('click', closeOrderEditorModal);
  document.getElementById('order-edit-cancel').addEventListener('click', closeOrderEditorModal);
  document.getElementById('order-edit-push').addEventListener('click', pushEditedOrderToPrestashop);
  document.getElementById('order-edit-save').addEventListener('click', saveOrderEditor);
  document.getElementById('order-edit-modal').addEventListener('click', (event) => {
    if (event.target?.id === 'order-edit-modal') {
      closeOrderEditorModal();
    }
  });
  document.querySelectorAll('#order-tabs .tab').forEach(t => {
    t.addEventListener('click', () => {
      document.querySelectorAll('#order-tabs .tab').forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      ordersStatus = t.dataset.status; ordersPage = 1; loadOrders();
    });
  });
}
