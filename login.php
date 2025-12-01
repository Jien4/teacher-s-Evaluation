<?php
// index.php - Student Login (hardened / fixed)
if (session_status() !== PHP_SESSION_ACTIVE) {
}

$err = '';
require_once 'functions.php';

if (!isset($conn) || !($conn instanceof PDO)) {
    $err = 'Application error: database connection not available.';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {

        $school_id = trim((string) ($_POST['school_id'] ?? ''));
        $password  = (string) ($_POST['password'] ?? '');

        if ($school_id === '' || $password === '') {
            $err = 'Fill required fields.';
        } else {
            $s = $conn->prepare('SELECT id, fullname, course, password FROM students WHERE school_id = ? LIMIT 1');
            $s->execute([$school_id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);

            if ($row && !empty($row['password']) && password_verify($password, $row['password'])) {
                session_regenerate_id(true);

                $_SESSION['student_id']     = $row['id'];
                $_SESSION['student_name']   = $row['fullname'];
                $_SESSION['student_course'] = $row['course'];

                if (function_exists('audit')) {
                    try { audit($conn, 'student', $row['id'], 'student_login', 'login success'); }
                    catch(Throwable $ae){}
                }

                header('Location: student_dashboard.php');
                exit;
            } else {
                $err = 'Invalid credentials.';
            }
        }
    }
} catch (Throwable $e) {
    $err = 'An unexpected error occurred. Please try again later.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Student Login</title>

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
    height:100%;
    margin:0;
    font-family:Inter,system-ui,sans-serif;
  }

  /* DARK BLUE SLANTED BACKGROUND */
  body{
    background:linear-gradient(135deg,var(--blue1) 0%,var(--blue2) 45%,var(--blue3) 100%);
    overflow-x:hidden;
    position:relative;
  }

  /* top slanted shine */
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

  /* bottom slanted shine */
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

  /* HEADER */
  .topbar{
    background:rgba(255,255,255,0.1);
    backdrop-filter:blur(6px);
    border-bottom:1px solid rgba(255,255,255,0.1);
    box-shadow:0 2px 8px rgba(0,0,0,0.25);
    color:white;
    position:relative;
    z-index:10;
  }
  .topbar a{ color:#dce4ff; text-decoration:none; }
  .topbar a:hover{ color:#ffffff; }

  .brand{
    color:white;
    font-weight:700;
  }

  /* LOGIN CARD */
  .auth-card{
    border:0;
    border-radius:.85rem;
    background:var(--card-bg);
    box-shadow:0 16px 45px rgba(0,0,0,0.45);
    padding:28px;
    margin-top:50px;
    position:relative;
    z-index:10;
    animation:fadeUp .5s ease;
  }

  @keyframes fadeUp{
    from{opacity:0; transform:translateY(15px);}
    to{opacity:1; transform:translateY(0);}
  }

  .form-label{ font-weight:600; }

  .form-control:focus{
    border-color:#0d6efd;
    box-shadow:0 0 0 .15rem rgba(13,110,253,0.25);
  }

  .btn-primary{
    background:linear-gradient(135deg,#0a43b8,#052a73);
    border:0;
    padding:10px 0;
    box-shadow:0 6px 18px rgba(0,0,0,0.35);
    font-weight:600;
  }
  .btn-primary:hover{
    background:linear-gradient(135deg,#06328d,#041f58);
  }

  h3{ font-weight:700; }

  .small-muted{ color:var(--muted); }

  footer{
    color:#d0d8e8;
    text-align:center;
    margin-top:40px;
    font-size:.92rem;
  }
</style>
</head>
<body>

<header class="topbar py-2 mb-4">
  <div class="container d-flex justify-content-between align-items-center">
    <div class="brand fs-4"><i class="bi bi-person-badge-fill"></i> Teacher's Evaluations</div>
    <div class="small">
      <a href="register.php" class="me-3">Register</a>
      <a href="admin_login.php">Admin</a>
    </div>
  </div>
</header>

<div class="container" style="max-width:430px;">
  <div class="auth-card">

    <h3 class="text-center mb-1">Student Login</h3>
    

    <?php if ($err): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="mb-3">
        <label for="school_id" class="form-label">School ID</label>
        <input id="school_id" name="school_id" class="form-control"
               value="<?php echo htmlspecialchars($_POST['school_id'] ?? ''); ?>" required>
      </div>

      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input id="password" type="password" name="password" class="form-control" required>
      </div>

      <button class="btn btn-primary w-100 mb-3" type="submit">
        <i class="bi bi-box-arrow-in-right"></i> Sign in
      </button>

      <div class="d-flex justify-content-between mb-2 small">
        <div>Don't have an account? <a href="register.php">Register</a></div>
        <div><a href="student_forgot.php">Forgot password?</a></div>
      </div>

      <hr>

      <div class="text-center small">
        <a href="admin_login.php">Admin Login</a>
      </div>
    </form>

  </div>
</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
