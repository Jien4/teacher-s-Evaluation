<?php
// evaluation_period.php (FULL - updated)
// - Start session before any session usage / require_admin
// - Robust CSRF handling
// - Auto-create evaluation_periods table (idempotent)
// - Small centered Add Period modal
// - Ensure form buttons have proper types
// - JS fallback to load Bootstrap and show modal programmatically
// - CSS tweaks for stable modal layout

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'functions.php';
require_admin(); // after session_start()

// ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

/* ------------------------------------
   CREATE TABLE IF NOT EXISTS (SAFE)
------------------------------------ */
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS evaluation_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            note TEXT DEFAULT NULL,
            closed TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // swallow; we'll show error when fetching if necessary
}

/* ------------------------------------
   POST ACTIONS: ADD / CLOSE
------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted_csrf)) {
        $_SESSION['flash'] = ['message' => 'Invalid CSRF token', 'success' => false];
        header('Location: evaluation_period.php'); exit;
    }

    try {
        $act = $_POST['action'] ?? '';

        // ADD NEW PERIOD
        if ($act === 'add') {
            $start = trim((string)($_POST['start_date'] ?? ''));
            $end   = trim((string)($_POST['end_date'] ?? ''));
            $note  = trim((string)($_POST['note'] ?? ''));

            if ($start === '' || $end === '') {
                throw new Exception("Start and end date are required.");
            }

            $stmt = $conn->prepare("
                INSERT INTO evaluation_periods (start_date, end_date, note, created_at)
                VALUES (:s, :e, :n, NOW())
            ");
            $stmt->execute([':s' => $start, ':e' => $end, ':n' => $note]);

            $_SESSION['flash'] = ['message' => 'Evaluation period added', 'success' => true];
            header('Location: evaluation_period.php'); exit;
        }

        // CLOSE PERIOD
        if ($act === 'close') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("Invalid period.");

            $stmt = $conn->prepare("
                UPDATE evaluation_periods 
                SET closed = 1 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);

            $_SESSION['flash'] = ['message' => 'Period closed', 'success' => true];
            header('Location: evaluation_period.php'); exit;
        }

    } catch (Exception $e) {
        $_SESSION['flash'] = ['message' => $e->getMessage(), 'success' => false];
        header('Location: evaluation_period.php'); exit;
    }
}

/* ------------------------------------
   FETCH LAST 10 PERIODS
------------------------------------ */
$periods = [];
try {
    $periods = $conn->query("
        SELECT id, start_date, end_date, note, created_at, COALESCE(closed,0) AS closed
        FROM evaluation_periods
        ORDER BY id DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['flash'] = ['message' => 'Courses table not found or DB error: ' . $e->getMessage(), 'success' => false];
}

$page_title = "Evaluation Period";
$active = "period";

ob_start();
?>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Evaluation Periods</h5>
    <button id="addPeriodBtn" type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPeriodModal">
      <i class="bi bi-plus-lg"></i> Add Period
    </button>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
      <div class="alert <?php echo ($_SESSION['flash']['success'] ?? false) ? 'alert-success' : 'alert-danger'; ?>">
        <?php echo htmlspecialchars($_SESSION['flash']['message'] ?? ''); ?>
      </div>
  <?php unset($_SESSION['flash']); endif; ?>

  <div class="table-responsive">
  <table class="table table-hover">
    <thead>
      <tr>
        <th>Start</th>
        <th>End</th>
        <th>Note</th>
        <th>Status</th>
        <th class="text-end"></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($periods)): ?>
        <tr><td colspan="5" class="text-center py-3">No evaluation periods found.</td></tr>
      <?php else: foreach($periods as $p): ?>
        <tr>
          <td><?php echo htmlspecialchars($p['start_date']); ?></td>
          <td><?php echo htmlspecialchars($p['end_date']); ?></td>
          <td><?php echo nl2br(htmlspecialchars($p['note'])); ?></td>
          <td><?php echo $p['closed'] ? 'Closed' : 'Active'; ?></td>

          <td class="text-end">
            <?php if (!$p['closed']): ?>
              <form method="post" action="evaluation_period.php" style="display:inline;" onsubmit="return confirm('Close this period?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Close</button>
              </form>
            <?php else: ?>
              <span class="text-muted small">Closed</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Add Period Modal (small, centered) -->
<div class="modal fade" id="addPeriodModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px;">
    <form method="post" action="evaluation_period.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Evaluation Period</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">

        <div class="mb-3">
          <label for="start-date" class="form-label">Start Date</label>
          <input id="start-date" type="date" name="start_date" class="form-control" required>
        </div>

        <div class="mb-3">
          <label for="end-date" class="form-label">End Date</label>
          <input id="end-date" type="date" name="end_date" class="form-control" required>
        </div>

        <div class="mb-3">
          <label for="note" class="form-label">Note</label>
          <textarea id="note" name="note" class="form-control" rows="3"></textarea>
        </div>
      </div>

      <div class="modal-footer justify-content-end">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>

<!-- Inline CSS tweaks for stable modal layout -->
<style>
.modal .form-label { display:block; margin-bottom:.35rem; }
.modal .form-control { width:100% !important; box-sizing:border-box; }
.modal .modal-footer { gap:.5rem; }
.modal .modal-footer .btn { min-width:80px; }
.modal .modal-content * { position: relative; }
.modal .modal-body { padding-top:.75rem; padding-bottom:.75rem; }
</style>

<!-- JS: ensure bootstrap loaded and modal fallback (safe) -->
<script>
(function(){
  function ensureBootstrapLoaded(cb) {
    if (typeof window.bootstrap !== 'undefined') return cb();
    if (document.getElementById('bootstrap-bundle-cdn')) {
      var check = setInterval(function(){
        if (typeof window.bootstrap !== 'undefined') { clearInterval(check); cb(); }
      }, 50);
      return;
    }
    var s = document.createElement('script');
    s.id = 'bootstrap-bundle-cdn';
    s.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js';
    s.crossOrigin = 'anonymous';
    s.onload = function(){ cb(); };
    s.onerror = function(){ cb(); };
    document.body.appendChild(s);
  }

  // Add button fallback to open Add Period modal programmatically if needed
  function initAddButton() {
    var addBtn = document.getElementById('addPeriodBtn');
    if (!addBtn) return;
    addBtn.addEventListener('click', function(){
      try {
        if (typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Modal) {
          var modalEl = document.getElementById('addPeriodModal');
          var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
          inst.show();
        }
      } catch (e) {
        if (console && console.warn) console.warn('Add period fallback failed', e);
      }
    });
  }

  ensureBootstrapLoaded(function(){
    initAddButton();
  });
})();
</script>
