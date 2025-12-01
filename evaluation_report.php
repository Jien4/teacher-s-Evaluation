<?php
// evaluation_report.php (fixed, robust report)
require_once 'functions.php';
require_admin();
if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = "Evaluation Report";
$active = "report";

try {
    // teachers for dropdown
    $teachers = $conn->query('SELECT id, name FROM teachers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $teachers = [];
}

// left list: distinct courses from students (safe fallback if class/subject columns missing)
try {
    $leftItems = $conn->query('SELECT DISTINCT IFNULL(course, "") AS course FROM students ORDER BY course')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $leftItems = [];
}

$teacher_id = (int)($_GET['faculty_id'] ?? 0);
$selected_class = trim($_GET['class'] ?? '');
$selected_subject = trim($_GET['subject'] ?? '');

// prepare report if teacher selected
$report = null;
$questionStats = [];
$recentEvaluations = [];
$overallAvg = null;

if ($teacher_id > 0) {
    try {
        // teacher name
        $tstmt = $conn->prepare('SELECT name FROM teachers WHERE id = :id LIMIT 1');
        $tstmt->execute([':id' => $teacher_id]);
        $tname = $tstmt->fetchColumn();
        if (!$tname) $tname = 'Unknown';

        // total distinct students who submitted to this teacher
        $stmt = $conn->prepare('SELECT COUNT(DISTINCT e.student_id) as total FROM evaluations e WHERE e.teacher_id = :tid');
        $stmt->execute([':tid' => $teacher_id]);
        $total = (int)$stmt->fetchColumn();

        // overall average rating (across all answers)
        $avgStmt = $conn->prepare('
            SELECT AVG(a.rating) as avg_rating
            FROM evaluation_answers a
            JOIN evaluations e ON e.id = a.evaluation_id
            WHERE e.teacher_id = :tid
        ');
        $avgStmt->execute([':tid' => $teacher_id]);
        $overallAvg = $avgStmt->fetchColumn();
        $overallAvg = $overallAvg !== null ? round((float)$overallAvg, 2) : null;

        // per-question averages and counts
        $qStmt = $conn->prepare('
            SELECT q.id, q.question_text, COALESCE(AVG(a.rating),0) AS avg_rating, COUNT(a.id) AS responses
            FROM evaluation_questions q
            LEFT JOIN evaluation_answers a ON a.question_id = q.id
            LEFT JOIN evaluations e ON e.id = a.evaluation_id AND e.teacher_id = :tid
            GROUP BY q.id
            ORDER BY COALESCE(q.ordering,0), q.id
        ');
        $qStmt->execute([':tid' => $teacher_id]);
        $questionStats = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        // recent evaluations (with optional filtering by class/subject if those columns exist)
        $recentSql = '
            SELECT e.id AS eval_id, s.fullname AS student_name, s.school_id, e.submitted_at, e.comment
            FROM evaluations e
            JOIN students s ON s.id = e.student_id
            WHERE e.teacher_id = :tid
            ORDER BY e.submitted_at DESC
            LIMIT 50
        ';
        $rStmt = $conn->prepare($recentSql);
        $rStmt->execute([':tid' => $teacher_id]);
        $recentEvaluations = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        $report = ['faculty' => $tname, 'total' => $total];

    } catch (Exception $e) {
        $_SESSION['flash'] = ['message' => 'Error generating report: ' . $e->getMessage(), 'success' => false];
    }
}

ob_start();
?>

<div class="row mb-3">
  <div class="col-12 d-flex align-items-center">
    <form method="get" class="d-flex align-items-center w-100">
      <label class="me-3 mb-0">Select Faculty</label>
      <select name="faculty_id" class="form-select me-3" style="max-width:420px" onchange="this.form.submit()">
        <option value="">-- Select Faculty --</option>
        <?php foreach ($teachers as $t): ?>
          <option value="<?php echo (int)$t['id']; ?>" <?php if ($teacher_id === (int)$t['id']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($t['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="ms-auto">
        <?php if ($teacher_id): ?>
          <a class="btn btn-success" href="#" onclick="window.print(); return false;"><i class="bi bi-printer"></i> Print</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="row">
  <div class="col-lg-3">
    <div class="card p-3">
      <h6>Courses</h6>
      <?php if (empty($leftItems)): ?>
        <div class="small text-muted">No course data.</div>
      <?php else: ?>
        <?php foreach ($leftItems as $it):
            $c = $it['course'] ?? '';
            $link = 'evaluation_report.php?faculty_id=' . $teacher_id . '&class=' . urlencode($c);
        ?>
          <a href="<?php echo $link; ?>" class="d-block py-2 mb-1 border-bottom text-decoration-none"><?php echo htmlspecialchars($c ?: '(Unspecified)'); ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-9">
    <div class="card p-3">
      <h5>Evaluation Report</h5>

      <?php if (!$report): ?>
        <div class="alert alert-info">Select a faculty to view report.</div>
      <?php else: ?>

        <div class="mb-2"><strong>Faculty:</strong> <?php echo htmlspecialchars($report['faculty']); ?></div>
        <div class="mb-2"><strong>Total Students Evaluated:</strong> <?php echo (int)$report['total']; ?></div>
        <div class="mb-2"><strong>Overall Average Rating:</strong>
          <?php echo $overallAvg !== null ? htmlspecialchars(number_format($overallAvg,2)) . ' / 5' : '<span class="text-muted">No ratings yet</span>'; ?>
        </div>

        <hr>

        <h6>Per-question Averages</h6>
        <?php if (empty($questionStats)): ?>
            <div class="small text-muted">No questions or responses found.</div>
        <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Question</th><th style="width:140px" class="text-center">Avg</th><th style="width:120px" class="text-center">Responses</th></tr></thead>
              <tbody>
                <?php foreach ($questionStats as $qs): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($qs['question_text']); ?></td>
                    <td class="text-center"><?php echo $qs['responses'] ? number_format((float)$qs['avg_rating'],2) : '<span class="text-muted">N/A</span>'; ?></td>
                    <td class="text-center"><?php echo (int)$qs['responses']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
        <?php endif; ?>

        <hr>

        <h6>Recent Evaluations</h6>
        <?php if (empty($recentEvaluations)): ?>
          <div class="small text-muted">No evaluations yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>When</th><th>Student</th><th>School ID</th><th>Comment</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($recentEvaluations as $rv): ?>
                  <tr>
                    <td class="small text-muted"><?php echo htmlspecialchars($rv['submitted_at']); ?></td>
                    <td><?php echo htmlspecialchars($rv['student_name']); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars($rv['school_id']); ?></td>
                    <td><?php echo htmlspecialchars($rv['comment']); ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="admin_view_evaluation.php?id=<?php echo (int)$rv['eval_id']; ?>"><i class="bi bi-eye"></i></a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
