<?php
// manage_subjects.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'functions.php';
require_admin();

// CSRF
if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    catch (Exception $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}

// ensure subjects table exists
try {
    $conn->exec("
      CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject_code VARCHAR(100) NOT NULL,
        subject_title VARCHAR(255) NOT NULL,
        course VARCHAR(100) NOT NULL,
        year VARCHAR(16) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_subject_code_course_year (subject_code, course, year)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // ignore
}

// also ensure teacher_subjects table exists (safe)
try {
    $conn->exec("
      CREATE TABLE IF NOT EXISTS teacher_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        subject_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_teacher_subject (teacher_id, subject_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted)) {
        $_SESSION['flash'] = ['message'=>'Invalid CSRF','success'=>false];
        header('Location: manage_subjects.php'); exit;
    }

    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'add') {
            $code = trim((string)($_POST['subject_code'] ?? ''));
            $title = trim((string)($_POST['subject_title'] ?? ''));
            $course = strtoupper(trim((string)($_POST['course'] ?? '')));
            $year = trim((string)($_POST['year'] ?? ''));
            if ($code === '' || $title === '' || $course === '' || $year === '') throw new Exception('All fields required.');

            $stmt = $conn->prepare('INSERT INTO subjects (subject_code, subject_title, course, year, created_at) VALUES (:code,:title,:course,:year,NOW())');
            $stmt->execute([':code'=>$code,':title'=>$title,':course'=>$course,':year'=>$year]);

            $_SESSION['flash'] = ['message'=>'Subject added','success'=>true];
            header('Location: manage_subjects.php'); exit;
        }

        if ($act === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim((string)($_POST['subject_code'] ?? ''));
            $title = trim((string)($_POST['subject_title'] ?? ''));
            $course = strtoupper(trim((string)($_POST['course'] ?? '')));
            $year = trim((string)($_POST['year'] ?? ''));
            if ($id <= 0 || $code === '' || $title === '' || $course === '' || $year === '') throw new Exception('Invalid input.');

            $stmt = $conn->prepare('UPDATE subjects SET subject_code=:code, subject_title=:title, course=:course, year=:year WHERE id=:id');
            $stmt->execute([':code'=>$code,':title'=>$title,':course'=>$course,':year'=>$year,':id'=>$id]);

            $_SESSION['flash'] = ['message'=>'Subject updated','success'=>true];
            header('Location: manage_subjects.php'); exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid ID.');
            // deletion cascades in mapping table due to FK if present, otherwise remove mapping first
            try { $conn->prepare('DELETE FROM teacher_subjects WHERE subject_id = :id')->execute([':id'=>$id]); } catch (Exception $e) {}
            $stmt = $conn->prepare('DELETE FROM subjects WHERE id=:id');
            $stmt->execute([':id'=>$id]);

            $_SESSION['flash'] = ['message'=>'Subject deleted','success'=>true];
            header('Location: manage_subjects.php'); exit;
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['message'=>$e->getMessage(),'success'=>false];
        header('Location: manage_subjects.php'); exit;
    }
}

// fetch subjects
try {
    $subjects = $conn->query('SELECT id, subject_code, subject_title, course, year, created_at FROM subjects ORDER BY course, year, subject_code')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['flash'] = ['message'=>'DB error: '.$e->getMessage(),'success'=>false];
    $subjects = [];
}

$page_title = "Manage Subjects";
$active = "subjects";
ob_start();
?>
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Manage Subjects</h5>
    <!-- Add button: ensure type="button" so it doesn't submit any forms and has an ID we can target -->
    <button id="openAddSubjectBtn" type="button" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg"></i> Add Subject
    </button>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert <?php echo ($_SESSION['flash']['success'] ?? false) ? 'alert-success' : 'alert-danger'; ?>">
      <?php echo htmlspecialchars($_SESSION['flash']['message'] ?? ''); ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>

  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Code</th><th>Title</th><th>Course</th><th>Year</th><th>Added</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($subjects)): ?>
          <tr><td colspan="6" class="text-center py-3">No subjects found.</td></tr>
        <?php else: foreach($subjects as $s): ?>
          <tr>
            <td><?php echo htmlspecialchars($s['subject_code']); ?></td>
            <td><?php echo htmlspecialchars($s['subject_title']); ?></td>
            <td><?php echo htmlspecialchars($s['course']); ?></td>
            <td><?php echo htmlspecialchars($s['year']); ?></td>
            <td><?php echo htmlspecialchars($s['created_at']); ?></td>
            <td class="text-end">
              <!-- Edit button: ensure type="button" -->
              <button type="button" class="btn btn-sm btn-outline-secondary editSubjectBtn"
                      data-id="<?php echo (int)$s['id']; ?>"
                      data-code="<?php echo htmlspecialchars($s['subject_code'], ENT_QUOTES); ?>"
                      data-title="<?php echo htmlspecialchars($s['subject_title'], ENT_QUOTES); ?>"
                      data-course="<?php echo htmlspecialchars($s['course'], ENT_QUOTES); ?>"
                      data-year="<?php echo htmlspecialchars($s['year'], ENT_QUOTES); ?>">Edit</button>

              <form method="post" action="manage_subjects.php" style="display:inline" onsubmit="return confirm('Delete subject?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Subject Modal -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:640px;">
    <form method="post" action="manage_subjects.php" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Subject</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">
        <div class="mb-3"><label class="form-label">Subject Code</label><input name="subject_code" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Subject Title</label><input name="subject_title" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Course</label><input name="course" class="form-control" placeholder="e.g. BSIT" required></div>
        <div class="mb-3"><label class="form-label">Year</label>
          <select name="year" class="form-select" required>
            <option value="">Select year</option>
            <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Add</button></div>
    </form>
  </div>
</div>

<!-- Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:640px;">
    <form id="editSubjectForm" method="post" action="manage_subjects.php" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Subject</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="edit">
        <input id="edit-id" type="hidden" name="id" value="">
        <div class="mb-3"><label class="form-label">Subject Code</label><input id="edit-code" name="subject_code" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Subject Title</label><input id="edit-title" name="subject_title" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Course</label><input id="edit-course" name="course" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Year</label>
          <select id="edit-year" name="year" class="form-select" required>
            <option value="">Select year</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
</div>

<!-- Inline JS: placed inside output buffer so it's included before admin_layout's closing body -->
<script>
(function(){
  // Helper to show a modal element. Uses Bootstrap API if available, otherwise falls back to simple show.
  function showModalById(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (window.bootstrap && bootstrap.Modal) {
      try {
        var instance = bootstrap.Modal.getInstance(el);
        if (!instance) instance = new bootstrap.Modal(el);
        instance.show();
        return;
      } catch (e) {
        // fallthrough to fallback
      }
    }
    // fallback (non-animated)
    el.classList.add('show');
    el.style.display = 'block';
    el.removeAttribute('aria-hidden');
    el.setAttribute('aria-modal','true');
  }

  function hideModalById(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (window.bootstrap && bootstrap.Modal) {
      try {
        var instance = bootstrap.Modal.getInstance(el);
        if (instance) instance.hide();
        return;
      } catch (e) {}
    }
    el.classList.remove('show');
    el.style.display = 'none';
    el.setAttribute('aria-hidden','true');
    el.removeAttribute('aria-modal');
  }

  document.addEventListener('DOMContentLoaded', function() {
    // Open Add modal when Add button clicked
    var addBtn = document.getElementById('openAddSubjectBtn');
    if (addBtn) {
      addBtn.addEventListener('click', function (e) {
        e.preventDefault();
        // clear fields in add modal (nice to have)
        var addModal = document.getElementById('addSubjectModal');
        if (addModal) {
          var inputs = addModal.querySelectorAll('input, select, textarea');
          inputs.forEach(function(inp){
            if (inp.type === 'hidden' && inp.name === 'csrf_token') return;
            if (inp.tagName.toLowerCase() === 'select') inp.value = '';
            else if (inp.type !== 'submit' && inp.type !== 'button') inp.value = '';
          });
        }
        showModalById('addSubjectModal');
      }, false);
    }

    // Event delegation for edit buttons
    document.addEventListener('click', function (evt) {
      var el = evt.target;
      while (el && el !== document) {
        if (el.matches && el.matches('.editSubjectBtn')) break;
        el = el.parentNode;
      }
      if (!el || el === document) return;

      var id = el.getAttribute('data-id') || '';
      var code = el.getAttribute('data-code') || '';
      var title = el.getAttribute('data-title') || '';
      var course = el.getAttribute('data-course') || '';
      var year = el.getAttribute('data-year') || '';

      var idInput = document.getElementById('edit-id');
      var codeInput = document.getElementById('edit-code');
      var titleInput = document.getElementById('edit-title');
      var courseInput = document.getElementById('edit-course');
      var yearSelect = document.getElementById('edit-year');

      if (idInput) idInput.value = id;
      if (codeInput) codeInput.value = code;
      if (titleInput) titleInput.value = title;
      if (courseInput) courseInput.value = course;
      if (yearSelect) yearSelect.value = year;

      showModalById('editSubjectModal');
    }, false);

    // Close fallback display if user clicks on data-bs-dismiss elements (so fallback respects dismiss)
    document.addEventListener('click', function(e){
      var target = e.target;
      if (!target) return;
      if (target.getAttribute && target.getAttribute('data-bs-dismiss') === 'modal') {
        // find parent modal
        var parent = target.closest('.modal');
        if (parent && parent.id) hideModalById(parent.id);
      }
    });

    // Optional: prevent accidental Enter submits inside modal (so user can type and click Add)
    var modalForms = document.querySelectorAll('#addSubjectModal form, #editSubjectModal form');
    modalForms.forEach(function(f) {
      f.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT')) {
          // allow Enter on textarea or if focused on submit; otherwise prevent to avoid accidental submit
          var tg = e.target;
          if (tg.tagName === 'INPUT' && tg.type !== 'submit') {
            e.preventDefault();
          }
        }
      });
    });
  });
})();
</script>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>
