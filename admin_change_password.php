<?php
require_once 'functions.php';
require_admin();

$err = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // fetch current password hash
    $stmt = $conn->prepare('SELECT password_hash FROM admins WHERE id=?');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($current, $admin['password_hash'])) {
        $err = "Current password is incorrect";
    } elseif (strlen($new) < 6) {
        $err = "New password must be at least 6 characters";
    } elseif ($new !== $confirm) {
        $err = "New password and confirm password do not match";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $conn->prepare('UPDATE admins SET password_hash=? WHERE id=?');
        $upd->execute([$hash, $_SESSION['admin_id']]);
        audit($conn,'admin',$_SESSION['admin_id'],'changed_password','');
        $success = "Password updated successfully";
    }
}
?>
<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Change Admin Password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-3">
<div class="container" style="max-width:500px">

<h3>Change Admin Password</h3>

<?php if($err): ?>
<div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
<?php endif; ?>
<?php if($success): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<form method="post">
    <div class="mb-3">
        <label>Current Password</label>
        <input type="password" name="current_password" class="form-control" required>
    </div>
    <div class="mb-3">
        <label>New Password</label>
        <input type="password" name="new_password" class="form-control" required>
    </div>
    <div class="mb-3">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" required>
    </div>
    <button class="btn btn-success w-100" type="submit">Change Password</button>
</form>

<a href="admin_dashboard.php" class="btn btn-secondary mt-2">Back to Dashboard</a>

</div>
</body>
</html>
