<?php
/** Layout footer — closes main, loads local Bootstrap bundle + main.js. */
declare(strict_types=1);
?>
</main>

<footer class="cus-footer py-3 mt-auto">
  <div class="container-fluid px-3 px-md-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
    <small>&copy; <?= date('Y') ?> <?= e(system_name()) ?></small>
    <small><i class="bi bi-shield-check me-1"></i>Authorised use only</small>
  </div>
</footer>

<script src="<?= e(app_base_path()) ?>/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="<?= e(app_base_path()) ?>/assets/js/main.js"></script>
</body>
</html>
