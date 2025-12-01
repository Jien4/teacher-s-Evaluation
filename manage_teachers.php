<?php
// manage_teachers.php (UPDATED with subjects)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'functions.php';
require_admin();

// CSRF
if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    catch (Exception $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}

// ensure subjects table exists (safe)
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
} catch (Exception $e) { /* ignore */ }

// ensure teacher_subjects and teacher table columns exist or table exists
try {
    $conn->exec("
      CREATE TABLE IF NOT EXISTS teacher_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        subject_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_teacher_subject (teacher_id, subject_id),
        CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
        CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) { /* ignore */ }

// handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted)) {
        $_SESSION['flash'] = ['message'=>'Invalid CSRF','success'=>false];
        header('Location: manage_teachers.php'); exit;
    }

    try {
        $act = $_POST['action'] ?? '';
        if ($act === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $course = strtoupper(trim((string)($_POST['course'] ?? '')));
            $year = trim((string)($_POST['year'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $subject_ids = $_POST['subjects'] ?? []; // array of subject ids

            if ($name === '') throw new Exception('Name required.');

            $stmt = $conn->prepare('INSERT INTO teachers (name, course, year, description, email, created_at) VALUES (:n,:c,:y,:d,:e,NOW())');
            $stmt->execute([':n'=>$name,':c'=>$course?:null,':y'=>$year?:null,':d'=>$desc?:null,':e'=>$email?:null]);
            $tid = (int)$conn->lastInsertId();

            // map subjects
            if (!empty($subject_ids) && is_array($subject_ids)) {
                $ins = $conn->prepare('INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (:tid, :sid)');
                foreach ($subject_ids as $sid) {
                    $ins->execute([':tid'=>$tid,':sid'=> (int)$sid ]);
                }
            }

            // audit
            $log = $conn->prepare("INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES ('admin',:uid,'add_teacher',:det,:ip,NOW())");
            $log->execute([':uid'=>$_SESSION['user_id'] ?? null, ':det'=>"Added teacher {$name}", ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message'=>'Teacher added','success'=>true];
            header('Location: manage_teachers.php'); exit;
        }

        if ($act === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $course = strtoupper(trim((string)($_POST['course'] ?? '')));
            $year = trim((string)($_POST['year'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $subject_ids = $_POST['subjects'] ?? [];

            if ($id <= 0 || $name === '') throw new Exception('Invalid input.');

            $stmt = $conn->prepare('UPDATE teachers SET name=:n, course=:c, year=:y, description=:d, email=:e WHERE id=:id');
            $stmt->execute([':n'=>$name,':c'=>$course?:null,':y'=>$year?:null,':d'=>$desc?:null,':e'=>$email?:null,':id'=>$id]);

            // sync subjects: remove existing mappings not in list, add new ones
            $conn->beginTransaction();
            $conn->prepare('DELETE FROM teacher_subjects WHERE teacher_id = :tid')->execute([':tid'=>$id]);
            if (!empty($subject_ids) && is_array($subject_ids)) {
                $ins = $conn->prepare('INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (:tid, :sid)');
                foreach ($subject_ids as $sid) {
                    $ins->execute([':tid'=>$id, ':sid'=>(int)$sid]);
                }
            }
            $conn->commit();

            $log = $conn->prepare("INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES ('admin',:uid,'edit_teacher',:det,:ip,NOW())");
            $log->execute([':uid'=>$_SESSION['user_id'] ?? null, ':det'=>"Edited teacher #{$id}", ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message'=>'Teacher updated','success'=>true];
            header('Location: manage_teachers.php'); exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid ID.');

            // prevent if evaluations exist
            try {
                $chk = $conn->prepare('SELECT COUNT(*) FROM evaluations WHERE teacher_id = :id');
                $chk->execute([':id'=>$id]);
                if ($chk->fetchColumn() > 0) {
                    $_SESSION['flash'] = ['message'=>'Cannot delete teacher with existing evaluations','success'=>false];
                    header('Location: manage_teachers.php'); exit;
                }
            } catch (Exception $e) {}

            $conn->prepare('DELETE FROM teacher_subjects WHERE teacher_id = :id')->execute([':id'=>$id]);
            $conn->prepare('DELETE FROM teachers WHERE id = :id')->execute([':id'=>$id]);

            $log = $conn->prepare("INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES ('admin',:uid,'delete_teacher',:det,:ip,NOW())");
            $log->execute([':uid'=>$_SESSION['user_id'] ?? null, ':det'=>"Deleted teacher #{$id}", ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message'=>'Teacher deleted','success'=>true];
            header('Location: manage_teachers.php'); exit;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['flash'] = ['message'=>$e->getMessage(),'success'=>false];
        header('Location: manage_teachers.php'); exit;
    }
}

// fetch teachers + assigned subjects (grouped)
$teachers = $conn->query('SELECT id, name, course, year, email, description, created_at FROM teachers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$subjects = $conn->query('SELECT id, subject_code, subject_title, course, year FROM subjects ORDER BY course, year, subject_code')->fetchAll(PDO::FETCH_ASSOC);

// load mapping for quick lookup
$maps = [];
$rows = $conn->query('SELECT ts.teacher_id, s.subject_code, s.subject_title, s.id AS subject_id, s.course, s.year FROM teacher_subjects ts JOIN subjects s ON s.id = ts.subject_id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $maps[$r['teacher_id']][] = $r;
}

$page_title = "Manage Teachers";
$active = "teachers";
ob_start();
?>
<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Manage Teachers</h5>
    <div>
      <a class="btn btn-outline-secondary btn-sm me-2" href="manage_subjects.php"><i class="bi bi-list-ul"></i> Manage Subjects</a>
      <button id="addTeacherBtn" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTeacherModal"><i class="bi bi-plus-lg"></i> Add Teacher</button>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert <?php echo ($_SESSION['flash']['success'] ?? false) ? 'alert-success' : 'alert-danger'; ?>">
      <?php echo htmlspecialchars($_SESSION['flash']['message'] ?? ''); ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>

  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Name</th><th>Course</th><th>Year</th><th>Subjects</th><th>Email</th><th>Added</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($teachers)): ?>
          <tr><td colspan="7" class="text-center py-3">No teachers found.</td></tr>
        <?php else: foreach($teachers as $t): ?>
          <tr>
            <td><?php echo htmlspecialchars($t['name']); ?></td>
            <td><?php echo htmlspecialchars($t['course']); ?></td>
            <td><?php echo htmlspecialchars($t['year']); ?></td>
            <td>
              <?php
                $list = $maps[$t['id']] ?? [];
                $out = array_map(function($it){ return htmlspecialchars($it['subject_code'] . ' — ' . $it['subject_title']); }, $list);
                echo implode('<br>', $out);
              ?>
            </td>
            <td><?php echo htmlspecialchars($t['email']); ?></td>
            <td><?php echo htmlspecialchars($t['created_at']); ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary editTeacherBtn"
                      type="button"
                      data-id="<?php echo (int)$t['id']; ?>"
                      data-name="<?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>"
                      data-course="<?php echo htmlspecialchars($t['course'], ENT_QUOTES); ?>"
                      data-year="<?php echo htmlspecialchars($t['year'], ENT_QUOTES); ?>"
                      data-email="<?php echo htmlspecialchars($t['email'], ENT_QUOTES); ?>"
                      data-desc="<?php echo htmlspecialchars($t['description'], ENT_QUOTES); ?>"
                      data-subjects="<?php echo htmlspecialchars(json_encode(array_map(function($it){ return (int)$it['subject_id']; }, $list)), ENT_QUOTES); ?>">
                Edit
              </button>

              <form method="post" action="manage_teachers.php" style="display:inline" onsubmit="return confirm('Delete teacher? This action cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Teacher Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:720px;">
    <form method="post" action="manage_teachers.php" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Teacher</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">

        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Course</label>
            <input name="course" class="form-control" placeholder="e.g. BSIT" required>
          </div>
          <div class="col-md-3"><label class="form-label">Year</label>
            <select name="year" class="form-select" required>
              <option value="">Select</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
            </select>
          </div>

          <div class="col-12"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
          <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>

          <div class="col-12">
            <label class="form-label">Assign Subjects (<?php echo htmlspecialchars('Code — Title'); ?>)</label>
            <div style="max-height:220px; overflow:auto; border:1px solid #e9ecef; padding:8px; border-radius:6px;">
              <?php foreach($subjects as $s): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="subjects[]" value="<?php echo (int)$s['id']; ?>" id="s_add_<?php echo (int)$s['id']; ?>">
                  <label class="form-check-label" for="s_add_<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_title'] . ' (' . $s['course'] . ' Y' . $s['year'] . ')'); ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Add</button></div>
    </form>
  </div>
</div>

<!-- Edit Teacher Modal -->
<div class="modal fade" id="editTeacherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:720px;">
    <form id="editTeacherForm" method="post" action="manage_teachers.php" class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit Teacher</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="edit">
        <input id="edit-id" type="hidden" name="id" value="">

        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Name</label><input id="edit-name" name="name" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Course</label><input id="edit-course" name="course" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Year</label>
            <select id="edit-year" name="year" class="form-select" required>
              <option value="">Select</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option>
            </select>
          </div>

          <div class="col-12"><label class="form-label">Email</label><input id="edit-email" name="email" type="email" class="form-control"></div>
          <div class="col-12"><label class="form-label">Description</label><textarea id="edit-desc" name="description" class="form-control" rows="2"></textarea></div>

          <div class="col-12">
            <label class="form-label">Assign Subjects</label>
            <div id="edit-subjects-list" style="max-height:220px; overflow:auto; border:1px solid #e9ecef; padding:8px; border-radius:6px;">
              <?php foreach($subjects as $s): ?>
                <div class="form-check">
                  <input class="form-check-input edit-sub-checkbox" type="checkbox" name="subjects[]" value="<?php echo (int)$s['id']; ?>" id="s_edit_<?php echo (int)$s['id']; ?>">
                  <label class="form-check-label" for="s_edit_<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_title'] . ' (' . $s['course'] . ' Y' . $s['year'] . ')'); ?></label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>

<script>
(function(){
  function ensureBootstrapLoaded(cb){
    if (typeof window.bootstrap !== 'undefined') return cb();
    var s=document.getElementById('bootstrap-bundle-cdn');
    if (s){ var check=setInterval(function(){ if (typeof window.bootstrap !== 'undefined'){clearInterval(check);cb();} },50); return; }
    s=document.createElement('script'); s.id='bootstrap-bundle-cdn'; s.src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'; s.crossOrigin='anonymous';
    s.onload=function(){ cb(); }; s.onerror=function(){ cb(); }; document.body.appendChild(s);
  }

  ensureBootstrapLoaded(function(){
    document.querySelectorAll('.editTeacherBtn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-id');
        var name = btn.getAttribute('data-name') || '';
        var course = btn.getAttribute('data-course') || '';
        var year = btn.getAttribute('data-year') || '';
        var email = btn.getAttribute('data-email') || '';
        var desc = btn.getAttribute('data-desc') || '';
        var subjJson = btn.getAttribute('data-subjects') || '[]';
        var subjList = JSON.parse(subjJson || '[]');

        document.getElementById('edit-id').value = id;
        document.getElementById('edit-name').value = name;
        document.getElementById('edit-course').value = course;
        document.getElementById('edit-year').value = year;
        document.getElementById('edit-email').value = email;
        document.getElementById('edit-desc').value = desc;

        // uncheck all first
        document.querySelectorAll('#edit-subjects-list .edit-sub-checkbox').forEach(function(cb){ cb.checked = false; });
        subjList.forEach(function(sid){
          var el = document.getElementById('s_edit_' + sid);
          if (el) el.checked = true;
        });

        var modalEl = document.getElementById('editTeacherModal');
        var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        inst.show();
      });
    });

    // add fallback for add button
    var addBtn = document.getElementById('addTeacherBtn');
    if (addBtn) addBtn.addEventListener('click', function(){ var modalEl=document.getElementById('addTeacherModal'); var inst=bootstrap.Modal.getInstance(modalEl)||new bootstrap.Modal(modalEl); inst.show(); });
  });
})();
</script>
