<?php
// =============================================================
// admin/report-daily.php - تقرير يومي مفصّل للطباعة
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$date         = $_GET['date']   ?? date('Y-m-d');
$filterBranch = (int)($_GET['branch'] ?? 0);

// التحقق من التاريخ
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// =========================================================
// جلب قائمة الفروع للفلتر
// =========================================================
$branchList = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();

// =========================================================
// جلب الموظفين مع سجلات الحضور الخاصة بهم
// =========================================================
$branchWhere = $filterBranch > 0 ? "AND e.branch_id = ?" : "";
$params      = $filterBranch > 0 ? [$date, $date, $filterBranch] : [$date, $date];

$sql = "
    SELECT
        e.id                  AS emp_id,
        e.name                AS emp_name,
        e.job_title,
        b.name                AS branch_name,
        ci.timestamp          AS check_in_ts,
        ci.late_minutes       AS late_min,
        co.timestamp          AS check_out_ts
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    LEFT JOIN (
        SELECT employee_id, MIN(timestamp) AS timestamp, late_minutes
        FROM attendances
        WHERE type = 'in' AND attendance_date = ?
        GROUP BY employee_id
    ) ci ON ci.employee_id = e.id
    LEFT JOIN (
        SELECT employee_id, MAX(timestamp) AS timestamp
        FROM attendances
        WHERE type = 'out' AND attendance_date = ?
        GROUP BY employee_id
    ) co ON co.employee_id = e.id
    WHERE e.is_active = 1 AND e.deleted_at IS NULL
    $branchWhere
    ORDER BY b.name, e.name
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// =========================================================
// احصائيات
// =========================================================
$totalEmp   = count($rows);
$totalIn    = 0;
$totalLate  = 0;
$totalEarly = 0;
$totalAbsent= 0;

foreach ($rows as $r) {
    if ($r['check_in_ts']) {
        $totalIn++;
        if ((int)($r['late_min'] ?? 0) > 0) $totalLate++;
    } else {
        $totalAbsent++;
    }
}

// اسم الفرع المحدد
$selectedBranchName = 'جميع الفروع';
foreach ($branchList as $b) {
    if ($b['id'] == $filterBranch) { $selectedBranchName = $b['name']; break; }
}

// تنسيق التاريخ بالعربية
$dateObj   = new DateTime($date);
$dayNames  = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$dayOfWeek = $dayNames[(int)$dateObj->format('w')];
$dateAr    = $dayOfWeek . '، ' . $dateObj->format('j') . ' / ' . $dateObj->format('n') . ' / ' . $dateObj->format('Y');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تقرير الحضور - <?= htmlspecialchars($date) ?></title>
<link rel="stylesheet" href="../assets/fonts/tajawal.css">
<style>

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Tajawal', Arial, sans-serif;
    background: #f5f6fa;
    color: #1a1a2e;
    direction: rtl;
    font-size: 13px;
  }

  /* ===== شريط الأدوات (لا يطبع) ===== */
  .toolbar {
    background: #1e3a5f;
    color: #fff;
    padding: 10px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    print-color-adjust: exact;
  }
  @media print { .toolbar { display: none !important; } }

  .toolbar .tb-title { font-size: 1rem; font-weight: 700; }
  .toolbar .tb-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .tb-controls input[type=date],
  .tb-controls select {
    padding: 6px 10px; border-radius: 6px; border: none;
    font-family: 'Tajawal', sans-serif; font-size: .85rem;
  }
  .btn-print {
    background: #3b82f6; color: #fff; border: none; border-radius: 8px;
    padding: 8px 20px; cursor: pointer; font-family: 'Tajawal', sans-serif;
    font-size: .9rem; font-weight: 700;
    display: flex; align-items: center; gap: 6px;
  }
  .btn-print:hover { background: #2563eb; }
  .btn-back {
    background: rgba(255,255,255,.15); color: #fff; border: none; border-radius: 8px;
    padding: 8px 16px; cursor: pointer; font-family: 'Tajawal', sans-serif;
    font-size: .85rem; text-decoration: none;
    display: flex; align-items: center; gap: 6px;
  }
  .btn-back:hover { background: rgba(255,255,255,.25); }

  /* ===== الصفحة الرئيسية ===== */
  .page {
    max-width: 960px;
    margin: 24px auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,.10);
    overflow: hidden;
  }

  @media print {
    body { background: #fff; font-size: 11px; }
    .page {
      max-width: 100%;
      margin: 0;
      border-radius: 0;
      box-shadow: none;
    }
  }

  /* ===== رأس التقرير ===== */
  .report-header {
    background: linear-gradient(135deg, #1e3a5f 0%, #1a5276 100%);
    color: #fff;
    padding: 28px 32px 22px;
    position: relative;
    overflow: hidden;
  }
  .report-header::after {
    content: '';
    position: absolute;
    left: -40px; top: -40px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
  }
  .rh-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
  }
  .rh-system-name { font-size: 1.5rem; font-weight: 900; letter-spacing: -.5px; }
  .rh-subtitle    { font-size: .85rem; opacity: .75; margin-top: 4px; }
  .rh-date-box {
    text-align: left;
    background: rgba(255,255,255,.12);
    border-radius: 10px;
    padding: 10px 18px;
  }
  .rh-date-big  { font-size: 1.3rem; font-weight: 900; }
  .rh-date-day  { font-size: .82rem; opacity: .8; margin-top: 2px; }
  .rh-meta {
    margin-top: 16px;
    display: flex;
    gap: 28px;
    flex-wrap: wrap;
    font-size: .82rem;
    opacity: .85;
  }
  .rh-meta span::before { content: '● '; font-size: .6rem; margin-left: 4px; }

  /* ===== بطاقات الإحصائيات ===== */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1px;
    background: #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
  }
  @media print { .stats-row { grid-template-columns: repeat(4, 1fr); } }
  .stat-box {
    background: #fff;
    padding: 16px;
    text-align: center;
  }
  .stat-num {
    font-size: 1.9rem;
    font-weight: 900;
    line-height: 1;
    margin-bottom: 4px;
  }
  .stat-lbl { font-size: .78rem; color: #6b7280; }
  .stat-box.blue   .stat-num { color: #2563eb; }
  .stat-box.green  .stat-num { color: #16a34a; }
  .stat-box.red    .stat-num { color: #dc2626; }
  .stat-box.amber  .stat-num { color: #d97706; }

  /* ===== الجدول ===== */
  .table-wrap { overflow-x: auto; }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
  }
  thead tr {
    background: #f0f4fa;
  }
  thead th {
    padding: 11px 10px;
    text-align: right;
    font-weight: 700;
    color: #374151;
    border-bottom: 2px solid #d1d5db;
    white-space: nowrap;
  }
  tbody tr {
    border-bottom: 1px solid #f0f0f0;
    transition: background .15s;
  }
  tbody tr:hover { background: #f8fbff; }
  tbody tr.absent-row { background: #fff5f5; }
  @media print {
    tbody tr:hover { background: transparent; }
    tbody tr.absent-row { background: #fff5f5 !important; }
  }
  tbody td {
    padding: 10px 10px;
    vertical-align: middle;
  }
  .num-cell { color: #9ca3af; font-size: .8rem; width: 32px; text-align: center; }

  /* ===== الحالة (بادج) ===== */
  .badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 700;
  }
  .badge-present { background: #dcfce7; color: #15803d; }
  .badge-late    { background: #fef3c7; color: #b45309; }
  .badge-absent  { background: #fee2e2; color: #b91c1c; }

  /* الوقت */
  .time-val { font-weight: 700; color: #1d4ed8; font-size: 1rem; }
  .time-absent { color: #d1d5db; font-size: .85rem; }
  .duration-val { color: #374151; font-size: .85rem; }

  /* ===== تذييل التقرير ===== */
  .report-footer {
    border-top: 2px solid #e5e7eb;
    padding: 20px 32px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    flex-wrap: wrap;
    font-size: .82rem;
    color: #6b7280;
  }
  .sig-box {
    text-align: center;
    min-width: 160px;
  }
  .sig-line {
    border-top: 1px solid #9ca3af;
    margin-top: 40px;
    padding-top: 6px;
  }
  .report-footer .gen-info { font-size: .78rem; }
  .report-footer .gen-info p { margin-bottom: 4px; }

  /* row branch separator */
  tr.branch-header td {
    background: #1e3a5f;
    color: #fff;
    font-weight: 700;
    font-size: .85rem;
    padding: 7px 12px;
    text-align: right;
  }

  /* ===== زر إضافة/تعديل الحضور ===== */
  .btn-add-att {
    background: #10b981; color: #fff; border: none; border-radius: 8px;
    padding: 8px 18px; cursor: pointer; font-family: 'Tajawal', sans-serif;
    font-size: .9rem; font-weight: 700;
    display: flex; align-items: center; gap: 6px;
  }
  .btn-add-att:hover { background: #059669; }

  /* ===== المودال ===== */
  .att-modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 9999;
    align-items: center;
    justify-content: center;
  }
  .att-modal-overlay.open { display: flex; }
  .att-modal {
    background: #fff;
    border-radius: 16px;
    padding: 28px 32px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    direction: rtl;
  }
  .att-modal h3 {
    font-size: 1.1rem; font-weight: 800; color: #1e3a5f;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
  }
  .att-modal .form-group {
    margin-bottom: 14px;
  }
  .att-modal label {
    display: block; font-size: .85rem; font-weight: 700;
    color: #374151; margin-bottom: 5px;
  }
  .att-modal select,
  .att-modal input[type=time],
  .att-modal input[type=date] {
    width: 100%; padding: 9px 12px;
    border: 1px solid #d1d5db; border-radius: 8px;
    font-family: 'Tajawal', sans-serif; font-size: .9rem;
    outline: none;
  }
  .att-modal select:focus,
  .att-modal input:focus { border-color: #3b82f6; }
  .att-modal .modal-actions {
    display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end;
  }
  .att-modal .btn-save {
    background: #10b981; color: #fff; border: none;
    border-radius: 8px; padding: 10px 26px;
    font-family: 'Tajawal', sans-serif; font-size: .95rem; font-weight: 700;
    cursor: pointer;
  }
  .att-modal .btn-save:hover { background: #059669; }
  .att-modal .btn-cancel {
    background: #f3f4f6; color: #374151; border: none;
    border-radius: 8px; padding: 10px 20px;
    font-family: 'Tajawal', sans-serif; font-size: .95rem;
    cursor: pointer;
  }
  .att-modal .btn-cancel:hover { background: #e5e7eb; }
  .att-modal .msg-box {
    padding: 10px 14px; border-radius: 8px; font-size: .85rem;
    margin-top: 12px; display: none;
  }
  .att-modal .msg-box.success { background: #d1fae5; color: #065f46; display: block; }
  .att-modal .msg-box.error   { background: #fee2e2; color: #991b1b; display: block; }

  /* زر تعديل في كل صف */
  .btn-edit-row {
    background: #dbeafe; color: #1d4ed8; border: none;
    border-radius: 6px; padding: 3px 10px;
    font-family: 'Tajawal', sans-serif; font-size: .78rem;
    cursor: pointer; font-weight: 700;
  }
  .btn-edit-row:hover { background: #bfdbfe; }
  @media print { .btn-edit-row, .btn-add-att { display: none !important; } }
</style>
</head>
<body>

<!-- شريط الأدوات (لا يطبع) -->
<div class="toolbar">
  <span class="tb-title">تقرير الحضور اليومي</span>
  <div class="tb-controls">
    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
      <select name="branch">
        <option value="0" <?= !$filterBranch ? 'selected' : '' ?>>جميع الفروع</option>
        <?php foreach ($branchList as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-print" style="background:#475569">عرض</button>
    </form>
    <button class="btn-print" onclick="window.print()">🖨️ طباعة / PDF</button>
    <button class="btn-add-att" onclick="openAttModal()">＋ إضافة حضور</button>
    <a href="attendance.php" class="btn-back">← العودة</a>
  </div>
</div>

<!-- الصفحة الرئيسية -->
<div class="page">

  <!-- رأس التقرير -->
  <div class="report-header">
    <div class="rh-top">
      <div>
        <div class="rh-system-name">نظام الحضور والانصراف</div>
        <div class="rh-subtitle">سجل الحضور اليومي الرسمي</div>
      </div>
      <div class="rh-date-box">
        <div class="rh-date-big"><?= $dateObj->format('Y/m/d') ?></div>
        <div class="rh-date-day"><?= $dayOfWeek ?></div>
      </div>
    </div>
    <div class="rh-meta">
      <span>الفرع: <?= htmlspecialchars($selectedBranchName) ?></span>
      <span>إجمالي الموظفين: <?= $totalEmp ?></span>
      <span>صدر بتاريخ: <?= date('Y/m/d H:i') ?></span>
    </div>
  </div>

  <!-- إحصائيات -->
  <div class="stats-row">
    <div class="stat-box blue">
      <div class="stat-num"><?= $totalEmp ?></div>
      <div class="stat-lbl">إجمالي الموظفين</div>
    </div>
    <div class="stat-box green">
      <div class="stat-num"><?= $totalIn ?></div>
      <div class="stat-lbl">حاضرون</div>
    </div>
    <div class="stat-box red">
      <div class="stat-num"><?= $totalAbsent ?></div>
      <div class="stat-lbl">غائبون</div>
    </div>
    <div class="stat-box amber">
      <div class="stat-num"><?= $totalLate ?></div>
      <div class="stat-lbl">متأخرون</div>
    </div>
  </div>

  <!-- الجدول الرئيسي -->
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th class="num-cell">#</th>
        <th>اسم الموظف</th>
        <th>المسمى الوظيفي</th>
        <th>الفرع</th>
        <th>وقت الحضور</th>
        <th>وقت الانصراف</th>
        <th>مدة العمل</th>
        <th>التأخير</th>
        <th>الحالة</th>
        <th class="no-print">تعديل</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $serial = 0;
    $lastBranch = null;
    foreach ($rows as $r):
        $serial++;
        $isAbsent   = !$r['check_in_ts'];
        $lateMin    = (int)($r['late_min'] ?? 0);
        $isLate     = !$isAbsent && $lateMin > 0;
        $isPresent  = !$isAbsent;

        // فاصل الفرع
        if ($r['branch_name'] !== $lastBranch) {
            $lastBranch = $r['branch_name'];
    ?>
    <tr class="branch-header">
      <td colspan="9">فرع: <?= htmlspecialchars($r['branch_name'] ?? 'بدون فرع') ?></td>
    </tr>
    <?php } ?>

    <tr class="<?= $isAbsent ? 'absent-row' : '' ?>">
      <td class="num-cell"><?= $serial ?></td>
      <td><strong><?= htmlspecialchars($r['emp_name']) ?></strong></td>
      <td style="color:#6b7280;font-size:.82rem"><?= htmlspecialchars($r['job_title'] ?? '') ?></td>
      <td style="color:#374151;font-size:.82rem"><?= htmlspecialchars($r['branch_name'] ?? '-') ?></td>
      <td>
        <?php if ($r['check_in_ts']): ?>
          <span class="time-val"><?= date('h:i A', strtotime($r['check_in_ts'])) ?></span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($r['check_out_ts']): ?>
          <span class="time-val" style="color:#7c3aed"><?= date('h:i A', strtotime($r['check_out_ts'])) ?></span>
        <?php elseif (!$isAbsent): ?>
          <span style="color:#f59e0b;font-size:.82rem">لم ينصرف</span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($r['check_in_ts'] && $r['check_out_ts']): ?>
          <?php
            $inTs  = strtotime($r['check_in_ts']);
            $outTs = strtotime($r['check_out_ts']);
            // إذا كان الانصراف في اليوم التالي (وردية تتجاوز منتصف الليل)
            if ($outTs < $inTs) $outTs += 86400;
            $diff  = $outTs - $inTs;
            $hrs   = floor($diff / 3600);
            $mins  = floor(($diff % 3600) / 60);
          ?>
          <span class="duration-val"><?= $hrs ?>س <?= $mins ?>د</span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isLate): ?>
          <span style="color:#d97706;font-size:.82rem;font-weight:700">
            <?= $lateMin >= 60
              ? floor($lateMin/60).'س '.($lateMin%60).'د'
              : $lateMin.'د' ?>
          </span>
        <?php else: ?>
          <span class="time-absent">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isAbsent): ?>
          <span class="badge badge-absent">غائب</span>
        <?php elseif ($isLate): ?>
          <span class="badge badge-late">متأخر</span>
        <?php else: ?>
          <span class="badge badge-present">حاضر</span>
        <?php endif; ?>
      </td>
      <td class="no-print">
        <button class="btn-edit-row" onclick="openAttModal(<?= $r['emp_id'] ?>, '<?= htmlspecialchars($r['emp_name'], ENT_QUOTES) ?>', '<?= $r['check_in_ts'] ? date('H:i', strtotime($r['check_in_ts'])) : '' ?>', '<?= $r['check_out_ts'] ? date('H:i', strtotime($r['check_out_ts'])) : '' ?>')">تعديل</button>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <tr><td colspan="9" style="text-align:center;padding:32px;color:#9ca3af">لا يوجد موظفون</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>

  <!-- تذييل التقرير -->
  <div class="report-footer">
    <div class="gen-info">
      <p><strong>نظام الحضور والانصراف</strong></p>
      <p>تاريخ التقرير: <?= $dateAr ?></p>
      <p>الفرع: <?= htmlspecialchars($selectedBranchName) ?></p>
      <p>وقت الإصدار: <?= date('Y/m/d — H:i:s') ?></p>
    </div>
    <div style="display:flex;gap:60px">
      <div class="sig-box">
        <div class="sig-line">توقيع المدير</div>
      </div>
      <div class="sig-box">
        <div class="sig-line">توقيع مسؤول الموارد البشرية</div>
      </div>
    </div>
  </div>

</div><!-- /.page -->

<!-- مودال إضافة/تعديل الحضور -->
<div class="att-modal-overlay" id="attModalOverlay">
  <div class="att-modal">
    <h3 id="attModalTitle">إضافة / تعديل الحضور</h3>
    <div class="form-group">
      <label>الموظف</label>
      <select id="attEmpId">
        <option value="">-- اختر موظفاً --</option>
        <?php foreach ($rows as $r): ?>
          <option value="<?= $r['emp_id'] ?>"><?= htmlspecialchars($r['emp_name']) ?> (<?= htmlspecialchars($r['branch_name'] ?? '') ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>التاريخ</label>
      <input type="date" id="attDate" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div class="form-group">
      <label>وقت الحضور <span style="color:#9ca3af;font-weight:400">(اتركه فارغاً لعدم التغيير)</span></label>
      <input type="time" id="attCheckIn">
    </div>
    <div class="form-group">
      <label>وقت الانصراف <span style="color:#9ca3af;font-weight:400">(اتركه فارغاً لعدم التغيير)</span></label>
      <input type="time" id="attCheckOut">
    </div>
    <div id="attMsg" class="msg-box"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeAttModal()">إلغاء</button>
      <button class="btn-save" id="attSaveBtn" onclick="saveAttendance()">حفظ</button>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
const REPORT_DATE = '<?= htmlspecialchars($date) ?>';

function openAttModal(empId = '', empName = '', checkIn = '', checkOut = '') {
    document.getElementById('attModalOverlay').classList.add('open');
    document.getElementById('attDate').value = REPORT_DATE;
    if (empId) {
        document.getElementById('attEmpId').value = empId;
        document.getElementById('attModalTitle').textContent = 'تعديل حضور: ' + empName;
    } else {
        document.getElementById('attEmpId').value = '';
        document.getElementById('attModalTitle').textContent = 'إضافة حضور يدوي';
    }
    document.getElementById('attCheckIn').value  = checkIn;
    document.getElementById('attCheckOut').value = checkOut;
    document.getElementById('attMsg').className = 'msg-box';
    document.getElementById('attMsg').textContent = '';
}

function closeAttModal() {
    document.getElementById('attModalOverlay').classList.remove('open');
}

// إغلاق عند الضغط خارج المودال
document.getElementById('attModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeAttModal();
});

function saveAttendance() {
    const empId    = document.getElementById('attEmpId').value;
    const date     = document.getElementById('attDate').value;
    const checkIn  = document.getElementById('attCheckIn').value;
    const checkOut = document.getElementById('attCheckOut').value;
    const msgBox   = document.getElementById('attMsg');
    const saveBtn  = document.getElementById('attSaveBtn');

    if (!empId)  { showMsg('يجب اختيار الموظف', 'error'); return; }
    if (!date)   { showMsg('يجب تحديد التاريخ', 'error'); return; }
    if (!checkIn && !checkOut) { showMsg('يجب إدخال وقت الحضور أو الانصراف', 'error'); return; }

    saveBtn.disabled = true;
    saveBtn.textContent = 'جاري الحفظ...';

    const fd = new FormData();
    fd.append('csrf_token',   CSRF_TOKEN);
    fd.append('employee_id',  empId);
    fd.append('date',         date);
    if (checkIn)  fd.append('check_in',  checkIn);
    if (checkOut) fd.append('check_out', checkOut);

    fetch('../api/attendance-handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showMsg('✅ تم الحفظ بنجاح! سيتم تحديث الصفحة...', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showMsg('❌ ' + (data.message || 'حدث خطأ'), 'error');
                saveBtn.disabled = false;
                saveBtn.textContent = 'حفظ';
            }
        })
        .catch(() => {
            showMsg('❌ خطأ في الاتصال بالخادم', 'error');
            saveBtn.disabled = false;
            saveBtn.textContent = 'حفظ';
        });
}

function showMsg(text, type) {
    const el = document.getElementById('attMsg');
    el.textContent = text;
    el.className = 'msg-box ' + type;
}

// طباعة تلقائية إذا طُلب ذلك من URL
if (new URLSearchParams(location.search).get('autoprint') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>
</body>
</html>
