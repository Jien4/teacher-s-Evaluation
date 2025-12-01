<?php
// admin_dashboard.php
require_once 'functions.php';
require_admin();
if (session_status() === PHP_SESSION_NONE) session_start();

// --- existing queries (you already had these)
$summary = $conn->query('SELECT t.id,t.name,t.course, COUNT(DISTINCT e.id) as eval_count, AVG(a.rating) as avg_rating
  FROM teachers t
  LEFT JOIN evaluations e ON t.id=e.teacher_id
  LEFT JOIN evaluation_answers a ON e.id=a.evaluation_id
  GROUP BY t.id ORDER BY t.name')->fetchAll(PDO::FETCH_ASSOC);

$recent = $conn->query('SELECT e.id as eval_id, t.name as teacher_name, s.fullname, s.school_id, s.course, e.submitted_at 
  FROM evaluations e 
  JOIN students s ON e.student_id=s.id 
  JOIN teachers t ON e.teacher_id=t.id 
  ORDER BY e.submitted_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);

$audits = $conn->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);

// chart data
$chartLabels = [];
$chartData = [];
foreach ($summary as $s_item) {
    $chartLabels[] = $s_item['name'];
    $chartData[] = isset($s_item['avg_rating']) ? (float)$s_item['avg_rating'] : 0;
}

// csrf
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$page_title = "Admin Dashboard";
$active = "dashboard";

ob_start();
?>

<!-- Top action area: Flowchart-like actions as cards -->
<div class="mb-4">
  <h4 class="mb-3">Quick Actions</h4>

  <div class="row g-3">
    <!-- Manage Users -->
    <div class="col-md-4">
      <a href="manage_users.php" class="text-decoration-none">
        <div class="card p-3 card-action h-100">
          <div class="d-flex align-items-start gap-3">
            <div class="fs-3 text-primary"><i class="bi bi-people-fill"></i></div>
            <div>
              <h6 class="mb-1">Manage Users</h6>
              <div class="small-muted">Add / Edit / Delete user accounts and set roles.</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <!-- Manage Courses -->
    <div class="col-md-4">
      <a href="manage_courses.php" class="text-decoration-none">
        <div class="card p-3 card-action h-100">
          <div class="d-flex align-items-start gap-3">
            <div class="fs-3 text-success"><i class="bi bi-journal-bookmark"></i></div>
            <div>
              <h6 class="mb-1">Manage Courses</h6>
              <div class="small-muted">Add or update course entries & assign teachers.</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <!-- Set Evaluation Period -->
    <div class="col-md-4">
      <a href="evaluation_period.php" class="text-decoration-none">
        <div class="card p-3 card-action h-100">
          <div class="d-flex align-items-start gap-3">
            <div class="fs-3 text-warning"><i class="bi bi-calendar2-event"></i></div>
            <div>
              <h6 class="mb-1">Set Evaluation Period</h6>
              <div class="small-muted">Define start and end dates for evaluation windows.</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <!-- Manage Questions -->
    <div class="col-md-4">
      <a href="manage_questions.php" class="text-decoration-none">
        <div class="card p-3 card-action h-100">
          <div class="d-flex align-items-start gap-3">
            <div class="fs-3 text-info"><i class="bi bi-question-circle"></i></div>
            <div>
              <h6 class="mb-1">Manage Questions</h6>
              <div class="small-muted">Add, edit or delete evaluation questionnaire items.</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <!-- Monitor Submissions -->
    <div class="col-md-4">
      <a href="monitor_submissions.php" class="text-decoration-none">
        <div class="card p-3 card-action h-100">
          <div class="d-flex align-items-start gap-3">
            <div class="fs-3 text-secondary"><i class="bi bi-check2-square"></i></div>
            <div>
              <h6 class="mb-1">Monitor Submissions</h6>
              <div class="small-muted">Track who has submitted and completion rates.</div>
            </div>
          </div>
        </div>
      </a>
    </div>

    <!-- Generate Reports -->
    <div class="col-md-4">
      <a href="evaluation_report.php" class="text-decoration-none">
        <div class="card p-3 card-action h-100">
          <div class="d-flex align-items-start gap-3">
            <div class="fs-3 text-dark"><i class="bi bi-graph-up"></i></div>
            <div>
              <h6 class="mb-1">Generate Reports</h6>
              <div class="small-muted">View, filter and print evaluation reports.</div>
            </div>
          </div>
        </div>
      </a>
    </div>
  </div>
</div>

<!-- Separator -->
<hr>

<!-- Existing dashboard content (summary chart, teacher list, recent evaluations) -->
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h5 class="mb-0">Teacher Summary</h5>
          <div class="small text-muted">Overview of evaluations and average ratings</div>
        </div>
        <div class="text-end">
          <div class="small text-muted">Total Teachers</div>
          <div class="h4 mb-0"><?php echo count($summary); ?></div>
        </div>
      </div>

      <div style="height:260px;">
        <canvas id="summaryChart" style="max-height:260px;"></canvas>
      </div>

      <div class="table-wrap mt-3">
        <table class="table table-hover align-middle">
          <thead>
            <tr><th>Teacher</th><th>Course</th><th class="text-center">Evaluations</th><th class="text-center">Avg Rating</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (empty($summary)): ?>
              <tr><td colspan="5" class="text-center py-4">No teachers found.</td></tr>
            <?php else: foreach($summary as $s): ?>
              <tr>
                <td><?php echo htmlspecialchars($s['name']); ?></td>
                <td class="small text-muted"><?php echo htmlspecialchars($s['course']); ?></td>
                <td class="text-center"><?php echo (int)$s['eval_count']; ?></td>
                <td class="text-center"><?php echo ($s['avg_rating'] !== null) ? number_format((float)$s['avg_rating'], 2) : '<span class="small text-muted">N/A</span>'; ?></td>
                <td><a class="btn btn-sm btn-primary" href="teacher_report.php?id=<?php echo urlencode($s['id']); ?>"><i class="bi bi-file-earmark-text-fill"></i> Report</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card p-3 mb-3">
      <h6 class="mb-2">Recent Evaluations</h6>
      <div class="table-wrap">
        <table class="table table-sm table-hover">
          <thead><tr><th>Evaluator</th><th>Teacher</th><th>When</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($recent)): ?>
              <tr><td colspan="4" class="text-center py-3">No recent evaluations.</td></tr>
            <?php else: foreach($recent as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['fullname']); ?><br><small class="text-muted"><?php echo htmlspecialchars($r['school_id']); ?></small></td>
                <td><?php echo htmlspecialchars($r['teacher_name']); ?></td>
                <td class="text-muted"><?php echo htmlspecialchars($r['submitted_at']); ?></td>
                <td><a href="admin_view_evaluation.php?id=<?php echo urlencode($r['eval_id']); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card p-3">
      <h6 class="mb-2">Audit Logs</h6>
      <div class="table-wrap small">
        <table class="table table-sm">
          <thead><tr><th>Time</th><th>User</th><th>Action</th></tr></thead>
          <tbody>
            <?php if (empty($audits)): ?>
              <tr><td colspan="3" class="text-center py-3">No audit logs.</td></tr>
            <?php else: foreach($audits as $a): ?>
              <tr>
                <td><?php echo htmlspecialchars($a['created_at']); ?></td>
                <td><?php echo htmlspecialchars($a['user_type']) . ($a['user_id'] ? ' #'.htmlspecialchars($a['user_id']) : ''); ?></td>
                <td><?php echo htmlspecialchars($a['action']); ?><br><small class="text-muted"><?php echo htmlspecialchars($a['details']); ?></small></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Teacher Modal (kept for convenience) -->
<div class="modal fade" id="addTeacherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form action="add_teacher.php" method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Teacher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <div class="mb-3">
          <label class="form-label">Teacher Name</label>
          <input name="name" type="text" class="form-control" required maxlength="255">
        </div>
        <div class="mb-3">
          <label class="form-label">Course</label>
          <input name="course" type="text" class="form-control" required maxlength="150">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const labels = <?php echo json_encode($chartLabels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
  const data = <?php echo json_encode($chartData, JSON_NUMERIC_CHECK); ?>;

  const ctx = document.getElementById('summaryChart');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Average Rating',
        data: data,
        backgroundColor: 'rgba(13,110,253,0.85)',
        borderRadius: 6,
        borderSkipped: false
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } }, x: { grid: { display: false } } }
    }
  });
</script>

<?php
$content = ob_get_clean();
include 'admin_layout.php';
