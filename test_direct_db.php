<?php
// اختبار مباشر للاتصال

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = '194.164.74.250';
$user = 'u307296675_whats';
$pass = 'Goolbx512@@@';
$db = 'u307296675_whats';

echo "محاولة الاتصال بـ $host...\n";
echo "البيانات: user=$user, db=$db\n\n";

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ اتصال ناجح!\n\n";
    
    // اختبار الجداول
    $tables = ['attendances', 'employees', 'branches'];
    foreach ($tables as $table) {
        try {
            $result = $pdo->query("SELECT COUNT(*) as cnt FROM $table")->fetch();
            echo "✅ $table: {$result['cnt']} سجل\n";
        } catch (Exception $e) {
            echo "❌ $table: " . $e->getMessage() . "\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ خطأ الاتصال:\n";
    echo "  رمز الخطأ: " . $e->getCode() . "\n";
    echo "  الرسالة: " . $e->getMessage() . "\n";
}
