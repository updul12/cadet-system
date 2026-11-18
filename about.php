<?php
/**
 * Public About page — history, structure, values. Static content only.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth_check.php';

$page_title = 'About';
require __DIR__ . '/includes/public_header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <h1 class="h2 mb-4">About the Unit</h1>

    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body p-4">
        <h2 class="h5"><i class="bi bi-history me-2"></i>History</h2>
        <p class="text-muted mb-0">
          Founded to develop disciplined, capable young leaders, <?= e(system_name()) ?>
          has grown into a vibrant unit combining military-style training, community
          service and academic excellence. Our cadets learn teamwork, resilience and
          responsibility through parade, field exercises and unit duties.
        </p>
      </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body p-4">
        <h2 class="h5"><i class="bi bi-diagram-3 me-2"></i>Organisation</h2>
        <ul class="text-muted mb-0">
          <li><strong>Commanding structure</strong> — student commanders supported by patron advisors.</li>
          <li><strong>Training streams</strong> — drill &amp; ceremonial, fieldcraft, and first aid / fitness.</li>
          <li><strong>Sections</strong> — administration, logistics and public communications.</li>
        </ul>
      </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body p-4">
        <h2 class="h5"><i class="bi bi-award me-2"></i>Core values</h2>
        <div class="row g-3">
          <div class="col-md-4">
            <div class="fw-semibold">Discipline</div>
            <div class="small text-muted">Standards in conduct, dress and duty.</div>
          </div>
          <div class="col-md-4">
            <div class="fw-semibold">Leadership</div>
            <div class="small text-muted">Leading by example at every level.</div>
          </div>
          <div class="col-md-4">
            <div class="fw-semibold">Service</div>
            <div class="small text-muted">Giving back to school and community.</div>
          </div>
        </div>
      </div>
    </div>

    <?php
    $leaders = [];
    try {
        $leaders = db()->query(
            "SELECT c.full_name, c.current_rank, c.`current_role`, c.department, c.photo
               FROM cadets c
               JOIN cadet_ranks r ON r.rank_name = c.current_rank
              WHERE c.status = 'Active' AND r.category = 'Officer'
              ORDER BY r.seniority_level DESC, c.full_name
              LIMIT 3"
        )->fetchAll();
    } catch (Throwable $ex) {
        error_log('[ABOUT-LEADERS] ' . $ex->getMessage());
    }
    $leaderRoles = ['Unit Commander', 'Adjutant', 'Training Instructor'];
    ?>
    <?php if ($leaders): ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-body p-4">
        <h2 class="h5"><i class="bi bi-person-badge me-2"></i>Key leadership</h2>
        <div class="row g-3 mt-1">
          <?php foreach ($leaders as $i => $L): ?>
            <div class="col-md-4">
              <div class="text-center">
                <?php if (!empty($L['photo']) && photo_url((string)$L['photo'])): ?>
                  <img src="<?= e(photo_url((string)$L['photo'])) ?>" alt=""
                       class="rounded-circle mb-2" style="width:88px;height:88px;object-fit:cover">
                <?php else: ?>
                  <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-2"
                       style="width:88px;height:88px;font-size:1.6rem;font-weight:600">
                    <?= e(strtoupper(mb_substr((string)$L['full_name'], 0, 1))) ?>
                  </div>
                <?php endif; ?>
                <div class="fw-semibold"><?= e($L['full_name']) ?></div>
                <div class="small text-primary"><?= e($L['current_rank']) ?></div>
                <div class="small text-muted"><?= e($leaderRoles[$i] ?? ($L['current_role'] ?? '')) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="text-center">
      <a href="contact.php" class="btn btn-outline-success">Contact us</a>
      <a href="join.php" class="btn btn-outline-primary">Join the unit</a>
      <a href="<?= e(portal_base_path()) ?>/login.php" class="btn btn-success">Member Portal</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
