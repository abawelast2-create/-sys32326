<?php
// =============================================================
// admin/attendance.php - تقارير الحضور والانصراف
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

// =================== حذف سجل ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
        exit;
    }
    $delId = (int)$_POST['delete_id'];
    if ($delId > 0) {
        $st = db()->prepare("DELETE FROM attendances WHERE id = ?");
        $st->execute([$delId]);
        auditLog('delete_attendance', "حذف سجل حضور ID={$delId}", $delId);
        echo json_encode(['success' => true, 'deleted' => $st->rowCount()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'معرف غير صالح']);
    }
    exit;
}

// =================== حفظ/تحديث سجل ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    header('Content-Type: application/json');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
        exit;
    }
    
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
    $empId = (int)($_POST['employee_id'] ?? 0);
    $type = $_POST['type'] ?? 'in';
    $dateTime = $_POST['datetime'] ?? date('Y-m-d H:i');
    $lateMinutes = (int)($_POST['late_minutes'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // التحقق من البيانات
    if ($empId <= 0 || !in_array($type, ['in', 'out'])) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة']);
        exit;
    }
    
    // التحقق من صحة التاريخ والوقت
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dateTime)) {
        echo json_encode(['success' => false, 'message' => 'صيغة التاريخ/الوقت غير صحيحة']);
        exit;
    }
    
    try {
        $attendanceDate = substr($dateTime, 0, 10);
        
        if ($id > 0) {
            // تحديث سجل موجود
            $stmt = db()->prepare("
                UPDATE attendances 
                SET timestamp = ?, attendance_date = ?, late_minutes = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$dateTime, $attendanceDate, $lateMinutes, $notes, $id]);
            auditLog('edit_attendance', "تحديث سجل حضور ID={$id} — نوع={$type}, وقت={$dateTime}", $id);
            echo json_encode(['success' => true, 'message' => 'تم التحديث بنجاح', 'id' => $id]);
        } else {
            // إدراج سجل جديد
            $stmt = db()->prepare("
                INSERT INTO attendances (employee_id, type, timestamp, attendance_date, late_minutes, latitude, longitude, notes)
                VALUES (?, ?, ?, ?, ?, 0, 0, ?)
            ");
            $stmt->execute([$empId, $type, $dateTime, $attendanceDate, $lateMinutes, $notes]);
            $newId = (int)db()->lastInsertId();
            auditLog('add_attendance', "إضافة سجل حضور جديد — موظف={$empId}, نوع={$type}, وقت={$dateTime}", $newId);
            echo json_encode(['success' => true, 'message' => 'تم الإضافة بنجاح', 'id' => $newId]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
    }
    exit;
}

// =================== جلب بيانات السجل للتحرير ===================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_attendance') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'معرف غير صالح']);
        exit;
    }
    
    $stmt = db()->prepare("
        SELECT id, employee_id, type, timestamp, late_minutes, notes
        FROM attendances
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    
    if (!$record) {
        echo json_encode(['success' => false, 'message' => 'السجل غير موجود']);
        exit;
    }
    
    // تحويل timestamp إلى صيغة HTML datetime input
    $dateTime = date('Y-m-d H:i', strtotime($record['timestamp']));
    
    echo json_encode([
        'success' => true,
        'id' => $record['id'],
        'employee_id' => $record['employee_id'],
        'type' => $record['type'],
        'datetime' => $dateTime,
        'late_minutes' => $record['late_minutes'],
        'notes' => $record['notes']
    ]);
    exit;
}

$pageTitle  = 'تقارير الحضور';
$activePage = 'attendance';

// =================== فلاتر ===================
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
// التحقق من صحة التواريخ وتبديلها إن كان البداية بعد النهاية
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp;
}
$empId    = (int)($_GET['emp_id'] ?? 0);
$type     = $_GET['type'] ?? '';
$filterBranch = (int)($_GET['branch'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;

// بناء الاستعلام مع الفلاتر
$where  = ["a.attendance_date BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if ($empId > 0) { $where[] = "a.employee_id = ?"; $params[] = $empId; }
if (in_array($type, ['in','out'])) { $where[] = "a.type = ?"; $params[] = $type; }
if ($filterBranch > 0) { $where[] = "e.branch_id = ?"; $params[] = $filterBranch; }

$whereStr = implode(' AND ', $where);

// العدد الكلي
$totalStmt = db()->prepare("SELECT COUNT(*) FROM attendances a JOIN employees e ON a.employee_id=e.id WHERE $whereStr");
$totalStmt->execute($params);
$total      = (int)$totalStmt->fetchColumn();
$totalPages = (int)ceil($total / $perPage);

// النتائج
$recStmt = db()->prepare("
    SELECT a.*, e.name AS employee_name, e.job_title, b.name AS branch_name
    FROM attendances a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    WHERE $whereStr
    ORDER BY a.timestamp DESC
    LIMIT ? OFFSET ?
");
$recStmt->execute(array_merge($params, [$perPage, $offset]));
$records = $recStmt->fetchAll();

// قائمة الموظفين للفلتر
$empList = db()->query("SELECT id, name, job_title, pin FROM employees WHERE is_active=1 AND deleted_at IS NULL ORDER BY name")->fetchAll();

// قائمة الفروع للفلتر
$branchList = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// إحصائيات الفترة
$statsStmt = db()->prepare("
    SELECT
        COUNT(CASE WHEN type='in' THEN 1 END)  AS total_in,
        COUNT(CASE WHEN type='out' THEN 1 END) AS total_out,
        COUNT(DISTINCT employee_id)             AS unique_employees,
        COUNT(DISTINCT attendance_date)          AS working_days
    FROM attendances a WHERE $whereStr
");
$statsStmt->execute($params);
$periodStats = $statsStmt->fetch();

// تصدير CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // التحقق من صحة نطاق التواريخ
    $fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom);
    $toDate = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$fromDate || !$toDate) {
        die('تواريخ غير صالحة');
    }
    if ($fromDate > $toDate) {
        $tmp = $dateFrom; $dateFrom = $dateTo; $dateTo = $tmp;
    }
    $daysDiff = $toDate->diff($fromDate)->days;
    if ($daysDiff > 365) {
        die('لا يمكن تصدير أكثر من سنة واحدة');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_' . $dateFrom . '_' . $dateTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    fputcsv($out, ['#', 'الاسم', 'الوظيفة', 'الفرع', 'النوع', 'التاريخ', 'الوقت', 'خط العرض', 'خط الطول', 'الدقة (م)']);
    $allStmt = db()->prepare("SELECT a.*, e.name AS employee_name, e.job_title, b.name AS branch_name FROM attendances a JOIN employees e ON a.employee_id=e.id LEFT JOIN branches b ON e.branch_id=b.id WHERE $whereStr ORDER BY a.timestamp DESC");
    $allStmt->execute($params);
    $i = 1;
    // دالة لمنع CSV Formula Injection (حماية من هجمات Excel)
    $csvSafe = function($val) {
        $val = (string)$val;
        if ($val !== '' && in_array($val[0], ['=', '+', '-', '@', '|', '%'], true)) {
            $val = "'" . $val; // إضافة apostrophe لمنع تفسير الخلية كمعادلة
        }
        return $val;
    };
    while ($row = $allStmt->fetch()) {
        fputcsv($out, [
            $i++,
            $csvSafe($row['employee_name']),
            $csvSafe($row['job_title']),
            $csvSafe($row['branch_name'] ?? '-'),
            $row['type'] === 'in' ? 'دخول' : 'انصراف',
            date('Y-m-d', strtotime($row['timestamp'])),
            date('H:i:s', strtotime($row['timestamp'])),
            $row['latitude'],
            $row['longitude'],
            $row['location_accuracy'],
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<!-- فلاتر -->
<div class="card" style="margin-bottom:20px">
    <form method="GET" class="filter-bar" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0">
            <label class="form-label">من تاريخ</label>
            <input class="form-control" type="date" name="date_from" value="<?= $dateFrom ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">إلى تاريخ</label>
            <input class="form-control" type="date" name="date_to" value="<?= $dateTo ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">الموظف</label>
            <select class="form-control" name="emp_id">
                <option value="0">الكل</option>
                <?php foreach ($empList as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $empId == $e['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($e['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">الفرع</label>
            <select class="form-control" name="branch">
                <option value="0">الكل</option>
                <?php foreach ($branchList as $br): ?>
                <option value="<?= $br['id'] ?>" <?= $filterBranch == $br['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($br['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label">النوع</label>
            <select class="form-control" name="type">
                <option value="">الكل</option>
                <option value="in"  <?= $type==='in'  ? 'selected':'' ?>>دخول</option>
                <option value="out" <?= $type==='out' ? 'selected':'' ?>>انصراف</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">بحث</button>
        <a href="?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&emp_id=<?= $empId ?>&type=<?= $type ?>&branch=<?= $filterBranch ?>&export=csv"
           class="btn btn-green">تصدير CSV</a>
        <a href="report-daily.php?date=<?= $dateFrom ?>&branch=<?= $filterBranch ?>"
           target="_blank"
           class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed">
           🖨️ تقرير يومي
        </a>
        <button type="button" class="btn btn-success" onclick="openAddAttendanceModal()" style="margin-right:auto">
           ➕ إضافة حضور
        </button>
    </form>
</div>

<!-- إحصائيات الفترة -->
<div class="stats-grid" style="margin-bottom:20px">
    <div class="stat-card"><div class="stat-icon-wrap orange"><?= svgIcon('attendance', 26) ?></div><div><div class="stat-value" data-live="att_total"><?= $total ?></div><div class="stat-label">إجمالي التسجيلات</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap green"><?= svgIcon('checkin', 26) ?></div><div><div class="stat-value" data-live="att_in"><?= $periodStats['total_in'] ?></div><div class="stat-label">تسجيلات دخول</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap purple"><?= svgIcon('checkout', 26) ?></div><div><div class="stat-value" data-live="att_out"><?= $periodStats['total_out'] ?></div><div class="stat-label">تسجيلات انصراف</div></div></div>
    <div class="stat-card"><div class="stat-icon-wrap blue"><?= svgIcon('employees', 26) ?></div><div><div class="stat-value" data-live="att_emp"><?= $periodStats['unique_employees'] ?></div><div class="stat-label">موظفون فريدون</div></div></div>
</div>

<!-- الجدول -->
<div class="card">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> سجلات الحضور (<span id="totalCount"><?= $total ?></span>)</span>
        <div style="display:flex;align-items:center;gap:10px">
            <div class="live-indicator" id="liveIndicator">
                <span class="live-dot"></span>
                <span id="liveText">مباشر</span>
            </div>
            <span class="badge badge-blue" id="pageBadge">صفحة <?= $page ?> / <?= max(1,$totalPages) ?></span>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="att-table">
        <thead>
            <tr><th>#</th><th>الموظف</th><th>الفرع</th><th>النوع</th><th>التاريخ</th><th>الوقت</th><th>الموقع</th><th>الدقة</th><th>الإجراءات</th></tr>
        </thead>
        <tbody id="attendanceTableBody">
        <?php if (empty($records)): ?>
            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text3)">لا توجد سجلات في هذه الفترة</td></tr>
        <?php else: ?>
            <?php foreach ($records as $i => $rec): ?>
            <tr data-ts="<?= htmlspecialchars($rec['timestamp']) ?>">
                <td style="color:var(--text3)"><?= $offset + $i + 1 ?></td>
                <td><strong><?= htmlspecialchars($rec['employee_name']) ?></strong><br><small style="color:var(--text3)"><?= htmlspecialchars($rec['job_title']) ?></small></td>
                <td style="font-size:.78rem;color:var(--text2)"><?= htmlspecialchars($rec['branch_name'] ?? '-') ?></td>
                <td><span class="badge <?= $rec['type'] === 'in' ? 'badge-green' : 'badge-red' ?>"><?= $rec['type'] === 'in' ? '▶ دخول' : '◀ انصراف' ?></span></td>
                <td style="color:var(--text2)"><?= date('Y-m-d', strtotime($rec['timestamp'])) ?></td>
                <td style="color:var(--primary);font-weight:bold"><?= date('h:i:s A', strtotime($rec['timestamp'])) ?></td>
                <td><a href="https://maps.google.com/?q=<?= $rec['latitude'] ?>,<?= $rec['longitude'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="عرض على الخريطة">الخريطة</a></td>
                <td style="color:var(--text3);font-size:.8rem"><?= round($rec['location_accuracy'] ?? 0) ?> م</td>
                <td style="display:flex;gap:5px">
                    <button class="btn btn-info btn-sm" onclick="editAttendanceRecord(<?= $rec['id'] ?>)" title="تعديل السجل">✎ تعديل</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteRecord(<?= $rec['id'] ?>, this)" title="حذف السجل">🗑️</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:center" id="paginationWrap">
    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
        <?php
            $maxPages = min($totalPages, 10);
            $qs = http_build_query(array_filter([
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'emp_id'    => $empId ?: null,
                'type'      => $type ?: null,
                'branch'    => $filterBranch ?: null,
            ]));
            for ($p = 1; $p <= $maxPages; $p++):
        ?>
            <a href="?<?= $qs ?>&page=<?= $p ?>" class="page-btn<?= $p === $page ? ' active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
        </div>
    <?php endif; ?>
    </div>
</div>

<!-- modal إضافة / تعديل الحضور -->
<div id="attendanceModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;background-attachment:fixed;overflow-y:auto">
    <div style="display:flex;flex-direction:column;gap:20px;align-items:center;justify-content:center;min-height:100vh;padding:20px">
        <div style="background:white;border-radius:8px;padding:30px;max-width:500px;width:100%;box-shadow:0 10px 40px rgba(0,0,0,0.2)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
            <h2 id="modalTitle" style="margin:0">إضافة حضور جديد</h2>
            <button onclick="closeAttendanceModal()" style="background:none;border:none;font-size:24px;cursor:pointer">×</button>
        </div>
        
        <form id="attendanceForm" style="display:flex;flex-direction:column;gap:15px;width:100%">
            <input type="hidden" id="recordId" value="0">
            <input type="hidden" id="csrfToken" value="<?= generateCsrfToken() ?>">
            
            <div style="display:flex;flex-direction:column;gap:5px">
                <label for="empSelect" style="display:block;margin-bottom:5px;font-weight:bold;color:#333">الموظف <span style="color:red">*</span></label>
                <select id="empSelect" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px">
                    <option value="">-- اختر موظف --</option>
                    <?php foreach($empList as $emp): ?>
                    <option value="<?= $emp->id ?>"><?= htmlspecialchars($emp['name']) ?> — <?= htmlspecialchars($emp['job_title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="typeSelect" style="display:block;margin-bottom:5px;font-weight:bold;color:#333">نوع الحضور <span style="color:red">*</span></label>
                <select id="typeSelect" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px">
                    <option value="">-- اختر --</option>
                    <option value="in">دخول (في)</option>
                    <option value="out">خروج (آوت)</option>
                </select>
            </div>
            
            <div>
                <label for="datetimeInput" style="display:block;margin-bottom:5px;font-weight:bold;color:#333">التاريخ والوقت <span style="color:red">*</span></label>
                <input type="datetime-local" id="datetimeInput" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px">
            </div>
            
            <div>
                <label for="lateInput" style="display:block;margin-bottom:5px;font-weight:bold;color:#333">دقائق التأخير</label>
                <input type="number" id="lateInput" min="0" value="0" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px">
            </div>
            
            <div>
                <label for="notesInput" style="display:block;margin-bottom:5px;font-weight:bold;color:#333">ملاحظات</label>
                <textarea id="notesInput" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;resize:vertical;min-height:80px"></textarea>
            </div>
            
            <div id="formError" style="padding:12px;border-radius:4px;color:#d32f2f;background:#ffebee;display:none;border:1px solid #ffcdd2"></div>
            
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">
                <button type="button" onclick="closeAttendanceModal()" class="btn" style="background:#e0e0e0;color:#333;border:none">إلغاء</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>


// =================== التحديث بالوقت الفعلي (جزئي) ===================
const REFRESH_INTERVAL = 20000;
let refreshTimer = null;
let failCount = 0;

const currentFilters = {
    date_from: <?= json_encode($dateFrom) ?>,
    date_to:   <?= json_encode($dateTo) ?>,
    emp_id:    <?= json_encode($empId) ?>,
    type:      <?= json_encode($type) ?>,
    branch:    <?= json_encode($filterBranch) ?>,
    page:      <?= json_encode($page) ?>,
};

// تحديث قيمة إحصائية واحدة فقط
function updateStatValue(key, newValue) {
    const el = document.querySelector(`[data-live="${key}"]`);
    if (!el) return;
    const nv = String(newValue);
    if (el.textContent.trim() !== nv) {
        el.textContent = nv;
        el.classList.add('updated');
        setTimeout(() => el.classList.remove('updated'), 600);
    }
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
}

function setLiveStatus(status) {
    const indicator = document.getElementById('liveIndicator');
    const text = document.getElementById('liveText');
    if (!indicator) return;
    indicator.classList.remove('paused', 'error');
    if (status === 'live') { text.textContent = 'مباشر'; }
    else if (status === 'paused') { indicator.classList.add('paused'); text.textContent = 'متوقف'; }
    else if (status === 'error') { indicator.classList.add('error'); text.textContent = 'خطأ'; }
}

// آخر timestamp لأحدث سجل في الجدول
let lastRecordTs = <?= json_encode(!empty($records) ? $records[0]['timestamp'] : null) ?>;

// إضافة صفوف جديدة أعلى الجدول (صفحة 1 + عرض اليوم فقط)
function prependNewRecords(apiRecords) {
    const today = new Date().toISOString().slice(0,10);
    const isToday = currentFilters.date_from === today && currentFilters.date_to === today;
    const isPage1 = currentFilters.page === 1;
    if (!isToday || !isPage1 || !lastRecordTs) return;

    const tbody = document.getElementById('attendanceTableBody');
    if (!tbody) return;

    const newRecs = apiRecords.filter(r => r.timestamp > lastRecordTs);
    if (newRecs.length === 0) return;

    // إزالة رسالة "لا توجد سجلات" إن وُجدت
    const emptyRow = tbody.querySelector('td[colspan="8"]');
    if (emptyRow) emptyRow.closest('tr').remove();

    newRecs.reverse().forEach(rec => {
        const tr = document.createElement('tr');
        tr.setAttribute('data-ts', rec.timestamp);
        tr.classList.add('new-row');
        const badgeClass = rec.type === 'in' ? 'badge-green' : 'badge-red';
        const badgeText  = rec.type === 'in' ? '▶ دخول' : '◀ انصراف';
        tr.innerHTML = `
            <td style="color:var(--text3)">●</td>
            <td><strong>${escapeHtml(rec.employee_name)}</strong><br><small style="color:var(--text3)">${escapeHtml(rec.job_title)}</small></td>
            <td style="font-size:.78rem;color:var(--text2)">${escapeHtml(rec.branch_name)}</td>
            <td><span class="badge ${badgeClass}">${badgeText}</span></td>
            <td style="color:var(--text2)">${escapeHtml(rec.date)}</td>
            <td style="color:var(--primary);font-weight:bold">${escapeHtml(rec.time)}</td>
            <td><a href="https://maps.google.com/?q=${rec.latitude},${rec.longitude}" target="_blank" class="btn btn-secondary btn-sm" title="عرض على الخريطة">الخريطة</a></td>
            <td style="color:var(--text3);font-size:.8rem">${escapeHtml(rec.accuracy)}</td>
            <td><button class="btn btn-danger btn-sm" onclick="deleteRecord(${rec.id}, this)" title="حذف السجل">🗑️</button></td>`;
        tbody.insertBefore(tr, tbody.firstChild);
    });

    // الاحتفاظ بأحدث 25 فقط
    while (tbody.children.length > 25) {
        tbody.removeChild(tbody.lastChild);
    }

    lastRecordTs = apiRecords[0].timestamp;
}

async function fetchAttendanceStats() {
    try {
        const params = new URLSearchParams({
            date_from: currentFilters.date_from,
            date_to:   currentFilters.date_to,
            emp_id:    currentFilters.emp_id,
            type:      currentFilters.type,
            branch:    currentFilters.branch,
            page:      currentFilters.page,
        });
        const resp = await fetch(`../api/realtime-attendance.php?${params}`, {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        if (!data.success) throw new Error(data.message || 'خطأ');

        // تحديث جزئي: الإحصائيات فقط
        updateStatValue('att_total', data.stats.total);
        updateStatValue('att_in', data.stats.total_in);
        updateStatValue('att_out', data.stats.total_out);
        updateStatValue('att_emp', data.stats.unique_employees);

        // تحديث العدد الكلي في العنوان
        const totalSpan = document.getElementById('totalCount');
        if (totalSpan && totalSpan.textContent !== String(data.pagination.total)) {
            totalSpan.textContent = data.pagination.total;
        }

        // إضافة سجلات جديدة فوق الجدول (اليوم + صفحة 1 فقط)
        prependNewRecords(data.records);

        setLiveStatus('live');
        failCount = 0;
    } catch (e) {
        failCount++;
        console.warn('Real-time fetch failed:', e.message);
        setLiveStatus(failCount >= 3 ? 'error' : 'paused');
    }
}

// =================== حذف سجل ===================
async function deleteRecord(id, btn) {
    if (!confirm('هل أنت متأكد من حذف هذا السجل؟')) return;
    btn.disabled = true;
    btn.textContent = '...';
    try {
        const fd = new FormData();
        fd.append('delete_id', id);
        fd.append('csrf_token', document.getElementById('csrfToken')?.value || '');
        const resp = await fetch('', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await resp.json();
        if (data.success) {
            const row = btn.closest('tr');
            row.style.transition = 'opacity .3s, transform .3s';
            row.style.opacity = '0';
            row.style.transform = 'translateX(30px)';
            setTimeout(() => row.remove(), 300);
            // تحديث العداد
            const tc = document.getElementById('totalCount');
            if (tc) tc.textContent = Math.max(0, parseInt(tc.textContent) - 1);
        } else {
            alert(data.message || 'فشل الحذف');
            btn.disabled = false;
            btn.textContent = '🗑️';
        }
    } catch (e) {
        alert('خطأ: ' + e.message);
        btn.disabled = false;
        btn.textContent = '🗑️';
    }
}

// بدء التحديث الدوري
refreshTimer = setInterval(fetchAttendanceStats, REFRESH_INTERVAL);

// إيقاف عند إخفاء الصفحة
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        clearInterval(refreshTimer);
        setLiveStatus('paused');
    } else {
        fetchAttendanceStats();
        refreshTimer = setInterval(fetchAttendanceStats, REFRESH_INTERVAL);
    }
});

// =================== دوال الـ Modal ===================
function openAddAttendanceModal() {
    document.getElementById('recordId').value = '0';
    document.getElementById('attendanceForm').reset();
    document.getElementById('modalTitle').textContent = 'إضافة حضور جديد';
    document.getElementById('formError').style.display = 'none';
    document.getElementById('empSelect').disabled = false;
    document.getElementById('attendanceModal').style.display = 'block';
}

function closeAttendanceModal() {
    document.getElementById('attendanceModal').style.display = 'none';
    document.getElementById('attendanceForm').reset();
}

async function editAttendanceRecord(recordId) {
    try {
        const res = await fetch(`?action=get_attendance&id=${recordId}`);
        if (!res.ok) throw new Error('فشل في تحميل السجل');
        const data = await res.json();
        
        document.getElementById('recordId').value = data.id;
        document.getElementById('empSelect').value = data.employee_id;
        document.getElementById('typeSelect').value = data.type;
        document.getElementById('datetimeInput').value = data.datetime;
        document.getElementById('lateInput').value = data.late_minutes || '0';
        document.getElementById('notesInput').value = data.notes || '';
        
        document.getElementById('modalTitle').textContent = 'تعديل الحضور';
        document.getElementById('empSelect').disabled = true; // تعطيل تغيير الموظف عند التعديل
        document.getElementById('formError').style.display = 'none';
        document.getElementById('attendanceModal').style.display = 'block';
    } catch (e) {
        alert('خطأ في تحميل السجل: ' + e.message);
    }
}

document.getElementById('attendanceForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const recordId = document.getElementById('recordId').value;
    const empId = document.getElementById('empSelect').value;
    const type = document.getElementById('typeSelect').value;
    const datetime = document.getElementById('datetimeInput').value;
    const lateMinutes = parseInt(document.getElementById('lateInput').value) || 0;
    const notes = document.getElementById('notesInput').value;
    
    const errorDiv = document.getElementById('formError');
    const submitBtn = document.getElementById('submitBtn');
    
    // التحقق من البيانات
    if (!empId || !type || !datetime) {
        errorDiv.textContent = 'يرجى ملء جميع الحقول المطلوبة';
        errorDiv.style.display = 'block';
        return;
    }
    
    try {
        submitBtn.disabled = true;
        submitBtn.textContent = 'جاري الحفظ...';
        
        const formData = new FormData();
        formData.append('action', 'save_attendance');
        formData.append('csrf_token', document.getElementById('csrfToken').value);
        formData.append('id', recordId);
        formData.append('employee_id', empId);
        formData.append('type', type);
        formData.append('datetime', datetime.replace('T', ' '));
        formData.append('late_minutes', lateMinutes);
        formData.append('notes', notes);
        
        const res = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        if (!res.ok) throw new Error('فشل الطلب');
        const data = await res.json();
        
        if (data.success) {
            closeAttendanceModal();
            // تحديث الجدول
            fetchAttendanceStats();
        } else {
            errorDiv.textContent = data.message || 'خطأ غير معروف';
            errorDiv.style.display = 'block';
        }
    } catch (e) {
        errorDiv.textContent = 'خطأ: ' + e.message;
        errorDiv.style.display = 'block';
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'حفظ';
    }
});

// إغلاق الـ Modal عند الضغط خارجها
document.getElementById('attendanceModal').addEventListener('click', (e) => {
    if (e.target.id === 'attendanceModal') {
        closeAttendanceModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>

</div></div>
</body></html>
