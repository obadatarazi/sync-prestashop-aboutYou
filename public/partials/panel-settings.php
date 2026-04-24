<div class="panel" id="p-settings">
  <div style="margin-bottom:16px;"><h2 style="font-size:15px;font-weight:600;">Settings</h2><p style="color:var(--muted);font-size:12px;margin-top:2px;">Stored in database and synced to .env</p></div>
  <div class="card" style="margin-bottom:14px;">
    <div class="ct" style="margin-bottom:8px;">Feature Flags</div>
    <div id="feature-flags" style="color:var(--muted);font-size:12px;">Loading feature flags...</div>
  </div>
  <div id="settings-form"></div>
  <div style="display:flex;gap:8px;margin-top:16px;">
    <button class="btn btn-p" id="save-settings">Save Settings</button>
    <button class="btn btn-sm" id="test-conn">● Test Connection</button>
    <span id="conn-result" style="font-size:12px;color:var(--muted);align-self:center;"></span>
  </div>
</div>
