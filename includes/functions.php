<?php
// =============================================================
// includes/functions.php - الدوال العامة للنظام
// =============================================================

require_once __DIR__ . '/db.php';

/**
 * إنشاء token فريد لموظف
 */
function generateUniqueToken(): string {
    do {
        $token = bin2hex(random_bytes(32)); // 64 حرف hex
        $stmt = db()->prepare("SELECT id FROM employees WHERE unique_token = ?");
        $stmt->execute([$token]);
    } while ($stmt->fetch()); // كرر حتى يكون فريداً
    return $token;
}

/**
 * توليد PIN فريد من 4 أرقام
 */
function generateUniquePin(): string {
    do {
        $pin = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = db()->prepare("SELECT id FROM employees WHERE pin = ?");
        $stmt->execute([$pin]);
    } while ($stmt->fetch());
    return $pin;
}

/**
 * جلب موظف عبر PIN
 */
function getEmployeeByPin(string $pin): ?array {
    $stmt = db()->prepare("SELECT * FROM employees WHERE pin = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$pin]);
    return $stmt->fetch() ?: null;
}

/**
 * حساب المسافة بين نقطتين جغرافيتين (صيغة Haversine)
 * @return float المسافة بالمتر
 */
function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000; // متر
    $latFrom = deg2rad($lat1);
    $lonFrom = deg2rad($lon1);
    $latTo   = deg2rad($lat2);
    $lonTo   = deg2rad($lon2);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $angle = 2 * asin(sqrt(
        pow(sin($latDelta / 2), 2) +
        cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
    ));

    return $angle * $earthRadius;
}

/**
 * تحميل جميع الإعدادات دفعة واحدة (تحسين الأداء - تجنب N+1 queries)
 * @param bool $refresh إعادة التحميل من DB (بعد setSystemSetting)
 */
function loadAllSettings(bool $refresh = false): array {
    static $cache = null;
    if ($cache === null || $refresh) {
        try {
            $rows  = db()->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            $cache = array_column($rows, 'setting_value', 'setting_key');
        } catch (Exception $e) {
            $cache = [];
        }
    }
    return $cache;
}

/**
 * التحقق إذا كان الموظف داخل نطاق الجيوفينس
 * @param float $empLat إحداثيات الموظف
 * @param float $empLon إحداثيات الموظف
 * @param int|null $branchId معرف الفرع (اختياري)
 */
function isWithinGeofence(float $empLat, float $empLon, ?int $branchId = null): array {
    $workLat = 0;
    $workLon = 0;
    $radius  = 500;
    
    // إذا كان للموظف فرع، استخدم إحداثيات الفرع
    if ($branchId) {
        $stmt = db()->prepare("SELECT latitude, longitude, geofence_radius FROM branches WHERE id = ? AND is_active = 1");
        $stmt->execute([$branchId]);
        $branch = $stmt->fetch();
        if ($branch) {
            $workLat = (float) $branch['latitude'];
            $workLon = (float) $branch['longitude'];
            $radius  = (float) $branch['geofence_radius'];
        }
    }
    
    // إذا لم يُعيَّن من الفرع، استخدم الإعدادات العامة
    if ($workLat == 0 && $workLon == 0) {
        $settings = loadAllSettings();
        $workLat  = (float) ($settings['work_latitude']  ?? '0');
        $workLon  = (float) ($settings['work_longitude'] ?? '0');
        $radius   = (float) ($settings['geofence_radius'] ?? '500');
    }

    // إذا لم يُعيَّن موقع العمل بعد، اسمح بأي موقع
    if ($workLat == 0 && $workLon == 0) {
        return ['allowed' => true, 'distance' => 0, 'message' => 'موقع العمل غير محدد - مسموح'];
    }

    $distance = calculateDistance($empLat, $empLon, $workLat, $workLon);
    $allowed  = $distance <= $radius;
    $dist     = round($distance);

    return [
        'allowed'  => $allowed,
        'distance' => $dist,
        'radius'   => $radius,
        'message'  => $allowed
            ? "أنت داخل نطاق العمل ({$dist} متر)"
            : "أنت خارج نطاق العمل! المسافة: {$dist} متر (الحد المسموح: {$radius} متر)"
    ];
}

/**
 * تسجيل حضور أو انصراف موظف
 */
function recordAttendance(int $employeeId, string $type, float $lat, float $lon, float $accuracy = 0): array {
    // التحقق من صحة نوع التسجيل
    $validTypes = ['in', 'out', 'overtime-start', 'overtime-end'];
    if (!in_array($type, $validTypes, true)) {
        return ['success' => false, 'message' => 'نوع تسجيل غير صالح'];
    }

    // التحقق من تكرار التسجيل خلال 5 دقائق
    $recent = hasRecentAttendance($employeeId, $type, 5);
    if ($recent) {
        return ['success' => false, 'message' => 'تم التسجيل مسبقاً خلال آخر 5 دقائق'];
    }

    $ip        = getClientIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // حساب دقائق التأخير (عند تسجيل الدخول فقط)
    $lateMinutes = 0;
    if ($type === 'in') {
        $empStmt = db()->prepare("SELECT branch_id FROM employees WHERE id = ?");
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch();
        $schedule = getBranchSchedule($emp['branch_id'] ?? null);
        $now = time();

        $referenceTimeStr = $schedule['work_start_time'];

        $workStart = strtotime(date('Y-m-d') . ' ' . $referenceTimeStr);
        // Handle midnight crossing
        if ($workStart > $now + 43200) {
            $workStart = strtotime(date('Y-m-d', strtotime('-1 day')) . ' ' . $referenceTimeStr);
        }
        if ($now > $workStart) {
            $lateMinutes = max(0, (int)round(($now - $workStart) / 60));
        }
    }

    $stmt = db()->prepare("
        INSERT INTO attendances (employee_id, type, timestamp, attendance_date, late_minutes, latitude, longitude, location_accuracy, ip_address, user_agent)
        VALUES (?, ?, NOW(), CURDATE(), ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$employeeId, $type, $lateMinutes, $lat, $lon, $accuracy, $ip, $userAgent]);

    return ['success' => true, 'message' => $type === 'in' ? 'تم تسجيل الدخول بنجاح' : 'تم تسجيل الانصراف بنجاح', 'late_minutes' => $lateMinutes];
}

/**
 * التحقق من وجود تسجيل حديث لتجنب التكرار
 */
function hasRecentAttendance(int $employeeId, string $type, int $minutes = 5): bool {
    $stmt = db()->prepare("
        SELECT id FROM attendances
        WHERE employee_id = ? AND type = ?
          AND timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        LIMIT 1
    ");
    $stmt->execute([$employeeId, $type, $minutes]);
    return (bool) $stmt->fetch();
}

/**
 * جلب موظف عبر token
 */
function getEmployeeByToken(string $token): ?array {
    $stmt = db()->prepare("SELECT * FROM employees WHERE unique_token = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * جلب قيمة إعداد من جدول settings (يستخدم cache لتجنب N+1)
 */
function getSystemSetting(string $key, string $default = ''): string {
    $cache = loadAllSettings();
    return isset($cache[$key]) ? (string)$cache[$key] : $default;
}

/**
 * حفظ إعداد في جدول settings (يُبطل Cache)
 */
function setSystemSetting(string $key, string $value): void {
    $stmt = db()->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
    // إبطال الـ cache بعد التحديث عبر حيلة static variable
    loadAllSettings(true);
}

/**
 * الحصول على IP الحقيقي للعميل (مع حماية من IP Spoofing)
 */
function getClientIP(): string {
    // REMOTE_ADDR هو الأكثر أمانًا (لا يمكن تزويره بسهولة)
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // قبول X-Forwarded-For فقط إذا كان المصدر من شبكة CDN/Proxy موثوقة
    $trustedProxies = ['127.0.0.1', '::1']; // أضف IPs الـ CDN هنا إن احتجت
    if (in_array($remoteAddr, $trustedProxies, true)) {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIVATE_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
}

/**
 * تنظيف المدخلات لمنع XSS
 */
function sanitize(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

/**
 * إنشاء CSRF token
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * التحقق من CSRF token (مع تدوير بعد التحقق الناجح)
 */
function verifyCsrfToken(string $token): bool {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    // تدوير التوكن بعد كل تحقق ناجح (One-Time Token)
    unset($_SESSION['csrf_token']);
    return true;
}

/**
 * حفظ أو تعديل سجل الحضور/الانصراف لموظف في يوم محدد
 * يُستخدم من واجهة الأدمن لإضافة حضور يدوي أو تعديل وقت
 *
 * @param int    $employeeId  معرف الموظف
 * @param string $date        التاريخ بصيغة Y-m-d
 * @param string|null $checkIn  وقت الحضور بصيغة H:i أو null
 * @param string|null $checkOut وقت الانصراف بصيغة H:i أو null
 */
function saveAttendanceRecord(int $employeeId, string $date, ?string $checkIn, ?string $checkOut): void {
    $pdo = db();

    if ($checkIn !== null) {
        $ts = $date . ' ' . $checkIn . ':00';

        // حساب دقائق التأخير باستخدام getBranchSchedule الصحيحة
        $lateMin = 0;
        $empStmt = $pdo->prepare("SELECT branch_id FROM employees WHERE id = ? LIMIT 1");
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch();
        $schedule = getBranchSchedule($emp['branch_id'] ?? null);
        if (!empty($schedule['work_start_time'])) {
            $workStart = strtotime($date . ' ' . $schedule['work_start_time']);
            $inTime    = strtotime($ts);
            if ($inTime > $workStart) {
                $lateMin = (int)(($inTime - $workStart) / 60);
            }
        }

        // تحقق إذا يوجد سجل حضور بالفعل
        $existing = $pdo->prepare("SELECT id FROM attendances WHERE employee_id=? AND attendance_date=? AND type='in' ORDER BY timestamp ASC LIMIT 1");
        $existing->execute([$employeeId, $date]);
        $row = $existing->fetch();
        if ($row) {
            // تحديث الوقت
            $upd = $pdo->prepare("UPDATE attendances SET timestamp=?, late_minutes=? WHERE id=?");
            $upd->execute([$ts, $lateMin, $row['id']]);
        } else {
            // إدراج جديد - إضافة يدوية من المشرف (lat/lng = 0)
            $ins = $pdo->prepare("INSERT INTO attendances (employee_id, type, timestamp, attendance_date, late_minutes, latitude, longitude) VALUES (?,?,?,?,?,0,0)");
            $ins->execute([$employeeId, 'in', $ts, $date, $lateMin]);
        }
    }

    if ($checkOut !== null) {
        $ts = $date . ' ' . $checkOut . ':00';

        $existing = $pdo->prepare("SELECT id FROM attendances WHERE employee_id=? AND attendance_date=? AND type='out' ORDER BY timestamp DESC LIMIT 1");
        $existing->execute([$employeeId, $date]);
        $row = $existing->fetch();
        if ($row) {
            $upd = $pdo->prepare("UPDATE attendances SET timestamp=? WHERE id=?");
            $upd->execute([$ts, $row['id']]);
        } else {
            // إدراج جديد - إضافة يدوية من المشرف (lat/lng = 0)
            $ins = $pdo->prepare("INSERT INTO attendances (employee_id, type, timestamp, attendance_date, latitude, longitude) VALUES (?,?,?,?,0,0)");
            $ins->execute([$employeeId, 'out', $ts, $date]);
        }
    }
}

/**
 * إرجاع رسالة JSON وإنهاء التنفيذ
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * تسجيل عملية في سجل المراجعة (Audit Log)
 * @param string $action نوع العملية (login, add_employee, edit_employee, delete_employee, change_password, update_settings, ...)
 * @param string $details تفاصيل إضافية
 * @param int|null $targetId معرف العنصر المستهدف (اختياري)
 */
function auditLog(string $action, string $details = '', ?int $targetId = null): void {
    try {
        $stmt = db()->prepare("
            INSERT INTO audit_log (admin_id, action, details, target_id, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['admin_id'] ?? null,
            $action,
            $details,
            $targetId,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (Exception $e) {
        // الجدول قد لا يكون موجوداً بعد — تجاهل الخطأ بصمت
    }
}

/**
 * إنشاء رابط واتساب لموظف
 */
function generateWhatsAppLink(string $phone, string $token): string {
    $url     = SITE_URL . '/employee/attendance.php?token=' . $token;
    $message = urlencode("مرحباً، هذا رابط تسجيل الحضور والانصراف الخاص بك:\n{$url}\n\nيرجى استخدامه يومياً لتسجيل حضورك وانصرافك.");
    $phone   = preg_replace('/[^0-9]/', '', $phone);
    return "https://wa.me/{$phone}?text={$message}";
}

/**
 * تحويل وقت HH:MM أو HH:MM:SS إلى دقائق من منتصف الليل
 */
function timeToMinutes(string $time): int {
    $p = explode(':', $time);
    return (int)$p[0] * 60 + (int)($p[1] ?? 0);
}

/**
 * كشف الوردية النشطة من قائمة ورديات الفرع حسب الوقت الحالي
 * نافذة تسجيل الحضور = shift_start - 60 دقيقة حتى shift_end
 */
function detectActiveShift(array $shifts): ?array {
    $nowMin = (int)date('H') * 60 + (int)date('i');
    foreach ($shifts as $s) {
        $start = timeToMinutes($s['shift_start']);
        $end   = timeToMinutes($s['shift_end']);
        $early = ($start - 60 + 1440) % 1440; // ساعة قبل البداية
        if ($end < $early) {
            // يعبر منتصف الليل
            if ($nowMin >= $early || $nowMin <= $end) return $s;
        } else {
            if ($nowMin >= $early && $nowMin <= $end) return $s;
        }
    }
    // لا وردية نشطة — أعد الأقرب
    $best = null;
    $bestDist = 9999;
    foreach ($shifts as $s) {
        $start = timeToMinutes($s['shift_start']);
        $dist = ($start - $nowMin + 1440) % 1440;
        if ($dist < $bestDist) { $bestDist = $dist; $best = $s; }
    }
    return $best ?: $shifts[0];
}

/**
 * جلب مواعيد الفرع من جدول الورديات (branch_shifts)
 * يكتشف الوردية النشطة تلقائياً حسب الوقت
 */
function getBranchSchedule(?int $branchId = null): array {
    $defaults = [
        'allow_overtime'       => true,
        'overtime_start_after' => 60,
        'overtime_min_duration'=> 30,
    ];

    if ($branchId) {
        // جلب ورديات الفرع
        $stmt = db()->prepare("SELECT shift_number, shift_start, shift_end FROM branch_shifts WHERE branch_id = ? AND is_active = 1 ORDER BY shift_number ASC");
        $stmt->execute([$branchId]);
        $shifts = $stmt->fetchAll();

        // جلب إعدادات الدوام الإضافي من الفرع
        $brStmt = db()->prepare("SELECT allow_overtime, overtime_start_after, overtime_min_duration FROM branches WHERE id = ? AND is_active = 1");
        $brStmt->execute([$branchId]);
        $brData = $brStmt->fetch();

        if ($shifts) {
            $active = detectActiveShift($shifts);
            return [
                'work_start_time'      => $active['shift_start'],
                'work_end_time'        => $active['shift_end'],
                'current_shift'        => (int)$active['shift_number'],
                'shifts'               => $shifts,
                'allow_overtime'       => $brData ? (bool)$brData['allow_overtime'] : true,
                'overtime_start_after' => $brData ? (int)$brData['overtime_start_after'] : 60,
                'overtime_min_duration'=> $brData ? (int)$brData['overtime_min_duration'] : 30,
            ];
        }
    }

    // افتراضي
    return [
        'work_start_time'      => '12:00',
        'work_end_time'        => '16:00',
        'current_shift'        => 1,
        'shifts'               => [['shift_number' => 1, 'shift_start' => '12:00', 'shift_end' => '16:00']],
        'allow_overtime'       => true,
        'overtime_start_after' => 60,
        'overtime_min_duration'=> 30,
    ];
}

/**
 * تحويل وقت إلى عربي مقروء
 */
function arabicDateTime(string $datetime): string {
    if (empty($datetime)) return '-';
    $ts = strtotime($datetime);
    return date('Y/m/d', $ts) . ' - ' . date('h:i A', $ts);
}

/**
 * إحصائيات سريعة ليوم اليوم
 */
function getTodayStats(): array {
    $today = date('Y-m-d');
    $stmt  = db()->prepare("
        SELECT
            (SELECT COUNT(DISTINCT employee_id) FROM attendances WHERE attendance_date = ? AND type = 'in') AS checked_in,
            (SELECT COUNT(DISTINCT employee_id) FROM attendances WHERE attendance_date = ? AND type = 'out') AS checked_out,
            (SELECT COUNT(*) FROM employees WHERE is_active = 1 AND deleted_at IS NULL) AS total_employees
    ");
    $stmt->execute([$today, $today]);
    return $stmt->fetch();
}

// =============================================================
// H: شعار التطبيق (مربعان متداخلان) + أيقونات SVG
// =============================================================
function getLogoSvg(int $size = 40, string $c1 = '#F97316', string $c2 = '#EA580C'): string {
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">'
         . '<rect x="2" y="2" width="22" height="22" rx="4" fill="'.htmlspecialchars($c1).'" opacity="0.9"/>'
         . '<rect x="16" y="16" width="22" height="22" rx="4" fill="'.htmlspecialchars($c2).'"/>'
         . '</svg>';
}

function svgIcon(string $name, int $size = 20): string {
    $paths = [
        'dashboard'  => '<path d="M4 4h7v7H4zm9 0h7v7h-7zM4 13h7v7H4zm9 0h7v7h-7z"/>',
        'branch'     => '<path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>',
        'employees'  => '<path d="M16 11c1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>',
        'attendance' => '<path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm-2 14l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>',
        'settings'   => '<path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/>',
        'logout'     => '<path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>',
        'checkin'    => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>',
        'checkout'   => '<path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>',
        'absent'     => '<path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/>',
        'present'    => '<path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>',
        'clock'      => '<path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>',
        'lock'       => '<path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1s3.1 1.39 3.1 3.1v2z"/>',
        'copy'       => '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>',
        'overtime'   => '<path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/><path d="M12.5 7H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>',
        'user'       => '<path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>',
        'key'        => '<path d="M12.65 10A5.99 5.99 0 0 0 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6a5.99 5.99 0 0 0 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>',
        'whatsapp'   => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="currentColor">'.$p.'</svg>';
}
