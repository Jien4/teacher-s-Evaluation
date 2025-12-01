<?php
// student_forgot.php (updated - PHPMailer / SMTP support + dev fallback)
require_once 'functions.php'; // should define $conn (PDO), audit(), and may contain SMTP config variables
$err = '';
$success = '';

// DEV: show reset link on screen if mail cannot be sent (keep true while testing)
$dev_show_link = true;

// Try to include Composer autoload if available
$composerAutoload = __DIR__ . '/vendor/autoload.php';
$hasComposer = false;
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
    $hasComposer = true;
}

// PHPMailer classes (if autoloaded)
if ($hasComposer) {
    // use statements for readability (no-op if classes already loaded)
    // we don't use "use" in the global scope string output; but they are helpful if editing code.
    // (PHPMailer classes will be referenced with full namespaces below.)
}

// Basic helper to check SMTP config presence
function smtp_configured(): bool {
    return !empty($GLOBALS['mail_host'])
        && !empty($GLOBALS['mail_username'])
        && !empty($GLOBALS['mail_password'])
        && !empty($GLOBALS['mail_from'])
        && !empty($GLOBALS['mail_from_name'])
        && !empty($GLOBALS['mail_port']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $err = 'Please enter your School ID or registered email.';
    } else {
        // Try the query that expects an email column. If DB doesn't have it, catch the exception and fallback.
        $hasEmailColumn = true;
        try {
            $stmt = $conn->prepare('SELECT id, fullname, email FROM students WHERE school_id = ? OR email = ? LIMIT 1');
            $stmt->execute([$identifier, $identifier]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $ex) {
            // If column not found, fallback to query without email
            if ($ex->getCode() === '42S22' || stripos($ex->getMessage(), 'Unknown column') !== false) {
                $hasEmailColumn = false;
                try {
                    $stmt = $conn->prepare('SELECT id, fullname FROM students WHERE school_id = ? LIMIT 1');
                    $stmt->execute([$identifier]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $ex2) {
                    $row = false;
                    $err = 'A database error occurred. Please try again later.';
                    // Optionally log $ex2->getMessage() to a file for debugging
                }
            } else {
                // Unexpected DB error
                $err = 'A database error occurred. Please try again later.';
                // Optionally log $ex->getMessage() to a file for debugging
                $row = false;
            }
        }

        // Do not reveal whether account exists — but for dev convenience we may show the link.
        if (!$row) {
            $success = 'If an account with that School ID / email exists, a password reset link was sent.';
        } else {
            // If we have an email column and the user has an email, send email. Otherwise fallback.
            $studentId = $row['id'];
            $fullname = $row['fullname'];
            $emailAvailable = $hasEmailColumn && !empty($row['email']);

            // generate token and store hashed token
            try {
                $plainToken = bin2hex(random_bytes(24));
            } catch (Exception $e) {
                // fallback in unlikely event random_bytes fails
                $plainToken = bin2hex(openssl_random_pseudo_bytes(24));
            }
            $hashedToken = password_hash($plainToken, PASSWORD_DEFAULT);
            $expires_at = date('Y-m-d H:i:s', time() + 60*60); // 1 hour

            // remove existing tokens for this user
            try {
                $del = $conn->prepare('DELETE FROM password_resets WHERE student_id = ?');
                $del->execute([$studentId]);

                $ins = $conn->prepare('INSERT INTO password_resets (student_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())');
                $ins->execute([$studentId, $hashedToken, $expires_at]);
            } catch (PDOException $ex) {
                $err = 'A database error occurred while creating reset token. Please try again later.';
                // Optionally log $ex->getMessage()
                $row = false; // treat as failure
            }

            if ($row !== false) {
                // build reset link
                $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}";

                // --- START: improved path detection to include subfolder (works on localhost and hosted) ---
                // Determine script directory (e.g. '/TEACHERSEV4' or '/' for root)
                $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $pathPrefix = ($scriptDir && $scriptDir !== '/') ? $scriptDir : '';
                // $pathPrefix already starts with a leading '/' if non-empty
                $reset_link = $base . $pathPrefix . '/student_reset.php?uid=' . urlencode($studentId) . '&token=' . urlencode($plainToken);
                // --- END: improved path detection ---

                // audit
                try {
                    audit($conn, 'student', $studentId, 'password_reset_request', 'requested password reset');
                } catch (Throwable $ae) {
                    // ignore audit errors for user flow
                }

                // Attempt to send email if student has email and SMTP is configured
                $mail_sent = false;
                if ($emailAvailable && $hasComposer && smtp_configured()) {
                    // Use PHPMailer
                    try {
                        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                        // SMTP settings (expected to be defined in config/functions)
                        $mail->isSMTP();
                        $mail->Host       = $mail_host;            // e.g. smtp.gmail.com
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $mail_username;
                        $mail->Password   = $mail_password;
                        // Determine secure type based on port (common defaults)
                        if (!empty($mail_smtp_secure)) {
                            // allow explicit override
                            $smtpSecure = $mail_smtp_secure;
                        } else {
                            $smtpSecure = ($mail_port == 465) ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        }
                        $mail->SMTPSecure = $smtpSecure;
                        $mail->Port       = $mail_port;            // 587 for TLS, 465 for SSL

                        // From / To
                        $mail->setFrom($mail_from, $mail_from_name);
                        $mail->addAddress($row['email'], $fullname);

                        // Content
                        $mail->isHTML(false);
                        $mail->Subject = 'TeacherEval — Password reset request';
                        $body = "Hi {$fullname},\n\nWe received a password reset request for your account.\n\n";
                        $body .= "Click the link below to reset your password (valid 1 hour):\n\n{$reset_link}\n\n";
                        $body .= "If you didn't request this, you can ignore this message.\n\nRegards,\nTeacherEval Admin";
                        $mail->Body = $body;

                        // send
                        $mail->send();
                        $mail_sent = true;
                    } catch (Throwable $e) {
                        // Sending failed (SMTP/auth/network). Optionally log $e->getMessage()
                        $mail_sent = false;
                    }
                }

                // Prepare user-visible message
                if ($emailAvailable && $mail_sent) {
                    $success = 'If an account with that School ID / email exists, a password reset link was sent to the registered email.';
                } elseif ($emailAvailable && !$mail_sent) {
                    $success = 'Password reset link generated. (Mail sending failed in this environment.)';
                    if ($dev_show_link) {
                        $success .= "<br><small>Reset link (dev only): <a href=\"" . htmlspecialchars($reset_link) . "\">" . htmlspecialchars($reset_link) . "</a></small>";
                    } else {
                        $success .= ' Please contact your administrator for assistance.';
                    }
                } else {
                    // No email available for this student
                    $success = 'A password reset was generated for that account.';
                    if ($dev_show_link) {
                        $success .= "<br><small>Reset link (dev only): <a href=\"" . htmlspecialchars($reset_link) . "\">" . htmlspecialchars($reset_link) . "</a></small>";
                    } else {
                        $success .= ' Please contact your administrator to reset your password.';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Forgot password — TeacherEval</title>
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
      <h4 class="mb-1">Forgot password</h4>
      <p class="text-muted mb-3">Enter your School ID or registered email. We'll send a link to reset your password.</p>

      <?php if ($err): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
      <?php endif; ?>

      <form method="post" class="mb-3" novalidate>
        <div class="mb-3">
          <label class="form-label">School ID or Email</label>
          <input name="identifier" class="form-control" value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-envelope"></i> Send reset link</button>
      </form>

      <div class="mt-3 small text-center">
        <a href="login.php">Back to login</a>
      </div>
    </div>
  </div>
</body>
</html>
