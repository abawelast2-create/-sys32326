<?php
// اختبار سريع لصفحات التقارير

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== اختبار قاعدة البيانات ===\n";

try {
    $result = db()->query('SELECT 1')->fetch();
    echo "✅ اتصال قاعدة البيانات: موافق\n\n";
} catch (Exception $e) {
    echo "❌ خطأ في اتصال قاعدة البيانات: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "=== فحص الجداول ===\n";

$tables = ['attendances', 'employees', 'branches', 'secret_reports', 'emp_document_groups'];

foreach ($tables as $table) {
    try {
        $result = db()->query("SELECT COUNT(*) FROM $table")->fetch();
        $count = $result['COUNT(*)'] ?? 0;
        echo "✅ $table: {$count} سجل\n";
    } catch (Exception $e) {
        echo "❌ $table: " . $e->getMessage() . "\n";
    }
}

echo "\n=== فحص الأعمدة الأساسية ===\n";

// فحص أعمدة attendances
try {
    $cols = db()->query("DESCRIBE attendances")->fetchAll(PDO::FETCH_COLUMN, 0);
    echo "أعمدة attendances: " . implode(', ', $cols) . "\n";
    
    $required = ['id', 'employee_id', 'type', 'timestamp', 'attendance_date', 'late_minutes'];
    $missing = array_diff($required, $cols);
    if ($missing) {
        echo "❌ أعمدة مفقودة: " . implode(', ', $missing) . "\n";
    } else {
        echo "✅ جميع الأعمدة المطلوبة موجودة\n";
    }
} catch (Exception $e) {
    echo "❌ خطأ في فحص الأعمدة: " . $e->getMessage() . "\n";
}

echo "\n=== استعلام تجريبي (مثل report-charts.php) ===\n";

try {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');
    
    $stmt = db()->prepare("
        SELECT
            COUNT(CASE WHEN a.type = 'in' THEN 1 END) AS total_check_ins,
            COUNT(CASE WHEN a.type = 'out' THEN 1 END) AS total_check_outs,
            COUNT(DISTINCT a.employee_id) AS unique_employees,
            COUNT(DISTINCT a.attendance_date) AS working_days,
            ROUND(AVG(CASE WHEN a.type = 'in' AND a.late_minutes > 0 THEN a.late_minutes END), 1) AS avg_late_minutes,
            SUM(CASE WHEN a.type = 'in' AND a.late_minutes > 0 THEN 1 ELSE 0 END) AS late_count
        FROM attendances a
        JOIN employees e ON a.employee_id = e.id
        WHERE a.attendance_date BETWEEN ? AND ?
          AND a.type IN ('in', 'out')
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $stats = $stmt->fetch();
    echo "✅ استعلام التقرير: موافق\n";
    echo print_r($stats, true) . "\n";
} catch (Exception $e) {
    echo "❌ خطأ في استعلام التقرير: " . $e->getMessage() . "\n";
}

echo "\n=== انتهى الاختبار ===\n";
