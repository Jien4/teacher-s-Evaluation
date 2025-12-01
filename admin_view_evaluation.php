<?php
require_once 'functions.php';
require_admin();

$id = intval($_GET['id'] ?? 0);

// Fetch evaluation with student & teacher info
$stmt = $conn->prepare('
    SELECT e.*, s.fullname, s.school_id, s.course, t.name as teacher_name 
    FROM evaluations e 
    JOIN students s ON e.student_id = s.id 
    JOIN teachers t ON e.teacher_id = t.id 
    WHERE e.id = ?');
$stmt->execute([$id]);
$ev = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch answers
$answers = [];
if($ev){
    $a = $conn->prepare('
        SELECT q.question_text, ans.rating 
        FROM evaluation_answers ans 
        JOIN evaluation_questions q ON ans.question_id = q.id 
        WHERE ans.evaluation_id = ?');
    $a->execute([$id]);
    $answers = $a->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>View Evaluation</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    body{
        background: #f5f7fb;
        font-family: "Inter", sans-serif;
    }
    .page-container{
        max-width: 900px;
        margin-top: 40px;
    }
    .card-main{
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border: none;
    }
    .title{
        font-weight: 700;
        color: #0d6efd;
    }
    .info-box{
        background: #fff;
        border-radius: 10px;
        padding: 15px 20px;
        margin-bottom: 20px;
        border-left: 4px solid #0d6efd;
    }
    .table thead th{
        background: #e9f1ff;
        font-weight: 600;
    }
    .btn-back{
        border-radius: 8px;
        padding: 8px 20px;
    }
</style>

</head>
<body class="p-3">

<div class="container page-container">

<?php if(!$ev){ echo '<div class="alert alert-warning">Evaluation not found.</div>'; exit; } ?>

<div class="card card-main p-4">

    <h3 class="title mb-3">Evaluation for <?php echo htmlspecialchars($ev['teacher_name']); ?></h3>

    <div class="info-box">
        <p class="mb-1">
            <strong>Evaluator:</strong> 
            <?php echo htmlspecialchars($ev['fullname']); ?> 
            (<?php echo htmlspecialchars($ev['school_id']); ?>)
        </p>
        <p class="mb-1"><strong>Course:</strong> <?php echo htmlspecialchars($ev['course']); ?></p>
        <p class="mb-1">
            <strong>Submitted:</strong>
            <?php echo date('F j, Y • H:i', strtotime($ev['submitted_at'])); ?>
        </p>
    </div>

    <?php if($ev['comment']): ?>
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <strong class="d-block mb-2">Student Comment:</strong>
            <div style="white-space: pre-wrap; font-size: 15px;">
                <?php echo nl2br(htmlspecialchars($ev['comment'])); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-responsive mb-4">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th style="width:70%">Question</th>
                    <th style="width:30%" class="text-center">Rating</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($answers as $an): ?>
                <tr>
                    <td><?php echo htmlspecialchars($an['question_text']); ?></td>
                    <td class="text-center fw-bold"><?php echo intval($an['rating']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <a href="admin_dashboard_full.php" class="btn btn-secondary btn-back">
        ← Back
    </a>

</div>

</div>

</body>
</html>
