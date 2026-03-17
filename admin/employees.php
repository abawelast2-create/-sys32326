<?php
// admin/employees.php - إدارة الموظفين (CRUD + WhatsApp)
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$pageTitle  = 'إدارة الموظفين';
$activePage = 'employees';
$message    = '';
$msgType    = '';

// =================== إجراءات POST ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'طلب غير صالح';
        $msgType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        // --- إضافة موظف ---
        if ($action === 'add') {
            $name    = sanitize($_POST['name'] ?? '');
            $job     = sanitize($_POST['job_title'] ?? '');
            $pin     = sanitize($_POST['pin'] ?? '');
            $phone   = sanitize($_POST['phone'] ?? '');
            $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;

            // توليد PIN تلقائي إذا لم يُحدد
            if (empty($pin)) {
                $pin = generateUniquePin();
            }

            if ($name && $job) {
                // التحقق من وجود الفرع
                if ($branchId !== null) {
                    $branchCheck = db()->prepare("SELECT id FROM branches WHERE id = ? AND is_active = 1");
                    $branchCheck->execute([$branchId]);
                    if (!$branchCheck->fetch()) {
                        $message = 'الفرع المحدد غير موجود أو غير مفعل';
                        $msgType = 'error';
                        header('Location: employees.php?msg=' . urlencode($message) . '&t=' . $msgType);
                        exit;
                    }
                }
                try {
                    $token = generateUniqueToken();
                    $stmt  = db()->prepare("INSERT INTO employees (name, job_title, pin, phone, branch_id, unique_token) VALUES (?,?,?,?,?,?)");
                    $stmt->execute([$name, $job, $pin, $phone ?: null, $branchId, $token]);
                    $newId = (int)db()->lastInsertId();
                    auditLog('add_employee', "إضافة موظف: {$name}", $newId);
                    $message = "تم إضافة الموظف {$name} بنجاح — PIN: {$pin}";
                    $msgType = 'success';
                } catch (PDOException $e) {
                    $message = 'PIN أو بيانات مكررة: ' . $e->getMessage();
                    $msgType = 'error';
                }
            } else {
                $message = 'أدخل الاسم والوظيفة';
                $msgType = 'error';
            }
        }

        // --- تعديل موظف ---
        if ($action === 'edit') {
            $id    = (int)($_POST['emp_id'] ?? 0);
            $name  = sanitize($_POST['name'] ?? '');
            $job   = sanitize($_POST['job_title'] ?? '');
            $phone = sanitize($_POST['phone'] ?? '');
            $active = (int)($_POST['is_active'] ?? 1);
            $branchId = (int)($_POST['branch_id'] ?? 0) ?: null;

            if ($id && $name && $job) {
                $stmt = db()->prepare("UPDATE employees SET name=?, job_title=?, phone=?, branch_id=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $job, $phone ?: null, $branchId, $active, $id]);
                auditLog('edit_employee', "تعديل موظف: {$name}", $id);
                $message = "تم تحديث بيانات الموظف";
                $msgType = 'success';
            }
        }

        // --- حذف موظف (Soft Delete) ---
        if ($action === 'delete') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                // إبطال التوكن عند الأرشفة لمنع استمرار الوصول
                $newToken = bin2hex(random_bytes(32));
                db()->prepare("UPDATE employees SET deleted_at=NOW(), is_active=0, unique_token=? WHERE id=?")->execute([$newToken, $id]);
                auditLog('delete_employee', "أرشفة موظف ID={$id}", $id);
                $message = "تم أرشفة الموظف (يمكن استعادته لاحقاً)";
                $msgType = 'success';
            }
        }

        // --- استعادة موظف محذوف ---
        if ($action === 'restore') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET deleted_at=NULL, is_active=1 WHERE id=?")->execute([$id]);
                auditLog('restore_employee', "استعادة موظف ID={$id}", $id);
                $message = "تم استعادة الموظف بنجاح";
                $msgType = 'success';
            }
        }

        // --- تفعيل/تعطيل موظف ---
        if ($action === 'toggle') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
                $message = "تم تغيير حالة الموظف";
                $msgType = 'success';
            }
        }

        // --- تغيير PIN ---
        if ($action === 'change_pin') {
            $id = (int)($_POST['emp_id'] ?? 0);
            $newPin = trim($_POST['new_pin'] ?? '');
            if ($id) {
                if (empty($newPin)) {
                    $newPin = generateUniquePin();
                }
                if (!preg_match('/^\d{4}$/', $newPin)) {
                    $message = 'PIN يجب أن يكون 4 أرقام';
                    $msgType = 'error';
                } else {
                    try {
                        db()->prepare("UPDATE employees SET pin=?, pin_changed_at=NOW() WHERE id=?")->execute([$newPin, $id]);
                        auditLog('change_pin', "تغيير PIN للموظف ID={$id}", $id);
                        $message = "تم تغيير PIN إلى: {$newPin}";
                        $msgType = 'success';
                    } catch (PDOException $e) {
                        $message = 'PIN مكرر، جرب رقماً مختلفاً';
                        $msgType = 'error';
                    }
                }
            }
        }

        // --- توليد PIN تلقائي لجميع الموظفين ---
        if ($action === 'auto_generate_pins') {
            $emps = db()->query("SELECT id FROM employees WHERE deleted_at IS NULL")->fetchAll();
            $count = 0;
            foreach ($emps as $emp) {
                $pin = generateUniquePin();
                db()->prepare("UPDATE employees SET pin=?, pin_changed_at=NOW() WHERE id=?")->execute([$pin, $emp['id']]);
                $count++;
            }
            auditLog('auto_generate_pins', "توليد PIN تلقائي لـ {$count} موظف");
            $message = "تم توليد PIN جديد لـ {$count} موظف";
            $msgType = 'success';
        }

        // --- توليد PIN من رقم الجوال ---
        if ($action === 'generate_pin_from_phone') {
            $emps = db()->query("SELECT id, phone FROM employees WHERE deleted_at IS NULL")->fetchAll();
            $usedPins = [];
            $count = 0;
            foreach ($emps as $emp) {
                $phone = preg_replace('/[^0-9]/', '', $emp['phone'] ?? '');
                $pin = '';
                if ($phone && strlen($phone) >= 4) {
                    $pin = substr($phone, -4);
                }
                // إذا كان الـ PIN مستخدمًا بالفعل، أو غير صالح، نولّد عشوائي
                if (!$pin || isset($usedPins[$pin]) || db()->prepare("SELECT id FROM employees WHERE pin = ? AND id != ?")->execute([$pin, $emp['id']]) && db()->prepare("SELECT id FROM employees WHERE pin = ? AND id != ?")->fetch()) {
                    // توليد PIN عشوائي غير مستخدم
                    do {
                        $pin = generateUniquePin();
                    } while (isset($usedPins[$pin]) || db()->prepare("SELECT id FROM employees WHERE pin = ? AND id != ?")->execute([$pin, $emp['id']]) && db()->prepare("SELECT id FROM employees WHERE pin = ? AND id != ?")->fetch());
                }
                $usedPins[$pin] = true;
                db()->prepare("UPDATE employees SET pin=?, pin_changed_at=NOW() WHERE id=?")->execute([$pin, $emp['id']]);
                $count++;
            }
            auditLog('generate_pin_from_phone', "توليد PIN من الجوال لـ {$count} موظف");
            $message = "تم تعيين آخر 4 أرقام من الجوال كـ PIN لـ {$count} موظف (مع معالجة التكرار تلقائياً)";
            $msgType = 'success';
        }

        // --- إعادة تعيين بصمة الجهاز ---
        if ($action === 'reset_device') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET device_fingerprint=NULL, device_registered_at=NULL, device_bind_mode=0 WHERE id=?")->execute([$id]);
                $message = "تم إعادة تعيين الجهاز — الرابط الآن حر بدون ربط";
                $msgType = 'success';
            }
        }

        // --- تفعيل ربط صارم (يربط عند الدخول التالي + يمنع الأجهزة المختلفة) ---
        if ($action === 'enable_bind') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET device_bind_mode=1 WHERE id=?")->execute([$id]);
                auditLog('enable_bind', "تفعيل ربط صارم للموظف ID={$id}", $id);
                $message = "تم تفعيل الربط الصارم — سيُمنع أي جهاز مختلف";
                $msgType = 'success';
            }
        }

        // --- تفعيل ربط مراقبة (يربط لكن لا يمنع — يسجل التلاعب بصمت) ---
        if ($action === 'enable_silent_bind') {
            $id = (int)($_POST['emp_id'] ?? 0);
            if ($id) {
                db()->prepare("UPDATE employees SET device_bind_mode=2 WHERE id=?")->execute([$id]);
                auditLog('enable_silent_bind', "تفعيل ربط مراقبة للموظف ID={$id}", $id);
                $message = "تم تفعيل ربط المراقبة — سيُسجّل التلاعب بصمت دون منع الموظف";
                $msgType = 'success';
            }
        }

        // --- فك ربط جميع الأجهزة ---
        if ($action === 'reset_all_devices') {
            $result = db()->exec("UPDATE employees SET device_fingerprint=NULL, device_registered_at=NULL, device_bind_mode=0 WHERE deleted_at IS NULL");
            auditLog('reset_all_devices', "فك ربط جميع الأجهزة — {$result} موظف");
            $message = "تم فك ربط جميع الأجهزة — {$result} موظف";
            $msgType = 'success';
        }

        // --- تفعيل الربط الصارم لجميع الموظفين عند الدخول القادم ---
        if ($action === 'enable_bind_all') {
            $result = db()->exec("UPDATE employees SET device_bind_mode=1 WHERE is_active=1 AND deleted_at IS NULL AND device_fingerprint IS NULL");
            auditLog('enable_bind_all', "تفعيل ربط صارم لجميع الموظفين — {$result} موظف");
            $message = "تم تفعيل الربط الصارم للجميع — {$result} موظف";
            $msgType = 'success';
        }

        // --- تفعيل ربط المراقبة لجميع الموظفين ---
        if ($action === 'enable_silent_bind_all') {
            $result = db()->exec("UPDATE employees SET device_bind_mode=2 WHERE is_active=1 AND deleted_at IS NULL AND device_fingerprint IS NULL");
            auditLog('enable_silent_bind_all', "تفعيل ربط مراقبة لجميع الموظفين — {$result} موظف");
            $message = "تم تفعيل ربط المراقبة للجميع — {$result} موظف (يُسجّل التلاعب بصمت)";
            $msgType = 'success';
        }
    }
    header('Location: employees.php?msg=' . urlencode($message) . '&t=' . $msgType);
    exit;
}

// عرض الرسالة من redirect
if (!empty($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
    $msgType = $_GET['t'] ?? 'success';
}

// =================== جلب الموظفين ===================
$search = trim($_GET['search'] ?? '');
$filterBranch = (int)($_GET['branch'] ?? 0);

$whereClause = '';
$params      = [];
$conditions  = ['e.deleted_at IS NULL'];
if ($search) {
    $conditions[] = "(e.name LIKE ? OR e.job_title LIKE ? OR e.pin LIKE ?)";
    $params       = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($filterBranch) {
    $conditions[] = "e.branch_id = ?";
    $params[]     = $filterBranch;
}
if ($conditions) {
    $whereClause = "WHERE " . implode(' AND ', $conditions);
}

$totalStmt = db()->prepare("SELECT COUNT(*) FROM employees e $whereClause");
$totalStmt->execute($params);
$total     = (int)$totalStmt->fetchColumn();

$empStmt = db()->prepare("SELECT e.*, b.name AS branch_name FROM employees e LEFT JOIN branches b ON e.branch_id = b.id $whereClause ORDER BY COALESCE(b.name, 'zzz') ASC, e.name ASC");
$empStmt->execute($params);
$employees = $empStmt->fetchAll();

// جلب ملكية الأجهزة: لكل بصمة، من هو أكثر مستخدم لها
$deviceOwners = [];
try {
    $ownerStmt = db()->query("
        SELECT kd.fingerprint, kd.employee_id, e.name AS owner_name, kd.usage_count
        FROM known_devices kd
        JOIN employees e ON kd.employee_id = e.id
        WHERE kd.id IN (
            SELECT MIN(sub.id) FROM (
                SELECT kd2.id, kd2.fingerprint, kd2.usage_count
                FROM known_devices kd2
                INNER JOIN (
                    SELECT fingerprint, MAX(usage_count) AS max_count
                    FROM known_devices
                    GROUP BY fingerprint
                ) best ON kd2.fingerprint = best.fingerprint AND kd2.usage_count = best.max_count
            ) sub GROUP BY sub.fingerprint
        )
    ");
    foreach ($ownerStmt as $row) {
        $deviceOwners[$row['fingerprint']] = [
            'employee_id' => (int)$row['employee_id'],
            'name' => $row['owner_name'],
            'count' => (int)$row['usage_count'],
        ];
    }
} catch (Exception $e) { /* الجدول قد لا يكون موجوداً بعد */
}

// جلب الفروع لعرضها في القوائم
$allBranches = db()->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name")->fetchAll();

// ألوان مميزة لكل فرع
$_bColors = ['#E74C3C', '#3498DB', '#2ECC71', '#9B59B6', '#F39C12', '#1ABC9C', '#E67E22', '#34495E', '#16A085', '#C0392B'];
$branchColorMap = [];
foreach ($allBranches as $_i => $br) {
    $branchColorMap[$br['id']] = $_bColors[$_i % count($_bColors)];
}

$csrf = generateCsrfToken();

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= $message ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:18px;padding:14px">
    <div class="top-actions" style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;align-items:flex-end">
        <!-- بحث -->
        <form method="GET" class="filter-bar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex:1;min-width:200px">
            <input class="form-control" name="search" placeholder="بحث بالاسم أو الوظيفة أو PIN..."
                value="<?= htmlspecialchars($search) ?>" style="max-width:240px">
            <select class="form-control" name="branch" style="max-width:180px">
                <option value="0">— كل الفروع —</option>
                <?php foreach ($allBranches as $br): ?>
                    <option value="<?= $br['id'] ?>" <?= $filterBranch == $br['id'] ? 'selected' : '' ?>><?= htmlspecialchars($br['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary">بحث</button>
            <?php if ($search || $filterBranch): ?><a href="employees.php" class="btn btn-secondary">إلغاء</a><?php endif; ?>
        </form>
        <div class="top-actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-primary" onclick="openModal('addModal')">+ إضافة موظف</button>
            <a href="<?= SITE_URL ?>/employee/" target="_blank" class="btn btn-secondary" style="text-decoration:none">🔑 بوابة الحضور</a>
            <button class="btn" style="background:#25D366;color:#fff;border:none" onclick="openModal('waSenderModal');waInitSender()">📲 إرسال الروابط واتساب</button>
            <div class="dropdown-wrap" style="position:relative">
                <button class="btn btn-secondary" onclick="toggleBulkMenu(this)" type="button">
                    ⚙️ إجراءات جماعية ▾
                </button>
                <div class="dropdown-menu">
                    <form method="POST" onsubmit="return confirm('فك ربط جميع الأجهزة؟')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="reset_all_devices">
                        <button type="submit" class="dropdown-item" style="color:var(--red)">
                            <?= svgIcon('lock', 16) ?> فك جميع الأجهزة
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('تفعيل الربط الصارم للجميع؟ سيُمنع أي جهاز مختلف.')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="enable_bind_all">
                        <button type="submit" class="dropdown-item" style="color:var(--red)">
                            🔒 ربط صارم للجميع
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('تفعيل ربط المراقبة للجميع؟ يُسجّل التلاعب بصمت دون منع.')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="enable_silent_bind_all">
                        <button type="submit" class="dropdown-item" style="color:var(--orange,#F59E0B)">
                            👁️ ربط مراقبة للجميع
                        </button>
                    </form>
                    <div style="border-top:1px solid var(--border);margin:4px 0"></div>
                    <form method="POST" onsubmit="return confirm('سيتم توليد أكواد PIN جديدة لجميع الموظفين وحذف القديمة. متأكد؟')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="auto_generate_pins">
                        <button type="submit" class="dropdown-item" style="color:var(--green)">
                            🔑 توليد PIN تلقائي
                        </button>
                    </form>
                    <form method="POST" onsubmit="return confirm('سيتم تعيين آخر 4 أرقام من الجوال كـ PIN لكل موظف. إذا تكرر الرقم سيتم توليد PIN عشوائي. متأكد؟')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="generate_pin_from_phone">
                        <button type="submit" class="dropdown-item" style="color:var(--blue)">
                            📱 إنشاء PIN من الجوال
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title"><span class="card-title-bar"></span> قائمة الموظفين (<?= $total ?>)</span>
        <span class="badge badge-blue">جميع الموظفين</span>
    </div>
    <div style="overflow-x:auto">
        <table class="emp-table">
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>الاسم</th>
                    <th>الوظيفة</th>
                    <th>الفرع</th>
                    <th>PIN</th>
                    <th>الحالة</th>
                    <th>الجهاز</th>
                    <th style="width:60px">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php $lastBranchName = null;
                $seq = 0;
                foreach ($employees as $i => $emp):
                    $curBranch = $emp['branch_name'] ?? 'بدون فرع';
                    $rowColor  = $branchColorMap[$emp['branch_id'] ?? 0] ?? '#94A3B8';
                    if ($curBranch !== $lastBranchName):
                        $lastBranchName = $curBranch;
                        // حساب عدد موظفي هذا الفرع
                        $brCount = 0;
                        foreach ($employees as $_e) {
                            if (($_e['branch_name'] ?? 'بدون فرع') === $curBranch) $brCount++;
                        }
                ?>
                        <tr class="branch-separator">
                            <td colspan="8" style="background:<?= $rowColor ?>12;border-right:4px solid <?= $rowColor ?>;padding:6px 14px;font-weight:700;font-size:.85rem;color:<?= $rowColor ?>">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $rowColor ?>;margin-left:5px;vertical-align:middle"></span>
                                <?= htmlspecialchars($curBranch) ?>
                                <span style="font-size:.7rem;font-weight:400;color:var(--text3);margin-right:4px">(<?= $brCount ?>)</span>

                            </td>
                        </tr>
                    <?php endif;
                    $seq++; ?>
                    <tr style="border-right:3px solid <?= $rowColor ?>">
                        <td style="color:var(--text3)"><?= $seq ?></td>
                        <td>
                            <strong><?= htmlspecialchars($emp['name']) ?></strong>
                            <?php if ($emp['phone']): ?>
                                <br><small style="color:var(--text3)"><?= htmlspecialchars($emp['phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($emp['job_title']) ?></td>
                        <td style="font-size:.8rem;font-weight:600;color:<?= $rowColor ?>"><?= htmlspecialchars($emp['branch_name'] ?? '—') ?></td>
                        <td style="font-family:monospace;font-size:.9rem;font-weight:700;letter-spacing:2px;text-align:center"><?= htmlspecialchars($emp['pin'] ?? '—') ?></td>
                        <td>
                            <?php if ($emp['is_active']): ?>
                                <span class="badge badge-green">مفعّل</span>
                            <?php else: ?>
                                <span class="badge badge-red">معطّل</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <?php
                            $bm = (int)($emp['device_bind_mode'] ?? 0);
                            $fp = $emp['device_fingerprint'] ?? '';
                            $devOwner = ($fp && isset($deviceOwners[$fp])) ? $deviceOwners[$fp] : null;
                            $ownerLabel = '';
                            if ($devOwner) {
                                if ($devOwner['employee_id'] === (int)$emp['id']) {
                                    $ownerLabel = 'جهازه';
                                } else {
                                    $ownerLabel = 'جهاز ' . $devOwner['name'];
                                }
                            }
                            if (!empty($fp) && $bm === 1): ?>
                                <span title="ربط صارم — <?= $emp['device_registered_at'] ? date('Y-m-d', strtotime($emp['device_registered_at'])) : '' ?>" style="color:var(--red);cursor:default"><?= svgIcon('lock', 18) ?></span>
                            <?php elseif (!empty($fp) && $bm === 2): ?>
                                <span title="ربط مراقبة — <?= $emp['device_registered_at'] ? date('Y-m-d', strtotime($emp['device_registered_at'])) : '' ?>" style="color:var(--orange,#F59E0B);cursor:default">👁️</span>
                            <?php elseif (!empty($fp)): ?>
                                <span title="مربوط — <?= $emp['device_registered_at'] ? date('Y-m-d', strtotime($emp['device_registered_at'])) : '' ?>" style="color:var(--green);cursor:default"><?= svgIcon('lock', 18) ?></span>
                            <?php elseif ($bm === 1): ?>
                                <span class="badge badge-yellow" style="font-size:.65rem" title="ينتظر ربط صارم">🔒 ينتظر</span>
                            <?php elseif ($bm === 2): ?>
                                <span class="badge badge-yellow" style="font-size:.65rem" title="ينتظر ربط مراقبة">👁️ ينتظر</span>
                            <?php else: ?>
                                <span class="badge badge-blue" style="font-size:.65rem" title="حر — لا يحتاج ربط جهاز">🔓 حر</span>
                            <?php endif; ?>
                            <?php if ($ownerLabel): ?>
                                <div style="font-size:.62rem;color:<?= ($devOwner && $devOwner['employee_id'] !== (int)$emp['id']) ? 'var(--orange,#F59E0B)' : 'var(--text3)' ?>;margin-top:2px;line-height:1.1" title="استخدام: <?= $devOwner['count'] ?? 0 ?> مرة">📱 <?= $ownerLabel ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;align-items:center">
                            <?php
                              $waPhone = preg_replace('/[^0-9]/', '', $emp['phone'] ?? '');
                              if ($waPhone && substr($waPhone, 0, 1) === '0') $waPhone = '966' . substr($waPhone, 1);
                              elseif ($waPhone && substr($waPhone, 0, 3) !== '966') $waPhone = '966' . $waPhone;
                              $waGateway = SITE_URL . '/employee/';
                              $waPin = $emp['pin'] ?? '';
                              $waMsg = urlencode("مرحباً {$emp['name']}\n\nرابط تسجيل الحضور:\n{$waGateway}\n\nرمز الدخول (PIN): {$waPin}\n\nافتح الرابط وأدخل الرمز للتسجيل.");
                            ?>
                            <?php if ($waPhone): ?>
                              <a href="https://wa.me/<?= $waPhone ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener" class="btn btn-sm" style="background:#25D366;color:#fff;padding:4px 6px;border-radius:6px;font-size:.7rem;line-height:1;text-decoration:none;white-space:nowrap" title="إرسال عبر واتساب">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="#fff" style="vertical-align:middle"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                              </a>
                            <?php endif; ?>
                            <div class="dropdown-wrap">
                                <button class="btn btn-secondary btn-sm" onclick="toggleEmpMenu(this)" type="button">⚙️ ▾</button>
                                <div class="dropdown-menu emp-actions-menu">
                                    <!-- بروفايل -->
                                    <a href="employee-profile.php?id=<?= (int)$emp['id'] ?>" class="dropdown-item">👤 بروفايل الموظف</a>
                                    <!-- تعديل -->
                                    <button type="button" class="dropdown-item" onclick='this.closest(".dropdown-menu").classList.remove("show");openEditModal(<?= json_encode($emp, JSON_UNESCAPED_UNICODE) ?>)'><?= svgIcon('settings', 14) ?> تعديل البيانات</button>
                                    <!-- تغيير PIN -->
                                    <button type="button" class="dropdown-item" onclick='this.closest(".dropdown-menu").classList.remove("show");openChangePinModal(<?= (int)$emp["id"] ?>, <?= json_encode($emp["name"], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($emp["pin"] ?? "", JSON_UNESCAPED_UNICODE) ?>)'><?= svgIcon('key', 14) ?> تغيير PIN</button>
                                    <!-- تفعيل/تعطيل -->
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                        <?php if ($emp['is_active']): ?>
                                            <button type="submit" class="dropdown-item" style="color:var(--red)"><?= svgIcon('absent', 14) ?> تعطيل</button>
                                        <?php else: ?>
                                            <button type="submit" class="dropdown-item" style="color:var(--green)"><?= svgIcon('checkin', 14) ?> تفعيل</button>
                                        <?php endif; ?>
                                    </form>
                                    <!-- جهاز -->
                                    <?php if (!empty($emp['device_fingerprint'])): ?>
                                        <form method="POST" onsubmit="return confirm('فك ربط الجهاز؟')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="reset_device">
                                            <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="dropdown-item"><?= svgIcon('lock', 14) ?> فك ربط الجهاز</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" onsubmit="return confirm('ربط صارم: سيُمنع أي جهاز مختلف من الدخول')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="enable_bind">
                                            <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="dropdown-item" style="color:var(--red)">🔒 ربط صارم</button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('ربط مراقبة: يُسمح بالدخول من أي جهاز لكن يُسجّل التلاعب بصمت')">
                                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="enable_silent_bind">
                                            <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="dropdown-item" style="color:var(--orange,#F59E0B)">👁️ ربط مراقبة</button>
                                        </form>
                                    <?php endif; ?>
                                    <div style="border-top:1px solid var(--border);margin:4px 0"></div>
                                    <!-- أرشفة -->
                                    <form method="POST" onsubmit="return confirm('أرشفة الموظف؟')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                        <button type="submit" class="dropdown-item" style="color:var(--red)"><?= svgIcon('absent', 14) ?> أرشفة الموظف</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:30px;color:var(--text3)">لا توجد نتائج</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<!-- =================== Modal إضافة موظف =================== -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-title"><?= svgIcon('employees', 20) ?> إضافة موظف جديد</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">الاسم الكامل *</label>
                    <input class="form-control" name="name" required placeholder="محمد أحمد ...">
                </div>
                <div class="form-group">
                    <label class="form-label">المسمى الوظيفي *</label>
                    <input class="form-control" name="job_title" required placeholder="مهندس">
                </div>
                <div class="form-group">
                    <label class="form-label">PIN (رقم سري)</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input class="form-control" name="pin" placeholder="تلقائي" style="direction:ltr;max-width:140px" maxlength="4" pattern="\d{4}">
                        <small style="color:var(--text3);white-space:nowrap">اتركه فارغاً للتوليد التلقائي</small>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">الفرع</label>
                    <select class="form-control" name="branch_id">
                        <option value="">— بدون فرع —</option>
                        <?php foreach ($allBranches as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الواتساب (اختياري)</label>
                    <input class="form-control" name="phone" placeholder="966501234567" style="direction:ltr">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ →</button>
            </div>
        </form>
    </div>
</div>

<!-- =================== Modal تعديل موظف =================== -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-title"><?= svgIcon('settings', 20) ?> تعديل بيانات الموظف</div>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="emp_id" id="editId">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">الاسم الكامل *</label>
                    <input class="form-control" name="name" id="editName" required>
                </div>
                <div class="form-group">
                    <label class="form-label">المسمى الوظيفي *</label>
                    <input class="form-control" name="job_title" id="editJob" required>
                </div>
                <div class="form-group">
                    <label class="form-label">الفرع</label>
                    <select class="form-control" name="branch_id" id="editBranch">
                        <option value="">— بدون فرع —</option>
                        <?php foreach ($allBranches as $br): ?>
                            <option value="<?= $br['id'] ?>"><?= htmlspecialchars($br['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">رقم الواتساب</label>
                    <input class="form-control" name="phone" id="editPhone" style="direction:ltr">
                </div>
                <div class="form-group">
                    <label class="form-label">الحالة</label>
                    <select class="form-control" name="is_active" id="editActive">
                        <option value="1">مفعّل</option>
                        <option value="0">معطّل</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ →</button>
            </div>
        </form>
    </div>
</div>

<!-- =================== Modal تغيير PIN =================== -->
<div class="modal-overlay" id="changePinModal">
    <div class="modal" style="max-width:420px">
        <div class="modal-title"><?= svgIcon('key', 20) ?> تغيير PIN</div>
        <form method="POST" id="changePinForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="change_pin">
            <input type="hidden" name="emp_id" id="cpEmpId">
            <div style="margin-bottom:16px">
                <div style="font-weight:700;margin-bottom:4px" id="cpEmpName"></div>
                <div style="font-size:.85rem;color:var(--text3)">PIN الحالي: <code id="cpCurrentPin" style="font-size:1rem;font-weight:700;letter-spacing:2px"></code></div>
            </div>
            <div class="form-group">
                <label class="form-label">PIN الجديد (4 أرقام)</label>
                <div style="display:flex;gap:10px;align-items:center">
                    <input class="form-control" name="new_pin" id="cpNewPin" placeholder="اتركه فارغاً للتوليد التلقائي" style="direction:ltr;font-size:1.1rem;letter-spacing:2px;font-weight:700;max-width:200px" maxlength="4" pattern="\d{4}">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('cpNewPin').value=String(Math.floor(1000+Math.random()*9000))" style="white-space:nowrap">🎲 عشوائي</button>
                </div>
                <small style="color:var(--text3)">اتركه فارغاً لتوليد رقم فريد تلقائياً</small>
            </div>
            <div style="background:#FFF7ED;border:1px solid #FDBA74;border-radius:8px;padding:10px 14px;margin:12px 0;font-size:.82rem;color:#92400E">
                ⚠️ تغيير PIN سيطلب من الموظف إدخال الرقم الجديد عند الدخول التالي
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('changePinModal')">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ →</button>
            </div>
        </form>
    </div>
</div>

<!-- =================== Modal إرسال واتساب =================== -->
<?php
// تجهيز بيانات الموظفين لـ JS
$waEmployees = [];
foreach ($employees as $emp) {
    if (!$emp['is_active'] || empty($emp['phone'])) continue;
    $ph = preg_replace('/[^0-9]/', '', $emp['phone']);
    if ($ph && $ph[0] === '0') $ph = '966' . substr($ph, 1);
    elseif ($ph && substr($ph, 0, 3) !== '966') $ph = '966' . $ph;
    if (strlen($ph) < 9) continue;
    $waEmployees[] = [
        'id'     => (int)$emp['id'],
        'name'   => $emp['name'],
        'phone'  => $ph,
        'pin'    => $emp['pin'] ?? '',
        'branch' => $emp['branch_name'] ?? 'بدون فرع',
    ];
}
?>
<div class="modal-overlay" id="waSenderModal">
    <div class="modal" style="max-width:640px;max-height:90vh;display:flex;flex-direction:column">
        <div class="modal-title" style="display:flex;justify-content:space-between;align-items:center">
            <span>📲 إرسال روابط الحضور عبر واتساب</span>
            <button type="button" onclick="closeModal('waSenderModal')" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text3)">✕</button>
        </div>

        <div style="background:linear-gradient(135deg,#FFFBEB,#FEF3C7);border:1px solid #FCD34D;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.78rem;color:#92400E;line-height:1.6">
            <strong>💡 طريقة العمل:</strong> يفتح النظام نافذة واتساب ويب واحدة ويعرض رسالة كل موظف بالتسلسل.
            اضغط «إرسال» في واتساب ثم اضغط «التالي ←» هنا للانتقال للموظف التالي.
            <br><strong>⏱️ انتظر 5 ثوانٍ على الأقل بين كل إرسال</strong> لتجنّب حظر واتساب.
        </div>

        <!-- شريط التقدم -->
        <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;font-size:.78rem;color:var(--text2);margin-bottom:4px">
                <span id="waProgressText">0 / <?= count($waEmployees) ?></span>
                <span id="waProgressPct">0%</span>
            </div>
            <div style="background:var(--surface2);border-radius:6px;height:8px;overflow:hidden">
                <div id="waProgressBar" style="height:100%;background:linear-gradient(90deg,#25D366,#128C7E);width:0%;transition:width .3s;border-radius:6px"></div>
            </div>
        </div>

        <!-- الموظف الحالي -->
        <div id="waCurrentEmp" style="background:var(--surface);border-radius:10px;padding:16px;margin-bottom:12px;border:1.5px solid var(--border);display:none">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <div>
                    <strong id="waEmpName" style="font-size:1rem"></strong>
                    <span id="waEmpBranch" style="font-size:.75rem;color:var(--text3);margin-right:8px"></span>
                </div>
                <span id="waEmpPhone" style="font-family:monospace;font-size:.85rem;color:var(--text2);direction:ltr"></span>
            </div>
            <div style="background:var(--bg);border-radius:8px;padding:10px;font-size:.82rem;line-height:1.7;color:var(--text2);white-space:pre-wrap;direction:rtl" id="waMessagePreview"></div>
        </div>

        <!-- قائمة الانتظار -->
        <div style="flex:1;overflow-y:auto;margin-bottom:12px;max-height:220px" id="waQueueContainer">
            <div style="font-size:.78rem;color:var(--text3);margin-bottom:6px;font-weight:600">قائمة الانتظار:</div>
            <div id="waQueueList" style="display:flex;flex-direction:column;gap:3px"></div>
        </div>

        <!-- أزرار التحكم -->
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px">
            <button class="btn" style="background:#25D366;color:#fff;border:none;padding:8px 24px;font-size:.9rem" id="waStartBtn" onclick="waStart()">▶️ ابدأ الإرسال</button>
            <button class="btn btn-primary" id="waNextBtn" onclick="waNext()" style="display:none;padding:8px 24px;font-size:.9rem" disabled>التالي ← <span id="waCountdown"></span></button>
            <button class="btn btn-secondary" id="waSkipBtn" onclick="waSkip()" style="display:none;padding:8px 16px;font-size:.85rem">تخطي</button>
            <button class="btn btn-secondary" onclick="closeModal('waSenderModal')" id="waDoneBtn" style="display:none;padding:8px 24px">✅ تم</button>
        </div>
    </div>
</div>

<script>
// =================== واتساب Sender ===================
const waEmployees = <?= json_encode($waEmployees, JSON_UNESCAPED_UNICODE) ?>;
const waGateway   = <?= json_encode(SITE_URL . '/employee/') ?>;
let waIndex = 0;
let waWindow = null;
let waCountdownTimer = null;
const WA_COOLDOWN = 6; // ثوانٍ الحد الأدنى بين الرسائل

function waBuildMessage(emp) {
    return "مرحباً " + emp.name + "\n\nرابط تسجيل الحضور:\n" + waGateway + "\n\nرمز الدخول (PIN): " + emp.pin + "\n\nافتح الرابط وأدخل الرمز للتسجيل.";
}

function waBuildUrl(emp) {
    return "https://wa.me/" + emp.phone + "?text=" + encodeURIComponent(waBuildMessage(emp));
}

function waRenderQueue() {
    const list = document.getElementById('waQueueList');
    list.innerHTML = '';
    waEmployees.forEach(function(emp, i) {
        const div = document.createElement('div');
        div.id = 'waQ_' + emp.id;
        div.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:5px 10px;border-radius:6px;font-size:.8rem;';
        if (i < waIndex) {
            div.style.background = '#D1FAE520';
            div.style.color = '#16A34A';
            div.innerHTML = '<span>✅ ' + emp.name + '</span><span style="font-size:.7rem;color:var(--text3)">' + emp.branch + '</span>';
        } else if (i === waIndex) {
            div.style.background = '#DBEAFE';
            div.style.fontWeight = '700';
            div.innerHTML = '<span>➤ ' + emp.name + '</span><span style="font-size:.7rem">' + emp.branch + '</span>';
        } else {
            div.style.color = 'var(--text3)';
            div.innerHTML = '<span>○ ' + emp.name + '</span><span style="font-size:.7rem">' + emp.branch + '</span>';
        }
        list.appendChild(div);
    });
}

function waUpdateProgress() {
    const total = waEmployees.length;
    const pct = total > 0 ? Math.round((waIndex / total) * 100) : 0;
    document.getElementById('waProgressText').textContent = waIndex + ' / ' + total;
    document.getElementById('waProgressPct').textContent = pct + '%';
    document.getElementById('waProgressBar').style.width = pct + '%';
}

function waShowCurrent() {
    if (waIndex >= waEmployees.length) {
        document.getElementById('waCurrentEmp').style.display = 'none';
        document.getElementById('waNextBtn').style.display = 'none';
        document.getElementById('waSkipBtn').style.display = 'none';
        document.getElementById('waStartBtn').style.display = 'none';
        document.getElementById('waDoneBtn').style.display = '';
        waUpdateProgress();
        waRenderQueue();
        return;
    }
    const emp = waEmployees[waIndex];
    document.getElementById('waCurrentEmp').style.display = '';
    document.getElementById('waEmpName').textContent = emp.name;
    document.getElementById('waEmpBranch').textContent = '(' + emp.branch + ')';
    document.getElementById('waEmpPhone').textContent = '+' + emp.phone;
    document.getElementById('waMessagePreview').textContent = waBuildMessage(emp);
    waUpdateProgress();
    waRenderQueue();
    // scroll current into view
    const qEl = document.getElementById('waQ_' + emp.id);
    if (qEl) qEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function waOpenLink(emp) {
    const url = waBuildUrl(emp);
    if (waWindow && !waWindow.closed) {
        waWindow.location.href = url;
        waWindow.focus();
    } else {
        waWindow = window.open(url, 'wa_sender');
    }
}

function waStart() {
    waIndex = 0;
    document.getElementById('waStartBtn').style.display = 'none';
    document.getElementById('waNextBtn').style.display = '';
    document.getElementById('waSkipBtn').style.display = '';
    waShowCurrent();
    // فتح أول رابط
    waOpenLink(waEmployees[0]);
    waStartCooldown();
}

function waStartCooldown() {
    const btn = document.getElementById('waNextBtn');
    const span = document.getElementById('waCountdown');
    btn.disabled = true;
    let sec = WA_COOLDOWN;
    span.textContent = '(' + sec + ')';
    if (waCountdownTimer) clearInterval(waCountdownTimer);
    waCountdownTimer = setInterval(function() {
        sec--;
        if (sec <= 0) {
            clearInterval(waCountdownTimer);
            btn.disabled = false;
            span.textContent = '';
        } else {
            span.textContent = '(' + sec + ')';
        }
    }, 1000);
}

function waNext() {
    waIndex++;
    if (waIndex < waEmployees.length) {
        waShowCurrent();
        waOpenLink(waEmployees[waIndex]);
        waStartCooldown();
    } else {
        waShowCurrent(); // will show done state
    }
}

function waSkip() {
    waIndex++;
    if (waIndex < waEmployees.length) {
        waShowCurrent();
        waOpenLink(waEmployees[waIndex]);
        waStartCooldown();
    } else {
        waShowCurrent();
    }
}

// تهيئة القائمة عند فتح المودال
function waInitSender() {
    waIndex = 0;
    document.getElementById('waStartBtn').style.display = '';
    document.getElementById('waNextBtn').style.display = 'none';
    document.getElementById('waSkipBtn').style.display = 'none';
    document.getElementById('waDoneBtn').style.display = 'none';
    waUpdateProgress();
    waRenderQueue();
    document.getElementById('waCurrentEmp').style.display = 'none';
}
</script>

<script>
    function openModal(id) {
        document.getElementById(id).classList.add('show');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    function openEditModal(emp) {
        document.getElementById('editId').value = emp.id;
        document.getElementById('editName').value = emp.name;
        document.getElementById('editJob').value = emp.job_title;
        document.getElementById('editPhone').value = emp.phone || '';
        document.getElementById('editBranch').value = emp.branch_id || '';
        document.getElementById('editActive').value = emp.is_active;
        openModal('editModal');
    }

    function openChangePinModal(empId, empName, currentPin) {
        document.getElementById('cpEmpId').value = empId;
        document.getElementById('cpEmpName').textContent = empName;
        document.getElementById('cpCurrentPin').textContent = currentPin || '—';
        document.getElementById('cpNewPin').value = '';
        openModal('changePinModal');
    }



    // إغلاق modal عند الضغط خارجه
    document.querySelectorAll('.modal-overlay').forEach(o => {
        o.addEventListener('click', e => {
            if (e.target === o) o.classList.remove('show');
        });
    });

    // إغلاق dropdown عند الضغط خارجه
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-wrap')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
        }
    });

    // فتح/إغلاق قائمة الإجراءات مع إغلاق أي قائمة أخرى مفتوحة
    function toggleEmpMenu(btn) {
        const menu = btn.nextElementSibling;
        const wasOpen = menu.classList.contains('show');
        // أغلق جميع القوائم أولاً
        closeAllMenus();
        if (!wasOpen) {
            menu.classList.add('show');
            const ov = document.getElementById('dropdownOverlay');
            if (window.innerWidth <= 768) {
                // موبايل: bottom-sheet (CSS يتكفل)
                if (ov) ov.classList.add('show');
            } else {
                // ديسكتوب: fixed position لتجنب قص overflow
                const r = btn.getBoundingClientRect();
                menu.style.position = 'fixed';
                menu.style.top  = (r.bottom + 4) + 'px';
                menu.style.right = 'auto';
                menu.style.left = Math.max(8, r.right - 220) + 'px';
                menu.style.zIndex = '9999';
            }
        }
    }
    function closeAllMenus() {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
            m.classList.remove('show');
            m.style.position = '';
            m.style.top = '';
            m.style.left = '';
            m.style.right = '';
            m.style.zIndex = '';
        });
        const ov = document.getElementById('dropdownOverlay');
        if (ov) ov.classList.remove('show');
    }
    document.getElementById('dropdownOverlay')?.addEventListener('click', closeAllMenus);
    function toggleBulkMenu(btn) {
        const menu = btn.nextElementSibling;
        const wasOpen = menu.classList.contains('show');
        closeAllMenus();
        if (!wasOpen) {
            menu.classList.add('show');
            const ov = document.getElementById('dropdownOverlay');
            if (ov && window.innerWidth <= 768) ov.classList.add('show');
        }
    }
    // إغلاق القوائم عند النقر خارجها (desktop)
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-wrap')) closeAllMenus();
    });

</script>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>

</div>
</div>
</body>

</html>