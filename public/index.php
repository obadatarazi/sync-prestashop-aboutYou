<?php
/**
 * SyncBridge admin UI shell.
 *
 * Markup is split into partials under `partials/`, styles live in
 * `assets/css/app.css`, and client-side logic is composed from classic
 * scripts under `assets/js/`. The backend contract in `api.php` is
 * untouched.
 */
$partials = __DIR__ . '/partials';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SyncBridge</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<?php include $partials . '/login.php'; ?>

<div id="app-screen" class="app" style="display:none">
  <?php include $partials . '/sidebar.php'; ?>

  <div class="main">
    <?php include $partials . '/topbar.php'; ?>

    <div class="content">
      <?php include $partials . '/panel-dashboard.php'; ?>
      <?php include $partials . '/panel-onboarding.php'; ?>
      <?php include $partials . '/panel-products.php'; ?>
      <?php include $partials . '/panel-quality.php'; ?>
      <?php include $partials . '/panel-images.php'; ?>
      <?php include $partials . '/panel-orders.php'; ?>
      <?php include $partials . '/panel-sync.php'; ?>
      <?php include $partials . '/panel-logs.php'; ?>
      <?php include $partials . '/panel-mappings.php'; ?>
      <?php include $partials . '/panel-attributes.php'; ?>
      <?php include $partials . '/panel-settings.php'; ?>
      <?php include $partials . '/panel-scheduler.php'; ?>
    </div>
  </div>
</div>

<div id="toasts"></div>
<?php include $partials . '/modal-category-products.php'; ?>
<?php include $partials . '/modal-order-edit.php'; ?>

<!-- Core: state → utils → api → router → auth -->
<script src="assets/js/core/state.js"></script>
<script src="assets/js/core/utils.js"></script>
<script src="assets/js/core/api.js"></script>
<script src="assets/js/core/router.js"></script>
<script src="assets/js/core/auth.js"></script>

<!-- Page modules (each exposes load*/render* and a wire*Page() helper) -->
<script src="assets/js/pages/dashboard.js"></script>
<script src="assets/js/pages/products.js"></script>
<script src="assets/js/pages/orders.js"></script>
<script src="assets/js/pages/images.js"></script>
<script src="assets/js/pages/quality.js"></script>
<script src="assets/js/pages/onboarding.js"></script>
<script src="assets/js/pages/logs.js"></script>
<script src="assets/js/pages/sync.js"></script>
<script src="assets/js/pages/settings.js"></script>
<script src="assets/js/pages/mappings.js"></script>
<script src="assets/js/pages/attributes.js"></script>
<script src="assets/js/pages/scheduler.js"></script>

<!-- Bootstrap: attaches static event listeners and resumes session -->
<script src="assets/js/main.js"></script>
</body>
</html>
