<?php
require_once 'functions.php';
require_student();

$teacher_id = intval($_POST['teacher_id'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

$student_id = $_SESSION['student_id'];

// prevent duplicate eval by same student for same teacher (optional)
$chk = $conn->prepare('SELECT id FROM evaluations WHERE student_id=? AND teacher_id=?');
$chk->execute([$student_id,$teacher_id]);
if($chk->fetch()){
  header('Location: student_dashboard.php?msg=already');
  exit;
}

// insert evaluation
$ins = $conn->prepare('INSERT INTO evaluations (student_id, teacher_id, comment) VALUES (?,?,?)');
$ins->execute([$student_id, $teacher_id, $comment]);
$eval_id = $conn->lastInsertId();

// iterate questions from POST
foreach($_POST as $k=>$v){
  if(strpos($k,'q_')===0){
    $qid = intval(substr($k,2));
    $rating = intval($v);
    if($rating < 1 || $rating > 5) $rating = 0;
    $ins2 = $conn->prepare('INSERT INTO evaluation_answers (evaluation_id, question_id, rating) VALUES (?,?,?)');
    $ins2->execute([$eval_id, $qid, $rating]);
  }
}

audit($conn,'student',$student_id,'submitted_evaluation','teacher: '.$teacher_id);
header('Location: student_dashboard.php?submitted=1');
