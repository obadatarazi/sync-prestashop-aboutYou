<nav class="sidebar">
  <div class="s-logo">
    <div class="s-icon">S</div>
    <div class="s-name">Sync<span>Bridge</span></div>
  </div>

  <div class="s-section">
    <div class="s-lbl">Overview</div>
    <div class="nav active" data-page="dashboard"><span class="nav-ico">◉</span> Dashboard</div>
    <div class="nav" data-page="onboarding"><span class="nav-ico">✦</span> Onboarding</div>
    <div class="nav" data-page="mappings"><span class="nav-ico">⇄</span> Category Mapping</div>
  </div>
  <div class="s-section">
    <div class="s-lbl">Catalog</div>
    <div class="nav" data-page="products"><span class="nav-ico">▣</span> Products <span class="nav-badge g" id="nb-products">—</span></div>
    <div class="nav" data-page="quality"><span class="nav-ico">◈</span> Quality Check</div>
    <div class="nav" data-page="images"><span class="nav-ico">◫</span> Images <span class="nav-badge" id="nb-images" style="display:none"></span></div>
    <div class="nav" data-page="attributes"><span class="nav-ico">⊞</span> Attribute Mapping</div>
  </div>
  <div class="s-section">
    <div class="s-lbl">Commerce</div>
    <div class="nav" data-page="orders"><span class="nav-ico">☰</span> Orders <span class="nav-badge" id="nb-orders" style="display:none"></span></div>
    <div class="nav" data-page="sync"><span class="nav-ico">⟳</span> Sync Engine</div>
  </div>
  <div class="s-section">
    <div class="s-lbl">System</div>
    <div class="nav" data-page="logs"><span class="nav-ico">▤</span> Logs</div>
    <div class="nav" data-page="scheduler"><span class="nav-ico">⏲</span> Scheduler</div>
    <div class="nav" data-page="settings"><span class="nav-ico">⚙</span> Settings</div>
  </div>

  <div class="s-foot">
    <div style="font-size:11.5px;color:var(--muted);display:flex;align-items:center;"><span class="s-dot"></span> <span id="conn-status">Connected</span></div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">Logged in as <strong id="whoami" style="color:var(--text);">—</strong></div>
    <button class="btn btn-sm" style="margin-top:8px;width:100%;justify-content:center;" id="logout-btn">Log out</button>
  </div>
</nav>
