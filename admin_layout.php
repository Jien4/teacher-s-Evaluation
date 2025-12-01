<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'functions.php';
require_admin();

// compute base path (works when app is in a subfolder or at web root)
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$base = ($scriptPath === '/' ? '' : rtrim($scriptPath, '/'));

// username for topbar
$fullnameInSession = $_SESSION['username'] ?? 'Administrator';

/**
 * helper to build safe hrefs
 */
function admin_href($base, $file) {
    // ensure leading slash for browser absolute path
    $path = ($base === '') ? '/' . ltrim($file, '/') : $base . '/' . ltrim($file, '/');
    return htmlspecialchars($path, ENT_QUOTES | ENT_HTML5);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($page_title ?? 'Admin') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body { background:#f4f6f9; font-family: Inter, sans-serif; }
#sidebar { width:240px; position:fixed; top:0; left:0; bottom:0; background:#263238; color:#fff; overflow:auto; }
#sidebar .brand { padding:18px; text-align:center; font-weight:700; border-bottom:1px solid rgba(255,255,255,0.04); }
#sidebar .nav-link { color:#cfd8dc; padding:12px 18px; display:block; text-decoration:none; }
#sidebar .nav-link:hover { background: rgba(255,255,255,0.03); color:#fff; }
#sidebar .nav-link.active { background:#0d6efd; color:#fff; }
#content { margin-left:240px; padding:20px; min-height:100vh; }
.topbar { background:#1976d2; color:#fff; padding:12px 16px; border-radius:.4rem; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; }
@media (max-width:991px) {
  #sidebar { position:relative; width:100%; height:auto; }
  #content { margin-left:0; padding:12px; }
}
</style>
</head>

<body>

<div id="sidebar">
  <div class="brand">Admin Panel</div>

  <a class="nav-link <?= ($active === 'dashboard' ? 'active' : '') ?>" href="<?= admin_href($base, 'admin_dashboard_full.php') ?>">
    <i class="bi bi-speedometer2 me-2"></i> Dashboard
  </a>

  <a class="nav-link <?= ($active === 'users' ? 'active' : '') ?>" href="<?= admin_href($base, 'manage_users.php') ?>">
    <i class="bi bi-people me-2"></i> Manage Users
  </a>

  <a class="nav-link <?= ($active === 'courses' ? 'active' : '') ?>" href="<?= admin_href($base, 'manage_courses.php') ?>">
    <i class="bi bi-journal-bookmark me-2"></i> Manage Courses
  </a>

  <!-- NEW: Manage Subjects -->
  <a class="nav-link <?= ($active === 'subjects' ? 'active' : '') ?>" href="<?= admin_href($base, 'manage_subjects.php') ?>">
    <i class="bi bi-list-ul me-2"></i> Manage Subjects
  </a>

  <a class="nav-link <?= ($active === 'period' ? 'active' : '') ?>" href="<?= admin_href($base, 'evaluation_period.php') ?>">
    <i class="bi bi-calendar2-event me-2"></i> Set Evaluation Period
  </a>

  <a class="nav-link <?= ($active === 'questions' ? 'active' : '') ?>" href="<?= admin_href($base, 'manage_questions.php') ?>">
    <i class="bi bi-question-circle me-2"></i> Manage Questions
  </a>

   <a class="nav-link <?= ($active === 'teachers' ? 'active' : '') ?>" href="<?= admin_href($base, 'manage_teachers.php') ?>">
    <i class="bi bi-question-circle me-2"></i> Manage Teachers
  </a>

  <a class="nav-link <?= ($active === 'monitor' ? 'active' : '') ?>" href="<?= admin_href($base, 'monitor_submissions.php') ?>">
    <i class="bi bi-check2-square me-2"></i> Monitor Submissions
  </a>

  <a class="nav-link <?= ($active === 'reports' ? 'active' : '') ?>" href="<?= admin_href($base, 'evaluation_report.php') ?>">
    <i class="bi bi-graph-up me-2"></i> Generate Reports
  </a>

  <div class="p-3">
    <a href="<?= admin_href($base, 'logout.php') ?>" class="btn btn-outline-light w-100"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
  </div>
</div>

<div id="content">
  <div class="topbar">
    <strong><?= htmlspecialchars($page_title ?? 'Admin Dashboard') ?></strong>
    <div><?= htmlspecialchars($fullnameInSession) ?></div>
  </div>

  <?= $content ?? '' ?>
</div>

</body>
</html>
