<?php
/** Public site footer. */
declare(strict_types=1);
?>
</main>

<footer class="cus-footer py-3 mt-auto">
  <div class="container-fluid px-3 px-md-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
    <small>&copy; <?= date('Y') ?> <?= e(system_name()) ?></small>
    <small>
      <a href="<?= e(portal_base_path()) ?>/login.php" class="link-light text-decoration-none">
        <i class="bi bi-shield-lock me-1"></i>Member Portal
      </a>
    </small>
  </div>
</footer>

<?php $waNumber = whatsapp_number(); ?>
<?php if ($waNumber !== ''): ?>
<!-- Floating WhatsApp click-to-chat -->
<a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener"
   class="position-fixed bottom-0 end-0 m-3 m-md-4 d-flex align-items-center justify-content-center rounded-circle shadow-lg"
   style="width:56px;height:56px;background:#25D366;color:#fff;z-index:1030;text-decoration:none;"
   title="Chat with us on WhatsApp" aria-label="Chat with us on WhatsApp">
  <i class="bi bi-whatsapp fs-3"></i>
</a>
<?php endif; ?>

<script src="<?= e(app_base_path()) ?>/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="<?= e(app_base_path()) ?>/assets/js/main.js"></script>
</body>
</html>
