<?php
require_once 'config.php';

function audit($conn, $user_type, $user_id, $action, $details=''){
  $ip = current_ip();
  $stmt = $conn->prepare('INSERT INTO audit_logs (user_type, user_id, action, details, ip) VALUES (?,?,?,?,?)');
  $stmt->execute([$user_type, $user_id, $action, $details, $ip]);
}

function is_admin_logged_in(){
  return isset($_SESSION['admin_id']) && $_SESSION['admin_id']>0;
}

function is_student_logged_in(){
  return isset($_SESSION['student_id']) && $_SESSION['student_id']>0;
}

function require_admin(){
  if(!is_admin_logged_in()){
    header('Location: admin_login.php'); exit;
  }
}

function require_student(){
  if(!is_student_logged_in()){
    header('Location: login.php'); exit;
  }
}
?>
