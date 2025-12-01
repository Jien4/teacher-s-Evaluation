<?php
// manage_users.php (FULL - updated)
// - Start session before any session usage / require_admin
// - Robust CSRF handling
// - Small centered Add Admin modal
// - Single reusable Edit Admin modal populated via JS
// - CSS tweaks to keep modal layout stable
// - JS fallback to load bootstrap bundle and open modals programmatically

if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'functions.php';
require_admin(); // ensure this runs after session_start()

// ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

// POST handlers (add / edit / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $posted_csrf)) {
        $_SESSION['flash'] = ['message' => 'Invalid CSRF token', 'success' => false];
        header('Location: manage_users.php'); exit;
    }

    try {
        $act = $_POST['action'] ?? '';

        if ($act === 'add') {
            $fullname = trim((string)($_POST['fullname'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            if ($fullname === '' || $username === '') throw new Exception('Fullname and username required.');

            // default password = "password"
            $pwHash = password_hash('password', PASSWORD_DEFAULT);

            $stmt = $conn->prepare('INSERT INTO admins (fullname, username, password_hash, created_at) VALUES (:f, :u, :p, NOW())');
            $stmt->execute([':f'=>$fullname, ':u'=>$username, ':p'=>$pwHash]);

            // audit
            $log = $conn->prepare('INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES (\'admin\',:uid,\'add_admin\',:det,:ip,NOW())');
            $log->execute([':uid'=>$_SESSION['user_id'] ?? null, ':det'=>"Added admin {$username}", ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message'=>'Admin added (default password: password)','success'=>true];
            header('Location: manage_users.php'); exit;
        }

        if ($act === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $fullname = trim((string)($_POST['fullname'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            if ($id <= 0 || $fullname === '' || $username === '') throw new Exception('Invalid input.');

            $stmt = $conn->prepare('UPDATE admins SET fullname=:f, username=:u WHERE id=:id');
            $stmt->execute([':f'=>$fullname, ':u'=>$username, ':id'=>$id]);

            $log = $conn->prepare('INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES (\'admin\',:uid,\'edit_admin\',:det,:ip,NOW())');
            $log->execute([':uid'=>$_SESSION['user_id'] ?? null, ':det'=>"Edited admin #{$id}", ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message'=>'Admin updated','success'=>true];
            header('Location: manage_users.php'); exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid ID.');
            // prevent deleting currently logged-in admin
            if (!empty($_SESSION['user_id']) && $id === (int)$_SESSION['user_id']) {
                throw new Exception('Cannot delete the currently logged-in admin.');
            }

            $stmt = $conn->prepare('DELETE FROM admins WHERE id=:id');
            $stmt->execute([':id'=>$id]);

            $log = $conn->prepare('INSERT INTO audit_logs (user_type,user_id,action,details,ip,created_at) VALUES (\'admin\',:uid,\'delete_admin\',:det,:ip,NOW())');
            $log->execute([':uid'=>$_SESSION['user_id'] ?? null, ':det'=>"Deleted admin #{$id}", ':ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

            $_SESSION['flash'] = ['message'=>'Admin deleted','success'=>true];
            header('Location: manage_users.php'); exit;
        }

    } catch (Exception $e) {
        $_SESSION['flash'] = ['message'=>$e->getMessage(), 'success'=>false];
        header('Location: manage_users.php'); exit;
    }
}

// fetch admins list
$admins = $conn->query('SELECT id, fullname, username, created_at FROM admins ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Manage Admins";
$active = "users";

ob_start();
?>

<div class="card p-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Manage Admins</h5>
    <button id="addUserBtn" type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
      <i class="bi bi-plus-lg"></i> Add Admin
    </button>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert <?php echo ($_SESSION['flash']['success'] ?? false) ? 'alert-success' : 'alert-danger'; ?>">
      <?php echo htmlspecialchars($_SESSION['flash']['message'] ?? ''); ?>
    </div>
    <?php unset($_SESSION['flash']); endif; ?>

  <div class="table-responsive">
  <table class="table table-hover">
    <thead><tr><th>Fullname</th><th>Username</th><th>Added</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($admins)): ?>
        <tr><td colspan="4" class="text-center py-3">No admins found.</td></tr>
      <?php else: foreach ($admins as $u): ?>
        <tr>
          <td><?php echo htmlspecialchars($u['fullname']); ?></td>
          <td><?php echo htmlspecialchars($u['username']); ?></td>
          <td><?php echo htmlspecialchars($u['created_at']); ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary editUserBtn"
                    type="button"
                    data-id="<?php echo (int)$u['id']; ?>"
                    data-fullname="<?php echo htmlspecialchars($u['fullname'], ENT_QUOTES); ?>"
                    data-username="<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>">
              Edit
            </button>

            <form method="post" action="manage_users.php" style="display:inline" onsubmit="return confirm('Delete admin?');">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Add Admin Modal (small, centered) -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
    <form method="post" action="manage_users.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Admin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">

        <div class="mb-3">
          <label for="add-fullname" class="form-label">Fullname</label>
          <input id="add-fullname" name="fullname" class="form-control" required autocomplete="off">
        </div>

        <div class="mb-3">
          <label for="add-username" class="form-label">Username</label>
          <input id="add-username" name="username" class="form-control" required autocomplete="off">
        </div>

        <div class="small text-muted">Default password is <strong>password</strong>. Admins should change it on first login.</div>
      </div>

      <div class="modal-footer justify-content-end">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>
  </div>
</div>

<!-- Reusable Edit Admin Modal (populated via JS) -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <form id="editUserForm" method="post" action="manage_users.php" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Admin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="edit">
        <input id="edit-id" type="hidden" name="id" value="">

        <div class="mb-3">
          <label for="edit-fullname" class="form-label">Fullname</label>
          <input id="edit-fullname" name="fullname" class="form-control" required autocomplete="off">
        </div>

        <div class="mb-3">
          <label for="edit-username" class="form-label">Username</label>
          <input id="edit-username" name="username" class="form-control" required autocomplete="off">
        </div>
      </div>

      <div class="modal-footer justify-content-end">
        <button id="edit-cancel" type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
?>

<!-- Inline CSS tweaks -->
<style>
.modal .form-label { display:block; margin-bottom:.35rem; }
.modal .form-control { width:100% !important; box-sizing:border-box; }
.modal .modal-footer { gap:.5rem; }
.modal .modal-footer .btn { min-width:80px; }
.modal .modal-content * { position: relative; }
.modal .modal-body { padding-top:.75rem; padding-bottom:.75rem; }
</style>

<!-- JS: populate edit modal & ensure bootstrap present -->
<script>
(function(){
  function ensureBootstrapLoaded(cb) {
    if (typeof window.bootstrap !== 'undefined') return cb();
    if (document.getElementById('bootstrap-bundle-cdn')) {
      var check = setInterval(function(){ if (typeof window.bootstrap !== 'undefined') { clearInterval(check); cb(); } }, 50);
      return;
    }
    var s = document.createElement('script');
    s.id = 'bootstrap-bundle-cdn';
    s.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js';
    s.crossOrigin = 'anonymous';
    s.onload = function(){ cb(); };
    s.onerror = function(){ cb(); };
    document.body.appendChild(s);
  }

  function initEditButtons() {
    var editButtons = document.querySelectorAll('.editUserBtn');
    if (!editButtons) return;
    editButtons.forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-id');
        var fullname = btn.getAttribute('data-fullname') || '';
        var username = btn.getAttribute('data-username') || '';

        document.getElementById('edit-id').value = id;
        document.getElementById('edit-fullname').value = fullname;
        document.getElementById('edit-username').value = username;

        try {
          if (typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Modal) {
            var modalEl = document.getElementById('editUserModal');
            var inst = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            inst.show();
          }
        } catch (e) {
          console && console.warn && console.warn('Edit modal show failed', e);
        }
      });
    });
  }

  ensureBootstrapLoaded(function(){ initEditButtons(); });

  // If you dynamically add admins later, call initEditButtons() again.
})();
</script>
