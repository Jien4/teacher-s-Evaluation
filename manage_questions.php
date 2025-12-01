<?php
// manage_questions.php (FULL - updated)
// - Start session before any session usage / require_admin
// - Robust CSRF handling
// - Create evaluation_questions table if missing (idempotent)
// - Small centered Add Question modal
// - Single reusable Edit Question modal populated via JS (behaves like Add)
// - CSS tweaks to keep modal layout stable
// - JS fallback to load Bootstrap and open modals programmatically
// - All forms include CSRF and proper button types

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'functions.php';
require_admin(); // run after session_start()

// ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// ensure table exists
try {
    $conn->exec("
      CREATE TABLE IF NOT EXISTS evaluation_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_title VARCHAR(150) DEFAULT 'General',
        question_text TEXT NOT NULL,
        ordering INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // ignore; errors will surface when querying
}

// handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted_csrf)) {
        $_SESSION['flash'] = ['message' => 'Invalid CSRF token', 'success' => false];
        header('Location: manage_questions.php'); exit;
    }

    try {
        $act = $_POST['action'] ?? '';

        if ($act === 'add') {
            $group = trim((string)($_POST['group_title'] ?? '')) ?: 'General';
            $text  = trim((string)($_POST['question_text'] ?? ''));
            $ordering = (int)($_POST['ordering'] ?? 0);
            if ($text === '') throw new Exception('Question text required.');

            $stmt = $conn->prepare('INSERT INTO evaluation_questions (group_title, question_text, ordering, created_at) VALUES (:g, :t, :o, NOW())');
            $stmt->execute([':g'=>$group, ':t'=>$text, ':o'=>$ordering]);

            $_SESSION['flash'] = ['message' => 'Question added', 'success' => true];
            header('Location: manage_questions.php'); exit;
        }

        if ($act === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $group = trim((string)($_POST['group_title'] ?? '')) ?: 'General';
            $text  = trim((string)($_POST['question_text'] ?? ''));
            $ordering = (int)($_POST['ordering'] ?? 0);
            if ($id <= 0 || $text === '') throw new Exception('Invalid input.');

            $stmt = $conn->prepare('UPDATE evaluation_questions SET group_title = :g, question_text = :t, ordering = :o WHERE id = :id');
            $stmt->execute([':g'=>$group, ':t'=>$text, ':o'=>$ordering, ':id'=>$id]);

            $_SESSION['flash'] = ['message' => 'Question updated', 'success' => true];
            header('Location: manage_questions.php'); exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid ID.');

            $stmt = $conn->prepare('DELETE FROM evaluation_questions WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $_SESSION['flash'] = ['message' => 'Question deleted', 'success' => true];
            header('Location: manage_questions.php'); exit;
        }

    } catch (Exception $e) {
        $_SESSION['flash'] = ['message' => $e->getMessage(), 'success' => false];
        header('Location: manage_questions.php'); exit;
    }
}

// fetch questions
$questions = [];
try {
    $questions = $conn->query('SELECT id, group_title, question_text, ordering, created_at FROM evaluation_questions ORDER BY ordering, id')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['flash'] = ['message' => 'DB error: ' . $e->getMessage(), 'success' => false];
}

$page_title = "Manage Questions";
$active = "questions";
ob_start();
?>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Manage Questions</h5>
    <button id="addQuestionBtn" type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
      <i class="bi bi-plus-lg"></i> Add Question
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
        <th style="width:160px">Group</th>
        <th>Question</th>
        <th style="width:160px">Added</th>
        <th style="width:130px" class="text-end">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($questions)): ?>
        <tr><td colspan="4" class="text-center py-3">No questions found.</td></tr>
      <?php else: foreach ($questions as $q): ?>
        <tr>
          <td><?php echo htmlspecialchars($q['group_title']); ?></td>
          <td><?php echo htmlspecialchars($q['question_text']); ?></td>
          <td><?php echo htmlspecialchars($q['created_at']); ?></td>
          <td class="text-end">
            <button
              class="btn btn-sm btn-outline-secondary editQuestionBtn"
              type="button"
              data-id="<?php echo (int)$q['id']; ?>"
              data-group="<?php echo htmlspecialchars($q['group_title'], ENT_QUOTES); ?>"
              data-text="<?php echo htmlspecialchars($q['question_text'], ENT_QUOTES); ?>"
              data-order="<?php echo (int)$q['ordering']; ?>">
              Edit
            </button>

            <form method="post" action="manage_questions.php" style="display:inline" onsubmit="return confirm('Delete question?');">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?php echo (int)$q['id']; ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Add Question Modal (small, centered) -->
<div class="modal fade" id="addQuestionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <form method="post" action="manage_questions.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Question</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">

        <div class="mb-3">
          <label for="add-group" class="form-label">Group Title</label>
          <input id="add-group" name="group_title" class="form-control" placeholder="E.g. Criteria 101">
        </div>

        <div class="mb-3">
          <label for="add-text" class="form-label">Question</label>
          <textarea id="add-text" name="question_text" class="form-control" required rows="3"></textarea>
        </div>

        <div class="mb-3">
          <label for="add-order" class="form-label">Ordering (optional)</label>
          <input id="add-order" name="ordering" type="number" class="form-control" value="0">
        </div>
      </div>

      <div class="modal-footer justify-content-end">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>
  </div>
</div>

<!-- Reusable Edit Question Modal (populated by JS) -->
<div class="modal fade" id="editQuestionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <form id="editQuestionForm" method="post" action="manage_questions.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Question</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="edit">
        <input id="edit-id" type="hidden" name="id" value="">

        <div class="mb-3">
          <label for="edit-group" class="form-label">Group Title</label>
          <input id="edit-group" name="group_title" class="form-control">
        </div>

        <div class="mb-3">
          <label for="edit-text" class="form-label">Question</label>
          <textarea id="edit-text" name="question_text" class="form-control" required rows="3"></textarea>
        </div>

        <div class="mb-3">
          <label for="edit-order" class="form-label">Ordering</label>
          <input id="edit-order" name="ordering" type="number" class="form-control" value="0">
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

<!-- Inline CSS tweaks -->
<style>
.modal .form-label { display:block; margin-bottom:.35rem; }
.modal .form-control { width:100% !important; box-sizing:border-box; }
.modal .modal-footer { gap:.5rem; }
.modal .modal-footer .btn { min-width:80px; }
.modal .modal-content * { position: relative; }
.modal .modal-body { padding-top:.75rem; padding-bottom:.75rem; }
</style>

<!-- JS: populate edit modal, ensure bootstrap present -->
<script>
(function(){
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

  function initEditButtons() {
    var buttons = document.querySelectorAll('.editQuestionBtn');
    if (!buttons) return;
    buttons.forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-id');
        var group = btn.getAttribute('data-group') || '';
        var text  = btn.getAttribute('data-text') || '';
        var order = btn.getAttribute('data-order') || '0';

        document.getElementById('edit-id').value = id;
        document.getElementById('edit-group').value = group;
        document.getElementById('edit-text').value = text;
        document.getElementById('edit-order').value = order;

        // show modal programmatically (fallback if data-bs-toggle fails)
        try {
          if (typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Modal) {
            var modalEl = document.getElementById('editQuestionModal');
            var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            inst.show();
          }
        } catch (e) {
          console && console.warn && console.warn('Edit modal show failed', e);
        }
      });
    });
  }

  // init after bootstrap is available (or immediately if it is)
  ensureBootstrapLoaded(function(){
    initEditButtons();

    // also attach Add button fallback to ensure modal opens programmatically if needed
    var addBtn = document.getElementById('addQuestionBtn');
    if (addBtn) {
      addBtn.addEventListener('click', function(){
        try {
          if (typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Modal) {
            var modalEl = document.getElementById('addQuestionModal');
            var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            inst.show();
          }
        } catch (e) { console && console.warn && console.warn('Add modal show failed', e); }
      });
    }
  });
})();
</script>
