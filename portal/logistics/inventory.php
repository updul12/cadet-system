<?php
/**
 * Inventory — gear issuance and returns.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth_check.php';

$user = requireLogin();
requireModule('operations');

$errors = [];
$CONDITIONS = ['New', 'Good', 'Fair', 'Damaged'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = requireRole(['Admin', 'Officer']);
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $user = requireRole(['Admin']);
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id, item_name, serial_no FROM inventory WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $del = db()->prepare('DELETE FROM inventory WHERE id = ?');
            $del->execute([$id]);
            audit_log('inventory_delete', 'inventory', $id, $row['serial_no']);
            flash('success', 'Item “' . $row['item_name'] . '” deleted.');
        } else {
            flash('warning', 'Item not found.');
        }
        redirect(purl('logistics/inventory.php'));
    }

    if ($action === 'return') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT id, item_name, assigned_cadet_id FROM inventory WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash('warning', 'Item not found.');
            redirect(purl('logistics/inventory.php'));
        }
        $upd = db()->prepare(
            'UPDATE inventory SET assigned_cadet_id = NULL, date_issued = NULL, date_returned = CURDATE()
              WHERE id = ?'
        );
        $upd->execute([$id]);
        audit_log('inventory_return', 'inventory', $id, $row['item_name']);
        flash('success', '“' . $row['item_name'] . '” marked returned.');
        redirect(purl('logistics/inventory.php'));
    }

    /* create / update */
    $id           = (int)($_POST['id'] ?? 0);
    $itemName     = trim((string)($_POST['item_name'] ?? ''));
    $serialNo     = trim((string)($_POST['serial_no'] ?? ''));
    $category     = trim((string)($_POST['category'] ?? 'General'));
    $condition    = (string)($_POST['item_condition'] ?? 'Good');
    $assignedId   = (int)($_POST['assigned_cadet_id'] ?? 0);
    $dateIssued   = (string)($_POST['date_issued'] ?? '');
    $dateReturned = (string)($_POST['date_returned'] ?? '');

    if ($itemName === '' || mb_strlen($itemName) > 120) {
        $errors[] = 'Item name is required (max 120 chars).';
    }
    if (!preg_match('/^[A-Za-z0-9\-_\/.]{3,60}$/', $serialNo)) {
        $errors[] = 'Serial number is required (3–60 chars: letters, numbers, - _ / .).';
    }
    if ($category === '' || mb_strlen($category) > 60) {
        $errors[] = 'Category is required (max 60 chars).';
    }
    if (!in_array($condition, $CONDITIONS, true)) {
        $errors[] = 'Invalid condition.';
    }
    if ($dateIssued !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIssued) || !strtotime($dateIssued))) {
        $errors[] = 'Invalid issue date.';
    }
    if ($dateReturned !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateReturned) || !strtotime($dateReturned))) {
        $errors[] = 'Invalid return date.';
    }
    if ($assignedId > 0) {
        $stmt = db()->prepare('SELECT id FROM cadets WHERE id = ?');
        $stmt->execute([$assignedId]);
        if (!$stmt->fetch()) {
            $errors[] = 'Assigned cadet not found.';
        }
        if ($dateIssued === '') {
            $dateIssued = date('Y-m-d');
        }
    } else {
        $assignedId = null;
        $dateIssued = $dateIssued !== '' ? $dateIssued : null;
    }
    $dateReturned = $dateReturned !== '' ? $dateReturned : null;

    /* Unique serial (exclude self) */
    if (!$errors) {
        $sql = 'SELECT COUNT(*) FROM inventory WHERE serial_no = ?';
        $args = [$serialNo];
        if ($id > 0) {
            $sql .= ' AND id <> ?';
            $args[] = $id;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'Serial number “' . $serialNo . '” already exists.';
        }
    }

    if (!$errors) {
        try {
            if ($id > 0) {
                $stmt = db()->prepare(
                    'UPDATE inventory SET item_name=?, serial_no=?, category=?, item_condition=?,
                            assigned_cadet_id=?, date_issued=?, date_returned=? WHERE id=?'
                );
                $stmt->execute([$itemName, $serialNo, $category, $condition,
                    $assignedId, $dateIssued, $dateReturned, $id]);
                audit_log('inventory_update', 'inventory', $id, $serialNo);
                flash('success', 'Item “' . $itemName . '” updated.');
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO inventory (item_name, serial_no, category, item_condition,
                                            assigned_cadet_id, date_issued, date_returned)
                     VALUES (?,?,?,?,?,?,?)'
                );
                $stmt->execute([$itemName, $serialNo, $category, $condition,
                    $assignedId, $dateIssued, $dateReturned]);
                $newId = (int)db()->lastInsertId();
                audit_log('inventory_create', 'inventory', $newId, $serialNo);
                flash('success', 'Item “' . $itemName . '” added to inventory.');
            }
            redirect(purl('logistics/inventory.php'));
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'Serial number already exists.';
            } else {
                throw $ex;
            }
        }
    }
}

/* List with optional search */
$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT i.*, c.full_name AS assignee, c.service_no AS assignee_no
          FROM inventory i LEFT JOIN cadets c ON c.id = i.assigned_cadet_id';
$args = [];
if ($q !== '') {
    $sql .= ' WHERE i.item_name LIKE ? OR i.serial_no LIKE ? OR i.category LIKE ?';
    $like = '%' . $q . '%';
    $args = [$like, $like, $like];
}
$sql .= ' ORDER BY i.item_name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$items = $stmt->fetchAll();

$cadets = db()->query(
    "SELECT id, service_no, full_name FROM cadets WHERE status = 'Active' ORDER BY full_name"
)->fetchAll();

/* Edit target */
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM inventory WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$page_title = 'Inventory';
$page_base  = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-box-seam me-2"></i>Inventory</h1>
    <p class="text-muted mb-0"><?= count($items) ?> item(s)</p>
  </div>
  <?php if (canEdit()): ?>
    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#invForm">
      <i class="bi bi-plus-lg me-1"></i><?= $edit ? 'Edit item' : 'Add item' ?>
    </button>
  <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get" role="search">
  <div class="col-12 col-md-6">
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="search" class="form-control" name="q" placeholder="Search item, serial or category…"
             value="<?= e($q) ?>">
      <?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="<?= e(purl('logistics/inventory.php')) ?>"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </div>
  </div>
</form>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (canEdit()): ?>
<div class="collapse <?= ($errors || $edit) ? 'show' : '' ?>" id="invForm">
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3"><?= $edit ? 'Edit item' : 'New item' ?></h2>
      <form method="post" class="row g-3 needs-validation" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="col-12 col-md-3">
          <label class="form-label" for="i_name">Item name *</label>
          <input class="form-control" id="i_name" name="item_name" required maxlength="120"
                 value="<?= e($edit['item_name'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="i_serial">Serial no. *</label>
          <input class="form-control font-monospace" id="i_serial" name="serial_no" required maxlength="60"
                 value="<?= e($edit['serial_no'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="i_cat">Category *</label>
          <input class="form-control" id="i_cat" name="category" required maxlength="60"
                 value="<?= e($edit['category'] ?? 'General') ?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="i_cond">Condition *</label>
          <select class="form-select" id="i_cond" name="item_condition" required>
            <?php foreach ($CONDITIONS as $c): ?>
              <option value="<?= e($c) ?>" <?= ($edit['item_condition'] ?? 'Good') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label" for="i_cadet">Assigned to</label>
          <select class="form-select" id="i_cadet" name="assigned_cadet_id">
            <option value="0">— unassigned —</option>
            <?php foreach ($cadets as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($edit['assigned_cadet_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="i_issued">Date issued</label>
          <input class="form-control" type="date" id="i_issued" name="date_issued"
                 value="<?= e($edit['date_issued'] ?? '') ?>">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="i_returned">Date returned</label>
          <input class="form-control" type="date" id="i_returned" name="date_returned"
                 value="<?= e($edit['date_returned'] ?? '') ?>">
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-success" type="submit"><i class="bi bi-check-lg me-1"></i>Save</button>
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="<?= e(purl('logistics/inventory.php')) ?>">Cancel edit</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" data-empty="No inventory items tracked yet.">
      <thead class="table-dark">
        <tr>
          <th>Item</th>
          <th>Serial</th>
          <th class="d-none d-md-table-cell">Category</th>
          <th>Condition</th>
          <th>Assigned</th>
          <th class="d-none d-lg-table-cell">Issued</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i): ?>
          <tr>
            <td><strong><?= e($i['item_name']) ?></strong></td>
            <td class="font-monospace small"><?= e($i['serial_no']) ?></td>
            <td class="d-none d-md-table-cell small"><?= e($i['category']) ?></td>
            <td><?= status_badge($i['item_condition']) ?></td>
            <td class="small">
              <?php if ($i['assigned_cadet_id']): ?>
                <a href="<?= e(purl('cadets/view.php')) ?>?id=<?= (int)$i['assigned_cadet_id'] ?>" class="text-decoration-none">
                  <?= e($i['assignee']) ?>
                </a>
                <div class="text-muted font-monospace"><?= e($i['assignee_no']) ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="d-none d-lg-table-cell small"><?= format_date($i['date_issued']) ?></td>
            <td class="text-end text-nowrap">
              <div class="btn-group" role="group">
                <?php if (canEdit() && $i['assigned_cadet_id']): ?>
                  <form method="post" class="d-inline" data-confirm="Mark this item as returned?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="return">
                    <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                    <button class="btn btn-sm btn-outline-success" type="submit" title="Mark returned">
                      <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                  </form>
                <?php endif; ?>
                <?php if (canEdit()): ?>
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(purl('logistics/inventory.php')) ?>?edit=<?= (int)$i['id'] ?>" title="Edit">
                    <i class="bi bi-pencil"></i>
                  </a>
                <?php endif; ?>
                <?php if (canDelete()): ?>
                  <form method="post" class="d-inline" data-confirm="Delete <?= e($i['item_name']) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No inventory items.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
