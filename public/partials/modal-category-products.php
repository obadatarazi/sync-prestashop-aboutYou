<div id="category-products-modal" class="modal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="category-products-title">Category products</div>
        <div class="modal-sub" id="category-products-subtitle"></div>
      </div>
      <button class="btn btn-sm" id="category-products-close">Close</button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
        <input class="fi" id="category-products-ay-category-id" style="max-width:220px;" placeholder="AY Category ID">
        <button class="btn btn-p btn-sm" id="category-products-assign-btn">Assign AY category to all</button>
        <button class="btn btn-sm" id="category-products-suggest-btn">Suggest per product</button>
        <span id="category-products-assign-result" style="font-size:12px;color:var(--muted);"></span>
      </div>
      <div class="modal-list" id="category-products-list"></div>
    </div>
  </div>
</div>
