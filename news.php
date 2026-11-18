<?php
/**
 * Public news — published announcements from public_posts (read-only).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth_check.php';

$posts = [];
try {
    $posts = db()->query(
        'SELECT id, title, content, author, image_path, published_date
           FROM public_posts
          ORDER BY published_date DESC
          LIMIT 50'
    )->fetchAll();
} catch (Throwable $ex) {
    error_log('[PUBLIC-NEWS] ' . $ex->getMessage());
}

$page_title = 'News & Events';
require __DIR__ . '/includes/public_header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <h1 class="h2 mb-4">News &amp; Events</h1>

    <?php if (!$posts): ?>
      <div class="alert alert-light border">No announcements published yet — check back soon.</div>
    <?php endif; ?>

    <?php foreach ($posts as $p): ?>
      <article class="card shadow-sm border-0 mb-3" id="post-<?= (int)$p['id'] ?>">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
            <h2 class="h5 mb-0"><?= e($p['title']) ?></h2>
            <span class="text-nowrap small text-muted"><?= e(format_date($p['published_date'])) ?></span>
          </div>
          <?php if (!empty($p['author'])): ?>
            <div class="small text-muted mb-2">By <?= e($p['author']) ?></div>
          <?php endif; ?>
          <?php if (!empty($p['image_path'])): ?>
            <img src="<?= e(photo_url((string)$p['image_path'])) ?>" alt=""
                 class="img-fluid rounded mb-3" style="max-height:360px;width:100%;object-fit:cover">
          <?php endif; ?>
          <div class="text-muted" style="white-space:pre-wrap"><?= e($p['content']) ?></div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
