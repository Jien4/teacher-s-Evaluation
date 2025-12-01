<?php
// manage_courses.php (FINAL - edit modal reused + stable behavior)
// - Single reusable Edit modal (populated via JS) so Edit behaves exactly like Add
// - Session started at top
// - Robust CSRF handling
// - Create courses table if missing (idempotent)
// - Small centered Add Course modal
// - JS loads Bootstrap bundle if missing
// - Edit/Delete use POST + CSRF
// - CSS tweaks for stable layout

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'functions.php';
require_admin(); // called after session_start()

// ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// Create courses table if missing (idempotent)
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS courses (
          id INT AUTO_INCREMENT PRIMARY KEY,
          code VARCHAR(50) NOT NULL UNIQUE,
          title VARCHAR(255) NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // ignore here; show flash on fetch if necessary
}

// POST: add / edit / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted_csrf)) {
        $_SESSION['flash'] = ['message' => 'Invalid CSRF', 'success' => false];
        header('Location: manage_courses.php'); exit;
    }

    try {
        $act = $_POST['action'] ?? '';

        if ($act === 'add') {
            $code = trim((string)($_POST['code'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            if ($code === '' || $title === '') throw new Exception('All fields required.');

            $stmt = $conn->prepare('INSERT INTO courses (code, title, created_at) VALUES (:c, :t, NOW())');
            $stmt->execute([':c' => $code, ':t' => $title]);

            $log = $conn->prepare('INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES (\'admin\',:uid,\'add_course\',:det,:ip,NOW())');
            $log->execute([':uid' => $_SESSION['user_id'] ?? null, ':det' => "Added course {$code} - {$title}", ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message' => 'Course added', 'success' => true];
            header('Location: manage_courses.php'); exit;
        }

        if ($act === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)($_POST['code'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            if ($id <= 0 || $code === '' || $title === '') throw new Exception('Invalid input.');

            $stmt = $conn->prepare('UPDATE courses SET code = :c, title = :t WHERE id = :id');
            $stmt->execute([':c' => $code, ':t' => $title, ':id' => $id]);

            $log = $conn->prepare('INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES (\'admin\',:uid,\'edit_course\',:det,:ip,NOW())');
            $log->execute([':uid' => $_SESSION['user_id'] ?? null, ':det' => "Edited course #{$id}", ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message' => 'Course updated', 'success' => true];
            header('Location: manage_courses.php'); exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid ID.');

            // Prevent deleting if assigned (best-effort)
            try {
                $chk = $conn->prepare('SELECT code FROM courses WHERE id = :id LIMIT 1');
                $chk->execute([':id' => $id]);
                $row = $chk->fetch(PDO::FETCH_ASSOC);
                $codeToCheck = $row['code'] ?? null;
                if ($codeToCheck) {
                    $assigned = $conn->prepare('SELECT COUNT(*) FROM teachers WHERE course = :code');
                    $assigned->execute([':code' => $codeToCheck]);
                    if ($assigned->fetchColumn() > 0) {
                        $_SESSION['flash'] = ['message' => 'Cannot delete course with assigned teachers', 'success' => false];
                        header('Location: manage_courses.php'); exit;
                    }
                }
            } catch (Exception $e) {
                // ignore
            }

            $stmt = $conn->prepare('DELETE FROM courses WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $log = $conn->prepare('INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES (\'admin\',:uid,\'delete_course\',:det,:ip,NOW())');
            $log->execute([':uid' => $_SESSION['user_id'] ?? null, ':det' => "Deleted course #{$id}", ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message' => 'Course deleted', 'success' => true];
            header('Location: manage_courses.php'); exit;
        }

    } catch (Exception $e) {
        $_SESSION['flash'] = ['message' => $e->getMessage(), 'success' => false];
        header('Location: manage_courses.php'); exit;
    }
}

// fetch courses
$courses = [];
try {
    $courses = $conn->query('SELECT id, code, title, created_at FROM courses ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['flash'] = ['message' => 'Courses table not found or DB error: ' . $e->getMessage(), 'success' => false];
}

$page_title = "Manage Courses";
$active = "courses";
ob_start();
?>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Manage Courses</h5>
    <button id="addCourseBtn" type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCourseModal">
      <i class="bi bi-plus-lg"></i> Add Course
    </button>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert <?php echo ($_SESSION['flash']['success'] ?? false) ? 'alert-success' : 'alert-danger'; ?>">
      <?php echo htmlspecialchars($_SESSION['flash']['message'] ?? ''); ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>

  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Code</th><th>Title</th><th>Added</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($courses)): ?>
          <tr><td colspan="4" class="text-center py-3">No courses found.</td></tr>
        <?php else: foreach($courses as $c): ?>
          <tr>
            <td><?php echo htmlspecialchars($c['code']); ?></td>
            <td><?php echo htmlspecialchars($c['title']); ?></td>
            <td><?php echo htmlspecialchars($c['created_at']); ?></td>
            <td class="text-end">
              <!-- Edit button: will populate & open single edit modal -->
              <button class="btn btn-sm btn-outline-secondary editCourseBtn"
                      type="button"
                      data-id="<?php echo (int)$c['id']; ?>"
                      data-code="<?php echo htmlspecialchars($c['code'], ENT_QUOTES); ?>"
                      data-title="<?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?>">
                Edit
              </button>

              <form method="post" action="manage_courses.php" style="display:inline" onsubmit="return confirm('Delete course?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Course Modal (small, centered) -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
    <form method="post" action="manage_courses.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">

        <div class="mb-3">
          <label for="add-code" class="form-label">Code</label>
          <input id="add-code" name="code" class="form-control" required autocomplete="off">
        </div>

        <div class="mb-3">
          <label for="add-title" class="form-label">Title</label>
          <input id="add-title" name="title" class="form-control" required autocomplete="off">
        </div>
      </div>

      <div class="modal-footer justify-content-end">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>
  </div>
</div>

<!-- Single Reusable Edit Modal (populated by JS when Edit clicked) -->
<div class="modal fade" id="editCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <form id="editCourseForm" method="post" action="manage_courses.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="edit">
        <input id="edit-id" type="hidden" name="id" value="">

        <div class="mb-3">
          <label for="edit-code" class="form-label">Code</label>
          <input id="edit-code" name="code" class="form-control" required autocomplete="off">
        </div>

        <div class="mb-3">
          <label for="edit-title" class="form-label">Title</label>
          <input id="edit-title" name="title" class="form-control" required autocomplete="off">
        </div>
      </div>

      <div class="modal-footer justify-content-end">
        <button id="edit-cancel" type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>

<!-- Inline CSS tweaks to ensure modal inputs and buttons layout correctly -->
<style>
/* Stack labels and inputs and force full width in modals */
.modal .form-label { display:block; margin-bottom:.35rem; }
.modal .form-control { width:100% !important; box-sizing:border-box; }
/* Footer spacing and button min width */
.modal .modal-footer { gap:.5rem; }
.modal .modal-footer .btn { min-width:80px; }
/* Prevent odd absolute positioning from other global CSS */
.modal .modal-content * { position: relative; }
.modal .modal-body { padding-top: .75rem; padding-bottom: .75rem; }
</style>

<!-- JS: populate edit modal, fallback loader for bootstrap -->
<script>
(function(){
  // Load bootstrap if missing (so data-bs-dismiss and modal behavior works)
  function ensureBootstrapLoaded(cb) {
    if (typeof window.bootstrap !== 'undefined') return cb();
    if (document.getElementById('bootstrap-bundle-cdn')) {
      var check = setInterval(function(){ if (typeof window.bootstrap !== 'undefined') { clearInterval(check); cb(); } }, 50);
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

  // Prepare reusable edit modal behavior
  function initEditButtons() {
    var editButtons = document.querySelectorAll('.editCourseBtn');
    if (!editButtons) return;

    editButtons.forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-id');
        var code = btn.getAttribute('data-code') || '';
        var title = btn.getAttribute('data-title') || '';

        // populate edit form fields
        document.getElementById('edit-id').value = id;
        document.getElementById('edit-code').value = code;
        document.getElementById('edit-title').value = title;

        // show modal programmatically in case data-bs-toggle didn't trigger
        try {
          if (typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Modal) {
            var modalEl = document.getElementById('editCourseModal');
            var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            inst.show();
          } else {
            // fallback: attempt to click the element's data-bs-target if present (some browsers)
            var target = btn.getAttribute('data-bs-target');
            if (target) {
              var el = document.querySelector(target);
              if (el && el.style) el.style.display = 'block';
            }
          }
        } catch (e) {
          console && console.warn && console.warn('Edit modal show failed', e);
        }
      });
    });
  }

  // Run once bootstrap loaded (or immediately if present)
  ensureBootstrapLoaded(function(){
    // init edit buttons right away
    initEditButtons();
  });

  // If new rows are dynamically added later, you can call initEditButtons() again.

})();
</script>
