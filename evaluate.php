<?php
// evaluate.php - full updated version
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'functions.php'; // must provide $conn (PDO) and optionally require_student()

// ensure student logged in
if (function_exists('require_student')) {
    require_student();
} else {
    if (empty($_SESSION['student_id'])) {
        header('Location: login.php');
        exit;
    }
}

$student_id = (int)($_SESSION['student_id'] ?? 0);
if ($student_id <= 0) { header('Location: login.php'); exit; }

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    catch (Exception $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}

// get student's course/year (session preferred)
$student_course = trim((string)($_SESSION['student_course'] ?? ''));
$student_year   = trim((string)($_SESSION['student_year'] ?? ''));

if ($student_course === '' || $student_year === '') {
    try {
        $s = $conn->prepare('SELECT course, year FROM students WHERE id = :id LIMIT 1');
        $s->execute([':id' => $student_id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $student_course = $student_course ?: trim((string)$row['course']);
            $student_year   = $student_year   ?: trim((string)$row['year']);
            $_SESSION['student_course'] = $student_course;
            $_SESSION['student_year']   = $student_year;
        }
    } catch (Exception $e) {
        // log but continue; safety defaults below handle missing values
        if (function_exists('error_log')) error_log('Student course/year fetch error: ' . $e->getMessage());
    }
}

$student_course_norm = strtoupper(trim((string)$student_course));
$student_year_norm   = trim((string)$student_year);

// teacher id from query
$teacher_id = (int)($_GET['teacher_id'] ?? 0);
if ($teacher_id <= 0) {
    $_SESSION['flash'] = ['message' => 'Invalid teacher', 'success' => false];
    header('Location: student_dashboard.php'); exit;
}

// fetch teacher
try {
    $tstmt = $conn->prepare('SELECT id, name, course, year, description FROM teachers WHERE id = :id LIMIT 1');
    $tstmt->execute([':id' => $teacher_id]);
    $teacher = $tstmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $teacher = false;
    if (function_exists('error_log')) error_log('Teacher fetch error: ' . $e->getMessage());
}

if (!$teacher) {
    // if teacher couldn't be found, redirect with message
    $_SESSION['flash'] = ['message' => 'Teacher not found', 'success' => false];
    header('Location: student_dashboard.php'); exit;
}

// Verify teacher is assigned to at least one subject for this student's course+year
$check = $conn->prepare("
    SELECT COUNT(*) FROM teacher_subjects ts
    JOIN subjects s ON s.id = ts.subject_id
    WHERE ts.teacher_id = :tid
      AND UPPER(TRIM(s.course)) = :course
      AND TRIM(s.year) = :year
");
try {
    $check->execute([':tid' => $teacher_id, ':course' => $student_course_norm, ':year' => $student_year_norm]);
    $allowedCount = (int)$check->fetchColumn();
} catch (Exception $e) {
    $allowedCount = 0;
    if (function_exists('error_log')) error_log('Authorization check error: ' . $e->getMessage());
}

if ($allowedCount === 0) {
    $_SESSION['flash'] = ['message' => 'You are not allowed to evaluate this teacher.', 'success' => false];
    header('Location: student_dashboard.php'); exit;
}

// check if already evaluated
$chk2 = $conn->prepare('SELECT id FROM evaluations WHERE student_id = :sid AND teacher_id = :tid LIMIT 1');
try {
    $chk2->execute([':sid' => $student_id, ':tid' => $teacher_id]);
    $already = (bool)$chk2->fetchColumn();
} catch (Exception $e) {
    $already = false;
    if (function_exists('error_log')) error_log('Already-check error: ' . $e->getMessage());
}

// Handle POST (submit evaluation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted_csrf)) {
        $_SESSION['flash'] = ['message' => 'Invalid CSRF token', 'success' => false];
        header('Location: student_dashboard.php'); exit;
    }

    // re-check authorization (defense in depth)
    try {
        $check->execute([':tid' => $teacher_id, ':course' => $student_course_norm, ':year' => $student_year_norm]);
        if ((int)$check->fetchColumn() === 0) {
            $_SESSION['flash'] = ['message' => 'You are not allowed to evaluate this teacher.', 'success' => false];
            header('Location: student_dashboard.php'); exit;
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['message' => 'Authorization check failed', 'success' => false];
        header('Location: student_dashboard.php'); exit;
    }

    // ensure not duplicate
    try {
        $chk2->execute([':sid' => $student_id, ':tid' => $teacher_id]);
        if ($chk2->fetchColumn()) {
            $_SESSION['flash'] = ['message' => 'You have already evaluated this teacher.', 'success' => false];
            header('Location: student_dashboard.php'); exit;
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['message' => 'Duplicate check failed', 'success' => false];
        header('Location: student_dashboard.php'); exit;
    }

    // fetch evaluation question ids
    try {
        $qrows = $conn->query('SELECT id FROM evaluation_questions ORDER BY ordering, id')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $qrows = [];
        if (function_exists('error_log')) error_log('Questions fetch error: ' . $e->getMessage());
    }

    if (empty($qrows)) {
        $_SESSION['flash'] = ['message' => 'No evaluation questions configured.', 'success' => false];
        header('Location: student_dashboard.php'); exit;
    }

    // collect and validate answers
    $answers = [];
    foreach ($qrows as $qid) {
        $key = 'q_' . $qid;
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            $_SESSION['flash'] = ['message' => 'Please answer all questions.', 'success' => false];
            header("Location: evaluate.php?teacher_id=" . urlencode($teacher_id)); exit;
        }
        $val = (int)$_POST[$key];
        if ($val < 1 || $val > 5) {
            $_SESSION['flash'] = ['message' => 'Invalid rating value.', 'success' => false];
            header("Location: evaluate.php?teacher_id=" . urlencode($teacher_id)); exit;
        }
        $answers[(int)$qid] = $val;
    }
    $comment = trim((string)($_POST['comment'] ?? ''));

    // Insert evaluation + answers inside transaction
    try {
        $conn->beginTransaction();

        $ins = $conn->prepare('INSERT INTO evaluations (student_id, teacher_id, comment, submitted_at) VALUES (:sid, :tid, :comment, NOW())');
        $ins->execute([':sid' => $student_id, ':tid' => $teacher_id, ':comment' => $comment]);

        $evalId = (int)$conn->lastInsertId();
        $ins2 = $conn->prepare('INSERT INTO evaluation_answers (evaluation_id, question_id, rating) VALUES (:eid, :qid, :rating)');
        foreach ($answers as $qid => $rating) {
            $ins2->execute([':eid' => $evalId, ':qid' => $qid, ':rating' => $rating]);
        }

        $conn->commit();
        $_SESSION['flash'] = ['message' => 'Thank you — your evaluation has been submitted.', 'success' => true];
        header('Location: student_dashboard.php'); exit;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        if (function_exists('error_log')) error_log('Evaluation save error: ' . $e->getMessage());
        $_SESSION['flash'] = ['message' => 'An unexpected error occurred while saving your evaluation. Please try again later.', 'success' => false];
        header('Location: student_dashboard.php'); exit;
    }
}

// Prepare questions for display
try {
    $questions_rows = $conn->query('SELECT id, group_title, question_text, ordering FROM evaluation_questions ORDER BY ordering, id')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $questions_rows = [];
    if (function_exists('error_log')) error_log('Questions fetch display error: ' . $e->getMessage());
}
$grouped = [];
foreach ($questions_rows as $r) {
    $g = $r['group_title'] ?: 'General';
    if (!isset($grouped[$g])) $grouped[$g] = [];
    $grouped[$g][] = $r;
}

// --- SAFETY: ensure variables used by HTML are defined to avoid warnings ---
if (!isset($teacher) || !$teacher || !is_array($teacher)) {
    $teacher = ['name' => 'Unknown', 'course' => '', 'year' => '', 'description' => ''];
}
$already = isset($already) ? (bool)$already : false;
$grouped  = isset($grouped) && is_array($grouped) ? $grouped : [];

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Evaluate <?php echo htmlspecialchars($teacher['name'] ?? 'Unknown'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
.rating-row {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
}
.question-text {
  flex: 1 1 60%;
  min-width: 220px;
}
.rating-group {
  display: flex;
  gap: 6px;
  align-items: center;
  justify-content: flex-end;
  flex: 0 0 36%;
  min-width: 160px;
}

.rating-input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.rating-label {
  display: inline-grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 999px;
  border: 2px solid rgba(0,0,0,0.12);
  cursor: pointer;
  font-weight: 700;
  font-size: 0.85rem;
  transition: all .12s ease;
  background: #fff;
  box-shadow: 0 1px 0 rgba(0,0,0,0.03);
}

.rating-label:hover {
  transform: translateY(-2px);
  border-color: rgba(0,0,0,0.35);
}

.rating-input:checked + .rating-label,
.rating-label.selected {
  background: linear-gradient(180deg, #1f6feb 0%, #155bd6 100%);
  color: #fff;
  border-color: rgba(0,0,0,0.08);
  box-shadow: 0 6px 18px rgba(17,24,39,0.12);
  transform: translateY(-1px);
}

@media (max-width:720px){
  .rating-row { flex-direction: column; gap:12px; }
  .rating-group { justify-content:flex-start; }
  .rating-label { width:30px; height:30px; font-size:0.78rem; }
}

/* give focus outlines for keyboard users */
.rating-input:focus + .rating-label {
  box-shadow: 0 0 0 4px rgba(31,111,235,0.12);
  outline: none;
}
</style>
</head>
<body>
<div class="container" style="max-width:900px;padding:24px;">

  <a href="student_dashboard.php" class="btn btn-link mb-3"><i class="bi bi-arrow-left"></i> Back</a>

  <!-- Teacher Header -->
  <div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h5 class="mb-0">Evaluate: <?php echo htmlspecialchars($teacher['name'] ?? 'Unknown'); ?></h5>
        <div class="small text-muted"><?php echo htmlspecialchars((($teacher['course'] ?? '') . ' — Year ' . ($teacher['year'] ?? ''))); ?></div>
      </div>
      <?php if ($already): ?><span class="badge bg-success">Already submitted</span><?php endif; ?>
    </div>
    <?php if (!empty($teacher['description'])): ?>
      <div class="mt-2 small text-muted"><?php echo htmlspecialchars($teacher['description'] ?? ''); ?></div>
    <?php endif; ?>
  </div>

  <!-- Instructions -->
  <div class="card border-primary mb-4" style="background:#f8fbff;">
    <div class="card-body">
      <h6 class="fw-bold text-primary mb-2"><i class="bi bi-info-circle-fill"></i> Instructions</h6>
      <ul class="mb-0 small">
        <li>Please answer each question honestly based on your experience with this teacher.</li>
        <li>Select a rating by clicking the numbers (5 highest, 1 lowest).</li>
        <li>All questions are required before submitting.</li>
        <li>Your evaluation is confidential.</li>
      </ul>
    </div>
  </div>

  <?php if ($already): ?>
    <div class="alert alert-info">You have already evaluated this teacher. Thank you.</div>
  <?php else: ?>

    <form method="post" action="evaluate.php?teacher_id=<?php echo urlencode($teacher_id); ?>" id="evalForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

      <?php if (empty($grouped)): ?>
        <div class="alert alert-warning">No evaluation questions found.</div>
      <?php else: ?>

        <?php foreach ($grouped as $group => $qs): ?>
          <div class="mb-2 fw-bold"><?php echo htmlspecialchars($group); ?></div>

          <div class="card p-3 mb-3">
            <?php foreach ($qs as $q): ?>
              <?php $qid = (int)$q['id']; $inputName = 'q_' . $qid; ?>
              <div class="mb-3">
                <div class="rating-row" role="group" aria-labelledby="qlabel_<?php echo $qid; ?>">
                  <div class="question-text">
                    <label id="qlabel_<?php echo $qid; ?>" class="form-label"><strong><?php echo htmlspecialchars($q['question_text']); ?></strong></label>
                  </div>

                  <div class="rating-group" aria-hidden="false">
                    <?php for ($val = 5; $val >= 1; $val--):
                      $rid = "q_{$qid}_{$val}"; ?>
                      <input class="rating-input" type="radio"
                             name="<?php echo htmlspecialchars($inputName); ?>"
                             id="<?php echo htmlspecialchars($rid); ?>"
                             value="<?php echo $val; ?>"
                             data-qid="<?php echo $qid; ?>" />
                      <label class="rating-label" for="<?php echo htmlspecialchars($rid); ?>" data-val="<?php echo $val; ?>">
                        <?php echo $val; ?>
                      </label>
                    <?php endfor; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <div class="mb-3">
          <label class="form-label">Optional Comment</label>
          <textarea name="comment" class="form-control" rows="3"></textarea>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-secondary" onclick="location.href='student_dashboard.php'">Cancel</button>
          <button type="submit" class="btn btn-primary">Submit Evaluation</button>
        </div>

      <?php endif; ?>
    </form>

  <?php endif; ?>

</div>

<script>
(function(){
  var form = document.getElementById('evalForm');

  // delegated change listener for rating inputs
  document.addEventListener('change', function(e){
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('rating-input')) return;
    var name = t.name;
    var radios = document.querySelectorAll('input[name="'+name+'"]');
    radios.forEach(function(r){
      var lab = document.querySelector('label[for="'+r.id+'"]');
      if (lab) lab.classList.remove('selected');
    });
    var lab = document.querySelector('label[for="'+t.id+'"]');
    if (lab) lab.classList.add('selected');
  }, true);

  // restore states for any pre-checked radios (browser autofill)
  document.querySelectorAll('.rating-input:checked').forEach(function(r){
    var lab = document.querySelector('label[for="'+r.id+'"]');
    if (lab) lab.classList.add('selected');
  });

  // client-side require-all guard only if form exists
  if (form) {
    form.addEventListener('submit', function(e){
      var groups = {};
      document.querySelectorAll('.rating-input').forEach(function(r){ groups[r.name] = true; });
      for (var name in groups) {
        var checked = document.querySelector('input[name="'+name+'"]:checked');
        if (!checked) {
          e.preventDefault();
          alert('Please answer all questions before submitting.');
          var first = document.querySelector('input[name="'+name+'"]');
          if (first) first.focus();
          return false;
        }
      }
      return true;
    }, false);
  }
})();
</script>

</body>
</html>
