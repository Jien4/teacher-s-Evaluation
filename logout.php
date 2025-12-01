<?php
require_once 'functions.php';
if(isset($_SESSION['student_id'])) audit($conn,'student',$_SESSION['student_id'],'student_logout','');
if(isset($_SESSION['admin_id'])) audit($conn,'admin',$_SESSION['admin_id'],'admin_logout','');
session_unset(); session_destroy();
header('Location: login.php'); exit;
?>