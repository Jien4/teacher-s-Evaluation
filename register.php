<?php
// student_register.php
require_once 'functions.php';

$err = '';
$success = '';

$year_options = ['1','2','3','4','5'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullname  = trim($_POST['fullname'] ?? '');
  $school_id = trim($_POST['school_id'] ?? '');
  $course    = trim($_POST['course'] ?? '');
  $year      = trim($_POST['year'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $password  = $_POST['password'] ?? '';

  if (!$fullname || !$school_id || !$email || !$password || !$year) {
    $err = 'Please fill required fields.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Please enter a valid email address.';
  } elseif (strlen($password) < 6) {
    $err = 'Password must be at least 6 characters.';
  } else {
    try {
      $s = $conn->prepare('SELECT id FROM students WHERE school_id = ? LIMIT 1');
      $s->execute([$school_id]);
      if ($s->fetch()) {
        $err = 'School ID already registered.';
      } else {
        $se = $conn->prepare('SELECT id FROM students WHERE email = ? LIMIT 1');
        $se->execute([$email]);
        if ($se->fetch()) {
          $err = 'This email is already registered.';
        } else {
          $pw = password_hash($password, PASSWORD_DEFAULT);
          $ins = $conn->prepare('INSERT INTO students (fullname, school_id, course, year, email, password) VALUES (?,?,?,?,?,?)');
          $ins->execute([$fullname, $school_id, $course, $year, $email, $pw]);
          $student_id = $conn->lastInsertId();

          if (function_exists('audit')) {
            try { audit($conn, 'student', $student_id, 'student_registered', 'registered via public form'); } catch(Throwable $_){}
          }

          try {
            $q = $conn->prepare('SELECT id FROM subjects WHERE UPPER(TRIM(course)) = UPPER(TRIM(?)) AND TRIM(year) = TRIM(?)');
            $q->execute([$course, $year]);
            $subject_ids = $q->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($subject_ids)) {
              $insEnroll = $conn->prepare('INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)');
              foreach ($subject_ids as $sid) {
                $insEnroll->execute([$student_id, $sid]);
              }
            }
          } catch(Throwable $e){}

          $success = 'Registration successful. You can now login.';
          $_POST = [];
        }
      }
    } catch (PDOException $ex) {
      $err = 'An unexpected error occurred. Please try again later.';
      error_log($ex->getMessage());
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Student Register</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
  :root{
    --blue1:#052a73;
    --blue2:#0c3b9d;
    --blue3:#0d47c3;
    --muted:#adb5bd;
    --card-bg:#ffffff;
  }

  html,body{
    margin:0;
    height:100%;
    font-family:Inter,system-ui,sans-serif;
  }

  /* Dark slanted background */
  body{
    background:linear-gradient(135deg,var(--blue1) 0%,var(--blue2) 45%,var(--blue3) 100%);
    overflow-x:hidden;
    position:relative;
    padding-top:40px;
    padding-bottom:40px;
  }

  body::before{
    content:"";
    position:absolute;
    top:-20%;
    left:-25%;
    width:170%;
    height:70%;
    background:linear-gradient(135deg,rgba(255,255,255,0.07),rgba(255,255,255,0.03));
    transform:rotate(-8deg);
    border-radius:20px;
    z-index:0;
  }

  body::after{
    content:"";
    position:absolute;
    bottom:-25%;
    right:-30%;
    width:160%;
    height:65%;
    background:linear-gradient(135deg,rgba(255,255,255,0.05),rgba(255,255,255,0.02));
    transform:rotate(10deg);
    border-radius:20px;
    z-index:0;
  }

  /* Softened (not-blinding) card style */
  .auth-card{
    max-width:620px;
    margin:auto;
    padding:22px 26px;
    border-radius:12px;

    /* Softer, not pure white — easier on the eyes */
    background: rgba(245,245,245,0.86); /* light gray-white with slight transparency */
    backdrop-filter: blur(8px);

    /* softer border */
    border:1px solid rgba(255,255,255,0.32);

    /* softer but deep shadow for readability on dark bg */
    box-shadow:0 10px 30px rgba(2,6,23,0.25);

    position:relative;
    z-index:10;
    animation:fadeUp .45s ease;
  }

  @keyframes fadeUp{
    from{opacity:0; transform:translateY(12px);}
    to{opacity:1; transform:translateY(0);}
  }

  h4{ font-weight:700; }
  .lead{ color:var(--muted); font-size:.95rem; }

  .form-label{ font-weight:600; }

  .form-control:focus, .form-select:focus{
    border-color:#0d6efd;
    box-shadow:0 0 0 .12rem rgba(13,110,253,0.18);
  }

  .btn-primary{
    background:linear-gradient(135deg,#0a43b8,#052a73);
    border:0;
    padding:10px 14px;
    box-shadow:0 8px 20px rgba(0,0,0,0.28);
    font-weight:600;
  }
  .btn-primary:hover{
    background:linear-gradient(135deg,#06328d,#041f58);
  }

  .small-muted{ color:var(--muted); }
  .links-row{ font-size:.92rem; }

  @media (max-width: 520px) {
    .auth-card { padding: 16px; margin: 18px; }
  }
</style>

</head>
<body>

<div class="auth-card">

  <div class="d-flex justify-content-between align-items-start mb-2">
    <div>
      <h4>Student Registration</h4>
      <div class="lead">Create your account to submit evaluations</div>
    </div>
    <div class="text-end small-muted">
      <a href="admin_login.php" class="text-decoration-none">Admin</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger py-2"><?php echo htmlspecialchars($err); ?></div>
  <?php elseif ($success): ?>
    <div class="alert alert-success py-2"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <div class="row g-3">

      <div class="col-12">
        <label class="form-label" for="fullname">Full name *</label>
        <input id="fullname" name="fullname" class="form-control form-control-sm"
               value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label" for="school_id">School ID *</label>
        <input id="school_id" name="school_id" class="form-control form-control-sm"
               value="<?php echo htmlspecialchars($_POST['school_id'] ?? ''); ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label" for="course">Course</label>
        <input id="course" name="course" class="form-control form-control-sm"
               value="<?php echo htmlspecialchars($_POST['course'] ?? ''); ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label" for="year">Year *</label>
        <select id="year" name="year" class="form-select form-select-sm" required>
          <option value="">Select year</option>
          <?php foreach ($year_options as $opt): ?>
            <option value="<?php echo $opt; ?>" <?php echo (($_POST['year'] ?? '') === $opt) ? 'selected' : ''; ?>>
              <?php echo $opt; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="small-muted mt-1">Used to load your correct subjects.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label" for="email">Email *</label>
        <input id="email" type="email" name="email" class="form-control form-control-sm"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        <div class="small-muted mt-1">Needed for password recovery.</div>
      </div>

      <div class="col-12">
        <label class="form-label" for="password">Password *</label>
        <input id="password" type="password" name="password" class="form-control form-control-sm" required>
        <div class="small-muted mt-1">Minimum 6 characters.</div>
      </div>

    </div>

    <div class="d-grid gap-2 my-3">
      <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus-fill me-1"></i> Register</button>
    </div>

    <div class="d-flex justify-content-between links-row mb-2">
      <div class="small-muted">Already have an account? <a href="login.php">Login</a></div>
      <div class="small-muted"><a href="student_forgot.php">Forgot password?</a></div>
    </div>

    <hr>

    <div class="text-center small-muted">Need help? Contact your administrator.</div>
    <div class="text-center mt-2 small"><a href="admin_login.php">Admin Login</a></div>
  </form>
</div>

</body>
</html>
