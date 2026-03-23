<?php
// =============================================================
// api/attendance-handler.php - إضافة/تعديل الحضور يدوياً (أدمن)
// =============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'طلب غير صالح'], 405);
}

// التحقق من CSRF token
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'رمز الأمان غير صالح، أعد تحميل الصفحة'], 403);
}

$employeeId = (int)($_POST['employee_id'] ?? 0);
$date       = trim($_POST['date'] ?? '');
$checkIn    = trim($_POST['check_in'] ?? '') ?: null;
$checkOut   = trim($_POST['check_out'] ?? '') ?: null;

// التحقق من صحة المدخلات
if ($employeeId <= 0) {
    jsonResponse(['success' => false, 'message' => 'يجب تحديد الموظف'], 400);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    jsonResponse(['success' => false, 'message' => 'التاريخ غير صالح'], 400);
}

if ($checkIn !== null && !preg_match('/^\d{2}:\d{2}$/', $checkIn)) {
    jsonResponse(['success' => false, 'message' => 'وقت الحضور غير صالح'], 400);
}

if ($checkOut !== null && !preg_match('/^\d{2}:\d{2}$/', $checkOut)) {
    jsonResponse(['success' => false, 'message' => 'وقت الانصراف غير صالح'], 400);
}

if ($checkIn === null && $checkOut === null) {
    jsonResponse(['success' => false, 'message' => 'يجب إدخال وقت الحضور أو الانصراف على الأقل'], 400);
}

// التحقق من وجود الموظف
$empStmt = db()->prepare("SELECT id, name FROM employees WHERE id=? AND is_active=1 AND deleted_at IS NULL LIMIT 1");
$empStmt->execute([$employeeId]);
$employee = $empStmt->fetch();
if (!$employee) {
    jsonResponse(['success' => false, 'message' => 'الموظف غير موجود'], 404);
}

try {
    saveAttendanceRecord($employeeId, $date, $checkIn, $checkOut);

    // تسجيل العملية في سجل المراجعة
    $detail = "تعديل يدوي للحضور - الموظف: {$employee['name']} - التاريخ: $date";
    if ($checkIn)  $detail .= " - حضور: $checkIn";
    if ($checkOut) $detail .= " - انصراف: $checkOut";
    auditLog('manual_attendance', $detail, $employeeId);

    jsonResponse(['success' => true, 'message' => 'تم الحفظ بنجاح']);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'خطأ في الحفظ: ' . $e->getMessage()], 500);
}
