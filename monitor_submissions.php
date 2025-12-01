<?php
// monitor_submissions.php
require_once 'functions.php';
require_admin();

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = "Monitor Submissions";
$active = "monitor";

/*
    We show:
    ✔ LIST OF TEACHERS
    ✔ For each course:
         - Students who already submitted (joined evaluations)
         - Students who have not yet submitted (no evaluation found)
*/

// Fetch all teachers grouped by course
$teachers = $conn->query("
    SELECT id, name, course 
    FROM teachers 
    ORDER BY course, name
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all students grouped by course
$students = $conn->query("
    SELECT id, fullname, school_id, course
    FROM students
    ORDER BY course, fullname
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all submissions (evaluations)
$submissions = $conn->query("
    SELECT e.id, e.student_id, e.teacher_id
    FROM evaluations e
")->fetchAll(PDO::FETCH_ASSOC);

// Map submissions for fast lookup
$submitted = [];
foreach ($submissions as $s) {
    $submitted[$s['student_id']][$s['teacher_id']] = true;
}

ob_start();
?>

<div class="card p-3">
    <h5 class="mb-3">Monitor Submissions</h5>

    <p class="text-muted mb-3">
        This page shows which students have <strong>submitted</strong> or <strong>not yet submitted</strong> evaluations for each teacher.
    </p>

    <?php
    // Group students by course
    $studentsByCourse = [];
    foreach ($students as $s) {
        $studentsByCourse[$s['course']][] = $s;
    }
    ?>

    <?php foreach ($teachers as $t): ?>
        <div class="border rounded p-3 mb-4 bg-light">
            <h6 class="mb-1">
                <strong><?php echo htmlspecialchars($t['name']); ?></strong>
            </h6>
            <div class="text-muted mb-2">Course: <?php echo htmlspecialchars($t['course']); ?></div>

            <?php
            // Students for this teacher’s course
            $studList = $studentsByCourse[$t['course']] ?? [];

            $submittedList = [];
            $notSubmitted = [];

            foreach ($studList as $stud) {
                $has = isset($submitted[$stud['id']][$t['id']]);
                if ($has) $submittedList[] = $stud;
                else $notSubmitted[] = $stud;
            }
            ?>

            <div class="row">
                <!-- Submitted -->
                <div class="col-md-6">
                    <div class="card mb-2">
                        <div class="card-header bg-success text-white py-1">
                            Submitted (<?php echo count($submittedList); ?>)
                        </div>
                        <div class="card-body p-2" style="max-height: 200px; overflow-y:auto;">
                            <?php if (empty($submittedList)): ?>
                                <div class="text-muted small">No submissions yet.</div>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                <?php foreach ($submittedList as $s): ?>
                                    <li class="list-group-item py-1 small">
                                        <?php echo htmlspecialchars($s['fullname']); ?>
                                        <span class="text-muted">(<?php echo htmlspecialchars($s['school_id']); ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Not Submitted -->
                <div class="col-md-6">
                    <div class="card mb-2">
                        <div class="card-header bg-danger text-white py-1">
                            Not Submitted (<?php echo count($notSubmitted); ?>)
                        </div>
                        <div class="card-body p-2" style="max-height: 200px; overflow-y:auto;">
                            <?php if (empty($notSubmitted)): ?>
                                <div class="text-success small">All students submitted.</div>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                <?php foreach ($notSubmitted as $s): ?>
                                    <li class="list-group-item py-1 small">
                                        <?php echo htmlspecialchars($s['fullname']); ?>
                                        <span class="text-muted">(<?php echo htmlspecialchars($s['school_id']); ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
