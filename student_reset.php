<?php
// student_reset.php
require_once 'functions.php'; // must provide $conn (PDO), audit(), config starts session
$err = '';
$success = '';
$show_form = false;

// Get uid/token from GET or POST (POST when submitting)
$uid = $_REQUEST['uid'] ?? '';
$token = $_REQUEST['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Validate presence
    if (empty($uid) || empty($token)) {
        $err = 'Invalid or missing reset link.';
    } else {
        try {
            // Fetch stored reset row
            $stmt = $conn->prepare('SELECT * FROM password_resets WHERE student_id = ? LIMIT 1');
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $err = 'Invalid or expired reset link.';
            } else {
                // Verify token against hashed value
                if (!password_verify($token, $row['token'])) {
                    $err = 'Invalid or expired reset link.';
                } elseif (strtotime($row['expires_at']) < time()) {
                    $err = 'This reset link has expired.';
                } else {
                    // OK: show form
                    $show_form = true;
                }
            }
        } catch (PDOException $ex) {
            // Generic message
            $err = 'An error occurred. Please try again later.';
            // optionally log $ex->getMessage()
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // We expect uid and token to be in POST as hidden fields
    $uid = $_POST['uid'] ?? '';
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (empty($uid) || empty($token)) {
        $err = 'Invalid request.';
    } elseif (empty($password) || empty($password2)) {
        $err = 'Please fill required fields.';
    } elseif ($password !== $password2) {
        $err = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $err = 'Password must be at least 6 characters.';
    } else {
        try {
            $stmt = $conn->prepare('SELECT * FROM password_resets WHERE student_id = ? LIMIT 1');
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $err = 'Invalid or expired reset link.';
            } elseif (!password_verify($token, $row['token'])) {
                $err = 'Invalid or expired reset link.';
            } elseif (strtotime($row['expires_at']) < time()) {
                $err = 'This reset link has expired.';
            } else {
                // Update student's password
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $u = $conn->prepare('UPDATE students SET password = ? WHERE id = ?');
                $u->execute([$hash, $uid]);

                // Delete the reset token
                $d = $conn->prepare('DELETE FROM password_resets WHERE student_id = ?');
                $d->execute([$uid]);

                // Audit
                if (function_exists('audit')) {
                    try {
                        audit($conn, 'student', $uid, 'password_reset', 'password changed via reset link');
                    } catch (Throwable $_) {
                        // ignore audit errors
                    }
                }

                $success = 'Your password has been updated. You may now sign in.';
                // do not show form after success
                $show_form = false;
            }
        } catch (PDOException $ex) {
            $err = 'An unexpected error occurred. Please try again later.';
            // optionally log $ex->getMessage()
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reset password — TeacherEval</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{ background: linear-gradient(180deg,#f8fafc 0%,#eef2f7 100%); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial;}
    .auth-card{ max-width:520px; margin:6vh auto; padding:24px; border-radius:.85rem; box-shadow:0 10px 30px rgba(0,0,0,0.06); background:#fff;}
  </style>
</head>
<body>
  <div class="container">
    <div class="auth-card">
      <h4 class="mb-1">Reset password</h4>
      <p class="text-muted mb-3">Choose a new password for your account.</p>

      <?php if ($err): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="text-center mt-3">
          <a href="login.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
        </div>
      <?php endif; ?>

      <?php if ($show_form): ?>
        <form method="post" novalidate>
          <input type="hidden" name="uid" value="<?php echo htmlspecialchars($uid, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

          <div class="mb-3">
            <label class="form-label">New password</label>
            <input type="password" name="password" class="form-control" required>
            <div class="form-text">At least 6 characters.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Confirm new password</label>
            <input type="password" name="password2" class="form-control" required>
          </div>

          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle"></i> Update password</button>
        </form>
      <?php endif; ?>

      <div class="mt-3 small text-center">
        <a href="login.php">Back to login</a>
      </div>
    </div>
  </div>
</body>
</html>
