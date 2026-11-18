<?php
/**
 * Public landing page — hero, unit stats, recent highlights, portal CTA.
 * No authentication required (auth_check only bootstraps session/CSRF).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth_check.php';

/* Aggregate counts only — no personal details on the public site. */
$stats = ['cadets' => 0, 'years' => 0, 'posts' => 0];
try {
    $stats['cadets'] = (int)db()->query("SELECT COUNT(*) FROM cadets WHERE status = 'Active'")->fetchColumn();
    $first = db()->query('SELECT MIN(enrolled_date) FROM cadets')->fetchColumn();
    if ($first) {
        $y = (int)date('Y', strtotime((string)$first));
        $stats['years'] = max(0, (int)date('Y') - $y + 1);
    }
} catch (Throwable $ex) {
    error_log('[PUBLIC-HOME] ' . $ex->getMessage());
}
try {
    $recentPosts = db()->query(
        'SELECT id, title, published_date FROM public_posts ORDER BY published_date DESC LIMIT 3'
    )->fetchAll();
    $stats['posts'] = count($recentPosts);
} catch (Throwable $ex) {
    error_log('[PUBLIC-HOME] ' . $ex->getMessage());
    $recentPosts = [];
}

$page_title = 'Home';
require __DIR__ . '/includes/public_header.php';
?>

<div class="row justify-content-center text-center py-4 py-md-5">
  <div class="col-lg-8">
    <div class="mb-3">
      <i class="bi bi-shield-fill-check display-3 text-success"></i>
    </div>
    <h1 class="display-5 fw-bold mb-3"><?= e(system_name()) ?></h1>
    <p class="lead text-muted mb-4">
      Discipline · Leadership · Service. Preparing the next generation of cadets
      through training, community and excellence.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-2">
      <a href="<?= e(portal_base_path()) ?>/login.php" class="btn btn-primary btn-lg">
        <i class="bi bi-box-arrow-in-right me-2"></i>Member Portal Login
      </a>
      <a href="join.php" class="btn btn-success btn-lg">
        <i class="bi bi-person-plus me-2"></i>Join the Unit
      </a>
      <a href="contact.php" class="btn btn-outline-secondary btn-lg">
        <i class="bi bi-envelope me-2"></i>Contact
      </a>
    </div>
  </div>
</div>

<div class="row g-3 justify-content-center mb-5">
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0 text-center h-100">
      <div class="card-body">
        <div class="display-6 fw-bold text-success"><?= (int)$stats['cadets'] ?></div>
        <div class="small text-muted text-uppercase">Active cadets</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0 text-center h-100">
      <div class="card-body">
        <div class="display-6 fw-bold text-success"><?= (int)$stats['years'] ?></div>
        <div class="small text-muted text-uppercase">Years of service</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0 text-center h-100">
      <div class="card-body">
        <div class="display-6 fw-bold text-success">3</div>
        <div class="small text-muted text-uppercase">Training streams</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card shadow-sm border-0 text-center h-100">
      <div class="card-body">
        <div class="display-6 fw-bold text-success"><?= (int)$stats['posts'] ?></div>
        <div class="small text-muted text-uppercase">Latest updates</div>
      </div>
    </div>
  </div>
</div>

<?php if ($recentPosts): ?>
<div class="row justify-content-center mb-5">
  <div class="col-lg-8">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="h4 mb-0">Latest news</h2>
      <a href="news.php" class="btn btn-sm btn-outline-secondary">All news</a>
    </div>
    <div class="list-group shadow-sm">
      <?php foreach ($recentPosts as $p): ?>
        <a href="news.php#post-<?= (int)$p['id'] ?>" class="list-group-item list-group-item-action">
          <div class="d-flex w-100 justify-content-between">
            <h3 class="h6 mb-1"><?= e($p['title']) ?></h3>
            <small class="text-muted"><?= e(format_date($p['published_date'])) ?></small>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card shadow-sm border-0 bg-light">
      <div class="card-body text-center p-4">
        <h2 class="h5">Interested in joining?</h2>
        <p class="text-muted mb-3">Prospective cadets can apply online. External stakeholders can reach the unit through our contact page.</p>
        <a href="join.php" class="btn btn-success me-2">Apply to join</a>
        <a href="contact.php" class="btn btn-outline-secondary">Send an inquiry</a>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
