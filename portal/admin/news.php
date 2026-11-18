<?php
/**
 * Public news posts — Admin only (create / edit / delete announcements).
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireRole(['Admin']);

$errors = [];
$editId = (int)($_GET['edit'] ?? $_POST['edit'] ?? 0);
$in = ['title' => '', 'content' => '', 'author' => '', 'published_date' => date('Y-m-d')];
$currentImage = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id, title FROM public_posts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $del = db()->prepare('DELETE FROM public_posts WHERE id = ?');
            $del->execute([$id]);
            audit_log('post_delete', 'public_posts', $id, $row['title']);
            flash('success', 'Post deleted.');
        } else {
            flash('warning', 'Post not found.');
        }
        redirect(purl('admin/news.php'));
    }

    /* create / update (optional image) */
    $editId = (int)($_POST['edit'] ?? 0);
    $in = [
        'title'          => trim((string)($_POST['title'] ?? '')),
        'content'        => trim((string)($_POST['content'] ?? '')),
        'author'         => trim((string)($_POST['author'] ?? '')),
        'published_date' => (string)($_POST['published_date'] ?? ''),
    ];

    if ($in['title'] === '' || mb_strlen($in['title']) > 200) {
        $errors[] = 'Title is required (max 200 characters).';
    }
    if ($in['content'] === '' || mb_strlen($in['content']) > 20000) {
        $errors[] = 'Content is required (max 20 000 characters).';
    }
    if (mb_strlen($in['author']) > 100) {
        $errors[] = 'Author must be 100 characters or fewer.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['published_date']) || !strtotime($in['published_date'])) {
        $errors[] = 'Valid publish date is required.';
    }

    $imgErr = null;
    $imgPath = null;
    if (!$errors && !empty($_FILES['image']['name'])) {
        $imgPath = handle_photo_upload($_FILES['image'], $imgErr);
        if ($imgErr !== null) {
            $errors[] = $imgErr;
        }
    }

    if (!$errors) {
        if ($editId > 0) {
            $stmt = db()->prepare('SELECT id, image_path FROM public_posts WHERE id = ?');
            $stmt->execute([$editId]);
            $row = $stmt->fetch();
            if (!$row) {
                $errors[] = 'Post not found.';
                $editId = 0;
            } elseif ($imgPath !== null && !empty($row['image_path'])) {
                delete_photo((string)$row['image_path']);
            }
        }
    }

    if (!$errors) {
        if ($editId > 0) {
            if ($imgPath !== null) {
                $upd = db()->prepare(
                    'UPDATE public_posts SET title = ?, content = ?, author = ?, published_date = ?, image_path = ? WHERE id = ?'
                );
                $upd->execute([$in['title'], $in['content'], $in['author'] ?: null, $in['published_date'], $imgPath, $editId]);
            } else {
                $upd = db()->prepare(
                    'UPDATE public_posts SET title = ?, content = ?, author = ?, published_date = ? WHERE id = ?'
                );
                $upd->execute([$in['title'], $in['content'], $in['author'] ?: null, $in['published_date'], $editId]);
            }
            audit_log('post_update', 'public_posts', $editId, $in['title']);
            flash('success', 'Post updated.');
        } else {
            $ins = db()->prepare(
                'INSERT INTO public_posts (title, content, author, image_path, published_date) VALUES (?,?,?,?,?)'
            );
            $ins->execute([$in['title'], $in['content'], $in['author'] ?: null, $imgPath, $in['published_date']]);
            audit_log('post_create', 'public_posts', (int)db()->lastInsertId(), $in['title']);
            flash('success', 'Post published.');
        }
        redirect(purl('admin/news.php'));
    }

    foreach ($errors as $err) {
        flash('danger', $err);
    }
    redirect(purl('admin/news.php' . ($editId > 0 ? '?edit=' . $editId : '')));
}

/* Load post for editing */
if ($editId > 0) {
    $stmt = db()->prepare('SELECT id, title, content, author, image_path, published_date FROM public_posts WHERE id = ?');
    $stmt->execute([$editId]);
    $row = $stmt->fetch();
    if ($row) {
        $in = [
            'title'          => (string)$row['title'],
            'content'        => (string)$row['content'],
            'author'         => (string)($row['author'] ?? ''),
            'published_date' => (string)$row['published_date'],
        ];
        $currentImage = $row['image_path'] ?? null;
    } else {
        $editId = 0;
    }
}

$posts = db()->query(
    'SELECT id, title, author, published_date FROM public_posts ORDER BY published_date DESC, id DESC'
)->fetchAll();

$page_title = 'News posts';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-megaphone me-2"></i>News posts</h1>
    <p class="text-muted mb-0">Announcements shown on the public website.</p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= e(app_base_path()) ?>/news.php" target="_blank" rel="noopener">
    <i class="bi bi-eye me-1"></i>View public news
  </a>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2 small"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold">
        <i class="bi <?= $editId > 0 ? 'bi-pencil-square' : 'bi-plus-lg' ?> me-2"></i>
        <?= $editId > 0 ? 'Edit post' : 'New post' ?>
      </div>
      <div class="card-body">
        <form method="post" class="needs-validation" enctype="multipart/form-data" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="edit" value="<?= (int)$editId ?>">
          <div class="mb-3">
            <label class="form-label" for="p_title">Title *</label>
            <input class="form-control" id="p_title" name="title" required maxlength="200"
                   value="<?= e($in['title']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="p_content">Content *</label>
            <textarea class="form-control" id="p_content" name="content" rows="8" required
                      maxlength="20000"><?= e($in['content']) ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label" for="p_image">Cover image (optional)</label>
            <input class="form-control" type="file" id="p_image" name="image"
                   accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <div class="form-text">JPG, PNG or WebP · max 2 MB.
              <?php if (!empty($currentImage)): ?>
                Current image will be replaced when you upload a new one.
              <?php endif; ?>
            </div>
            <?php if (!empty($currentImage)): ?>
              <img src="<?= e(photo_url((string)$currentImage)) ?>" alt="" class="mt-2 rounded" style="max-height:100px">
            <?php endif; ?>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label" for="p_author">Author</label>
              <input class="form-control" id="p_author" name="author" maxlength="100"
                     value="<?= e($in['author']) ?>" placeholder="Unit Public Relations">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="p_date">Publish date *</label>
              <input class="form-control" type="date" id="p_date" name="published_date" required
                     value="<?= e($in['published_date']) ?>">
            </div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check-lg me-1"></i><?= $editId > 0 ? 'Save changes' : 'Publish' ?>
            </button>
            <?php if ($editId > 0): ?>
              <a class="btn btn-outline-secondary" href="<?= e(purl('admin/news.php')) ?>">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-list-ul me-2"></i>Published posts</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" data-empty="No news posts published yet.">
          <thead class="table-dark">
            <tr><th>Title</th><th>Author</th><th>Date</th><th class="text-end">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($posts as $p): ?>
              <tr>
                <td><?= e($p['title']) ?></td>
                <td class="small text-muted"><?= e($p['author'] ?? '—') ?></td>
                <td class="small text-nowrap"><?= e(format_date($p['published_date'])) ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(purl('admin/news.php')) ?>?edit=<?= (int)$p['id'] ?>">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <form method="post" class="d-inline" data-confirm="Delete this post?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$posts): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">No posts yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
