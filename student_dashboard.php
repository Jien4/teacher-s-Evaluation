<?php
require_once 'functions.php';
require_student();

$student_id = $_SESSION['student_id'] ?? 0;

// determine student's course/year (session preferred)
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
        // ignore
    }
}

$student_course_norm = mb_strtoupper(trim((string)$student_course));
$student_year_norm = trim((string)$student_year);

// If course/year available, fetch teachers who are assigned to subjects matching that course+year
$teachers = [];
try {
    if ($student_course_norm !== '' && $student_year_norm !== '') {
        $stmt = $conn->prepare("
          SELECT DISTINCT t.id, t.name, t.course, t.year, t.description
          FROM teacher_subjects ts
          JOIN subjects s ON s.id = ts.subject_id
          JOIN teachers t ON t.id = ts.teacher_id
          WHERE UPPER(TRIM(s.course)) = :course
            AND TRIM(s.year) = :year
          ORDER BY t.name ASC
        ");
        $stmt->execute([':course' => $student_course_norm, ':year' => $student_year_norm]);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $teachers = [];
}

// get list of evaluated teacher ids by this student
$evaluated = [];
try {
    $stmt = $conn->prepare("SELECT teacher_id FROM evaluations WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $evaluated = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $evaluated = array_map('intval', $evaluated);
} catch (Exception $e) {
    $evaluated = [];
}

$totalTeachers = count($teachers);
$completedCount = count(array_intersect($evaluated, array_map(function($t){ return (int)$t['id']; }, $teachers)));
$remaining = max(0, $totalTeachers - $completedCount);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Student Dashboard</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
  :root{
    --blue1:#052a73;
    --blue2:#0c3b9d;
    --blue3:#0d47c3;
    --muted:#9aa6bf;
    --text:#111827;
    --card-bg: rgba(245,245,245,0.86); /* soft, not blinding */
    --glass-border: rgba(255,255,255,0.32);
    --accent: #0d6efd;
    --success: #198754;
  }

  html,body{ height:100%; margin:0; font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; color:var(--text); -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }

  /* DARK SLANTED BACKGROUND (site-wide) */
  body{
    background:linear-gradient(135deg,var(--blue1) 0%,var(--blue2) 45%,var(--blue3) 100%);
    position:relative;
    overflow-x:hidden;
    padding-bottom:40px;
  }

  /* subtle diagonal overlays */
  body::before{
    content:"";
    position:absolute;
    top:-18%;
    left:-20%;
    width:160%;
    height:72%;
    background:linear-gradient(135deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02));
    transform:rotate(-8deg);
    border-radius:18px;
    z-index:0;
  }
  body::after{
    content:"";
    position:absolute;
    bottom:-22%;
    right:-28%;
    width:150%;
    height:64%;
    background:linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
    transform:rotate(10deg);
    border-radius:18px;
    z-index:0;
  }

  /* TOPBAR (soft glass) */
  .topbar{
    background: rgba(255,255,255,0.06);
    backdrop-filter: blur(6px);
    border-bottom:1px solid rgba(255,255,255,0.08);
    padding: 16px 0;
    position:relative;
    z-index:10;
  }
  .brand { font-weight:700; color: #fff; letter-spacing:.2px; }
  .small-muted { color: rgba(255,255,255,0.85); opacity:0.9; }

  .container { max-width:1100px; }

  /* Main card area wrapper */
  main {
    margin-top:18px;
    position:relative;
    z-index:10;
  }

  /* Softened cards for teacher items */
  .card-teacher {
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    background: var(--card-bg);
    box-shadow: 0 12px 30px rgba(2,6,23,0.20);
    transition: transform .18s ease, box-shadow .18s ease;
    min-height: 150px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
  }
  .card-teacher:hover {
    transform: translateY(-6px);
    box-shadow: 0 20px 50px rgba(2,6,23,0.28);
  }

  .teacher-grid { gap:18px; }

  .avatar {
    width:56px;
    height:56px;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    color:#fff;
    background: linear-gradient(135deg, #0d6efd, #6610f2);
    font-size:20px;
  }

  .teacher-meta { color:var(--muted); font-size:0.92rem; }

  .stat { font-weight:700; font-size:1.25rem; color:#fff; }
  .progress-pill {
    display:inline-block;
    padding:8px 12px;
    border-radius:999px;
    background: rgba(255,255,255,0.07);
    color:#fff;
    font-weight:600;
  }

  input[type="search"].search-input {
    max-width:420px;
    border-radius: 999px;
    padding-left:18px;
    padding-right:18px;
  }

  /* Buttons */
  .btn-primary {
    background: linear-gradient(135deg,#0a43b8,#052a73);
    border:0;
    box-shadow: 0 8px 20px rgba(0,0,0,0.28);
    font-weight:600;
  }
  .btn-primary:focus, .btn-primary:hover { background: linear-gradient(135deg,#06328d,#041f58); }

  .btn-outline-success {
    color: var(--success);
    border-color: rgba(25,135,84,0.12);
    background: transparent;
  }

  /* Logout button - avoid turning too white on hover */
  .btn-logout {
    display:inline-flex;
    align-items:center;
    gap:.5rem;
    padding:.35rem .6rem;
    border-radius:.35rem;
    border:1px solid rgba(255,255,255,0.18);
    color:#fff;
    background: rgba(255,255,255,0.03);
    text-decoration:none;
    font-weight:600;
  }
  .btn-logout:hover, .btn-logout:focus {
    background: rgba(255,255,255,0.10); /* slightly brighter but not pure white */
    color: #ffffff;
    text-decoration:none;
    outline: none;
    box-shadow: 0 6px 18px rgba(2,6,23,0.18);
  }
  .btn-logout:active { transform: translateY(1px); }

  .badge-success {
    background: linear-gradient(135deg,#1ca06b,#198754);
    color: #fff;
  }

  .badge-pending {
    background: rgba(0,0,0,0.06);
    color: #4b5563;
  }

  /* responsive tweaks */
  @media (max-width: 768px) {
    .container { padding-left:16px; padding-right:16px; }
    .stat { font-size:1.1rem; }
  }

</style>
</head>
<body>

<header class="topbar mb-3">
  <div class="container d-flex justify-content-between align-items-center">
    <div>
      <div class="brand">Teacher's Evaluations</div>
      <div class="small-muted">Student Portal</div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <div class="text-end me-3 small-muted" style="line-height:1.05;">
        <div style="font-size:.95rem;">Course: <strong style="color:#fff;"><?php echo htmlspecialchars($student_course ?: '-'); ?></strong> &nbsp; Year: <strong style="color:#fff;"><?php echo htmlspecialchars($student_year ?: '-'); ?></strong></div>
        <div class="small" style="color:rgba(255,255,255,0.85);">Welcome, <?php echo htmlspecialchars($_SESSION['student_name'] ?? ''); ?></div>
      </div>
      <a href="logout.php" class="btn-logout" aria-label="Logout">
        <i class="bi bi-box-arrow-right" style="font-size:1rem;"></i>
        <span style="font-size:.95rem;">Logout</span>
      </a>
    </div>
  </div>
</header>

<main class="container">
  <div class="row mb-3 align-items-center">
    <div class="col-12 col-md-8">
      <h4 class="mb-0" style="color:#fff;">Select a Teacher to Evaluate</h4>
      <div class="small-muted" style="color: rgba(255,255,255,0.85);">Only teachers assigned to subjects for your course and year are shown.</div>
    </div>
    <div class="col-12 col-md-4 text-md-end mt-3 mt-md-0">
      <div class="d-inline-block text-md-end">
        <div class="small-muted" style="color: rgba(255,255,255,0.85);">Progress</div>
        <div class="stat"><?php echo (int)$completedCount; ?> / <?php echo (int)$totalTeachers; ?></div>
        <div class="small-muted" style="color: rgba(255,255,255,0.85);"><?php echo (int)$remaining; ?> remaining</div>
      </div>
    </div>
  </div>

  <div class="row mb-3">
    <div class="col-12 col-md-6">
      <input id="search" type="search" class="form-control search-input" placeholder="Search teacher (type to filter)" aria-label="Search teacher">
    </div>
  </div>

  <div class="row teacher-grid">
    <?php if ($student_course === '' || $student_year === ''): ?>
      <div class="col-12">
        <div class="alert alert-warning">Your course or year is not set. Please update your student profile or contact the administrator.</div>
      </div>
    <?php elseif (empty($teachers)): ?>
      <div class="col-12">
        <div class="alert alert-secondary">No teachers found for <?php echo htmlspecialchars($student_course . ' Year ' . $student_year); ?>.</div>
      </div>
    <?php else: foreach($teachers as $t):
        $tid = (int)$t['id'];
        $completed = in_array($tid, $evaluated, true);
    ?>
      <div class="col-12 col-sm-6 col-lg-4 teacher-card" data-name="<?php echo htmlspecialchars(strtolower($t['name'] . ' ' . ($t['course'] ?? ''))); ?>">
        <div class="card card-teacher p-3 h-100">
          <div class="d-flex align-items-start">
            <div class="me-3">
              <div class="avatar" aria-hidden="true">
                <?php echo htmlspecialchars(mb_substr(trim($t['name']),0,1)); ?>
              </div>
            </div>

            <div class="flex-fill">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <div>
                  <div style="font-weight:700; font-size:1rem; color:#0f172a;"><?php echo htmlspecialchars($t['name']); ?></div>
                  <div class="teacher-meta"><?php echo htmlspecialchars($t['course'] . ' — Year ' . $t['year']); ?></div>
                </div>
                <div class="text-end">
                  <?php if ($completed): ?>
                    <span class="badge badge-success" style="padding:.45em .6em; border-radius:.6rem; background:linear-gradient(135deg,#20c997,#198754); color:#fff;">Completed</span>
                  <?php else: ?>
                    <span class="badge badge-pending" style="padding:.45em .6em; border-radius:.6rem;">Pending</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="mt-2">
                <?php if ($completed): ?>
                  <button class="btn btn-outline-success btn-sm" disabled><i class="bi bi-check2-circle"></i> Completed</button>
                <?php else: ?>
                  <a class="btn btn-primary btn-sm" href="evaluate.php?teacher_id=<?php echo urlencode($tid); ?>"><i class="bi bi-pencil-square"></i> Evaluate</a>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if (!empty($t['description'])): ?>
            <div class="mt-3 small text-muted" style="color:#4b5563;"><?php echo htmlspecialchars($t['description']); ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</main>

<script>
  (function(){
    const search = document.getElementById('search');
    if (!search) return;
    const cards = Array.from(document.querySelectorAll('.teacher-card'));
    search.addEventListener('input', function(){
      const q = this.value.trim().toLowerCase();
      cards.forEach(c=>{
        const name = c.getAttribute('data-name') || '';
        const show = q === '' || name.indexOf(q) !== -1;
        c.style.display = show ? '' : 'none';
      });
    });
  })();
</script>

</body>
</html>
