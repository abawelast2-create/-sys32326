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
            <button class="btn btn-secondary" onclick="openModal('qrModal');generatePortalQR()" style="gap:6px">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M3 11h8V3H3v8zm2-6h4v4H5V5zm8-2v8h8V3h-8zm6 6h-4V5h4v4zM3 21h8v-8H3v8zm2-6h4v4H5v-4zm13-2h-2v3h-3v2h3v3h2v-3h3v-2h-3v-3zm-5 7v-2H8v2h5zm5 0h2v-2h-2v2z"/></svg>
                باركود البوابة
            </button>
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
<!-- =================== Modal باركود بوابة الموظف =================== -->
<div class="modal-overlay" id="qrModal">
    <div class="modal" style="max-width:480px;padding:0;border-radius:16px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.3)">
        <div style="background:linear-gradient(135deg,#F97316,#EA580C);padding:20px 24px;display:flex;justify-content:space-between;align-items:center">
            <div style="color:#fff;font-weight:700;font-size:1.05rem;display:flex;align-items:center;gap:8px">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="#fff"><path d="M3 11h8V3H3v8zm2-6h4v4H5V5zm8-2v8h8V3h-8zm6 6h-4V5h4v4zM3 21h8v-8H3v8zm2-6h4v4H5v-4zm13-2h-2v3h-3v2h3v3h2v-3h3v-2h-3v-3zm-5 7v-2H8v2h5zm5 0h2v-2h-2v2z"/></svg>
                باركود بوابة الحضور
            </div>
            <button type="button" onclick="closeModal('qrModal')" style="background:rgba(255,255,255,.2);border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;color:#fff;font-size:1.1rem;display:flex;align-items:center;justify-content:center">✕</button>
        </div>
        <div style="padding:28px;text-align:center;background:var(--surface)">
            <div id="qrCardPrint" style="display:inline-block;background:#fff;border-radius:16px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.08);border:2px solid #f3f4f6;max-width:340px;width:100%">
                <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:16px">
                    <img src="<?= SITE_URL ?>/assets/images/loogo.png" alt="Logo" style="width:36px;height:36px;border-radius:8px;object-fit:cover">
                    <div style="font-weight:700;font-size:1rem;color:#1f2937"><?= SITE_NAME ?></div>
                </div>
                <div id="qrContainer" style="display:flex;justify-content:center;margin:8px 0 16px"></div>
                <div style="font-size:.82rem;color:#6b7280;margin-bottom:6px;direction:ltr;word-break:break-all;font-family:monospace;background:#f9fafb;padding:8px 12px;border-radius:8px" id="qrUrlDisplay"></div>
                <div style="margin-top:12px;font-size:.78rem;color:#9ca3af;line-height:1.5">امسح الباركود بكاميرا الجوال للدخول إلى بوابة تسجيل الحضور</div>
            </div>
            <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <button class="btn btn-primary" onclick="downloadQR()" style="gap:6px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    تحميل الصورة
                </button>
                <button class="btn btn-secondary" onclick="printQR()" style="gap:6px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                    طباعة
                </button>
            </div>
        </div>
    </div>
</div>

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

<!-- QR Code Generator (qrcode-generator by kazuhikoarase, MIT) -->
<script>
var qrcode=function(){function r(r,o){var f=r,i=e[o],u=null,a=0,c=null,l=[],g={},s=function(r,e){u=function(r){for(var e=new Array(r),t=0;t<r;t++){e[t]=new Array(r);for(var n=0;n<r;n++)e[t][n]=null}return e}(a=4*f+17);p(0,0);p(a-7,0);p(0,a-7);S();w();B(r,e);f>=7&&T(r);null==c&&(c=v(f,i,l)),A(c,e)},p=function(r,e){for(var t=-1;t<=7;t++)if(!(r+t<=-1||a<=r+t))for(var n=-1;n<=7;n++)e+n<=-1||a<=e+n||(0<=t&&t<=6&&(0==n||6==n)||0<=n&&n<=6&&(0==t||6==t)||2<=t&&t<=4&&2<=n&&n<=4?u[r+t][e+n]=!0:u[r+t][e+n]=!1)},d=function(){for(var r=0,e=0,t=0;t<8;t++){s(t,!0);var n=h.getLostPoint(g);(0==t||r>n)&&(r=n,e=t)}return e},S=function(){for(var r=8;r<a-8;r++)null==u[r][6]&&(u[r][6]=r%2==0),null==u[6][r]&&(u[6][r]=r%2==0)},w=function(){for(var r=h.getPatternPosition(f),e=0;e<r.length;e++)for(var t=0;t<r.length;t++){var n=r[e],o=r[t];if(null==u[n][o])for(var i=-2;i<=2;i++)for(var a=-2;a<=2;a++)-2==i||2==i||-2==a||2==a||0==i&&0==a?u[n+i][o+a]=!0:u[n+i][o+a]=!1}},T=function(r){for(var e=h.getBCHTypeNumber(f),t=0;t<18;t++){var n=!r&&1==(e>>t&1);u[Math.floor(t/3)][t%3+a-8-3]=n,u[t%3+a-8-3][Math.floor(t/3)]=n}},B=function(r,e){for(var t=h.getBCHTypeInfo(i<<3|e),n=0;n<15;n++){var o=!r&&1==(t>>n&1);n<6?u[n][8]=o:n<8?u[n+1][8]=o:u[a-15+n][8]=o,n<8?u[8][a-n-1]=o:n<9?u[8][15-n-1+1]=o:u[8][15-n-1]=o}u[a-8][8]=!r},A=function(r,e){for(var t=-1,n=a-1,o=7,i=0,c=h.getMaskFunction(e),l=a-1;l>0;l-=2)for(6==l&&l--;;){for(var g=0;g<2;g++)if(null==u[n][l-g]){var s=!1;i<r.length&&(s=1==(r[i]>>>o&1)),c(n,l-g)&&(s=!s),u[n][l-g]=s,-1==--o&&(i++,o=7)}if((n+=t)<0||a<=n){n-=t,t=-t;break}}};return g.addData=function(r,e){var n=null;switch(e=e||"Byte"){case"Numeric":n=t(r);break;case"Alphanumeric":n=E(r);break;case"Byte":n=F(r);break;default:throw"mode:"+e}l.push(n),c=null},g.isDark=function(r,e){if(r<0||a<=r||e<0||a<=e)throw r+","+e;return u[r][e]},g.getModuleCount=function(){return a},g.make=function(){if(f<1){for(var r=1;r<40;r++){for(var t=e[i],n=C.getRSBlocks(r,t),o=0,a=0;a<n.length;a++)o+=n[a].dataCount;var u=new R;for(a=0;a<l.length;a++){var c=l[a];u.put(c.getMode(),4),u.put(c.getLength(),h.getLengthInBits(c.getMode(),r)),c.write(u)}if(u.getLengthInBits()<=8*o)break}f=r}s(!1,d())},g.createTableTag=function(r,e){r=r||2;var t="";t+='<table style="',t+=" border-width: 0px; border-style: none;",t+=" border-collapse: collapse;",t+=" padding: 0px; margin: "+(e=void 0===e?4*r:e)+"px;",t+='">',t+="<tbody>";for(var n=0;n<g.getModuleCount();n++){t+="<tr>";for(var o=0;o<g.getModuleCount();o++)t+='<td style="',t+=" border-width: 0px; border-style: none;",t+=" border-collapse: collapse;",t+=" padding: 0px; margin: 0px;",t+=" width: "+r+"px;",t+=" height: "+r+"px;",t+=" background-color: ",t+=g.isDark(n,o)?"#000000":"#ffffff",t+=";",t+='"/>';t+="</tr>"}return t+="</tbody>",t+="</table>"},g.createSvgTag=function(r,e,t){r=r||2,e=void 0===e?4*r:e,t=t||"#000000";var n=g.getModuleCount()*r+2*e,o="";o+='<svg version="1.1" xmlns="http://www.w3.org/2000/svg"',o+=' width="'+n+'"',o+=' height="'+n+'"',o+=' viewBox="0 0 '+n+" "+n+'"',o+=' style="shape-rendering:crispEdges">',o+='<rect width="100%" height="100%" fill="#ffffff"/>',o+="<g>";for(var i=0;i<g.getModuleCount();i++)for(var a=0;a<g.getModuleCount();a++)g.isDark(i,a)&&(o+='<rect x="'+(a*r+e)+'" y="'+(i*r+e)+'" width="'+r+'" height="'+r+'" fill="'+t+'"/>');return o+="</g>",o+="</svg>"},g.renderTo2dContext=function(r,e){e=e||2;for(var t=g.getModuleCount(),n=0;n<t;n++)for(var o=0;o<t;o++)r.fillStyle=g.isDark(n,o)?"#000000":"#ffffff",r.fillRect(o*e,n*e,e,e)},g}var e={L:1,M:0,Q:3,H:2};function t(r){this.mode=1,this.data=r}function E(r){this.mode=2,this.data=r}function F(r){this.mode=4,this.data=r,this.bytes=function(){for(var r=[],e=0;e<this.data.length;e++){var t=this.data.charCodeAt(e);if(t<128)r.push(t);else if(t<2048)r.push(192|t>>6),r.push(128|63&t);else if(t<55296||t>=57344)r.push(224|t>>12),r.push(128|t>>6&63),r.push(128|63&t);else{t=65536+((1023&t)<<10|1023&this.data.charCodeAt(++e)),r.push(240|t>>18),r.push(128|t>>12&63),r.push(128|t>>6&63),r.push(128|63&t)}}return r}.call(this)}t.prototype={getMode:function(){return this.mode},getLength:function(){return this.data.length},write:function(r){for(var e=this.data,t=0;t+2<e.length;){var n=parseInt(e.substring(t,t+3));r.put(n,10),t+=3}t<e.length&&(e.length-t==1?(n=parseInt(e.substring(t,t+1)),r.put(n,4)):e.length-t==2&&(n=parseInt(e.substring(t,t+2)),r.put(n,7)))}},E.prototype={getMode:function(){return this.mode},getLength:function(){return this.data.length},write:function(r){for(var e="0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:",t=0;t+1<this.data.length;)r.put(45*e.indexOf(this.data.charAt(t))+e.indexOf(this.data.charAt(t+1)),11),t+=2;t<this.data.length&&r.put(e.indexOf(this.data.charAt(t)),6)}},F.prototype={getMode:function(){return this.mode},getLength:function(){return this.bytes.length},write:function(r){for(var e=0;e<this.bytes.length;e++)r.put(this.bytes[e],8)}};var C={getRSBlocks:function(r,e){var t=function(r,e){switch(e){case 1:return n[4*(r-1)];case 0:return n[4*(r-1)+1];case 3:return n[4*(r-1)+2];case 2:return n[4*(r-1)+3];default:return}}(r,e);if(void 0===t)throw"bad rs block @ typeNumber:"+r+"/errorCorrectionLevel:"+e;for(var o=[],i=0;i<t.length;i+=3)for(var a=0;a<t[i];a++)o.push({totalCount:t[i+1],dataCount:t[i+2]});return o}},n=[[1,26,19],[1,26,16],[1,26,13],[1,26,9],[1,44,34],[1,44,28],[1,44,22],[1,44,16],[1,70,55],[1,70,44],[2,35,17],[2,35,13],[1,100,80],[2,50,32],[2,50,24],[4,25,9],[1,134,108],[2,67,43],[2,33,15,2,34,16],[2,33,11,2,34,12],[2,86,68],[4,43,27],[4,43,19],[4,43,15],[2,98,78],[4,49,31],[2,32,14,4,33,15],[4,39,13,1,40,14],[2,121,97],[2,60,38,2,61,39],[4,40,18,2,41,19],[4,40,14,2,41,15],[2,146,116],[3,58,36,2,59,37],[4,36,16,4,37,17],[4,36,12,4,37,13],[2,86,68,2,87,69],[4,69,43,1,70,44],[6,43,19,2,44,20],[6,43,15,2,44,16],[4,101,81],[1,80,50,4,81,51],[4,50,22,4,51,23],[3,36,12,8,37,13],[2,116,92,2,117,93],[6,58,36,2,59,37],[4,46,20,6,47,21],[7,42,14,4,43,15],[4,133,107],[8,59,37,1,60,38],[8,44,20,4,45,21],[12,33,11,4,34,12],[3,145,115,1,146,116],[4,64,40,5,65,41],[11,36,16,5,37,17],[11,36,12,5,37,13],[5,109,87,1,110,88],[5,65,41,5,66,42],[5,54,24,7,55,25],[11,36,12],[5,122,98,1,123,99],[7,73,45,3,74,46],[15,43,19,2,44,20],[3,45,15,13,46,16],[1,135,107,5,136,108],[10,74,46,1,75,47],[1,50,22,15,51,23],[2,42,14,17,43,15],[5,150,120,1,151,121],[9,69,43,4,70,44],[17,50,22,1,51,23],[2,42,14,19,43,15],[3,141,113,4,142,114],[3,70,44,11,71,45],[17,47,21,4,48,22],[9,39,13,16,40,14],[3,135,107,5,136,108],[3,67,41,13,68,42],[15,54,24,5,55,25],[15,43,15,10,44,16],[4,144,116,4,145,117],[17,68,42],[17,50,22,6,51,23],[19,46,16,6,47,17],[2,139,111,7,140,112],[17,74,46],[7,54,24,16,55,25],[34,37,13],[4,151,121,5,152,122],[4,75,47,14,76,48],[11,54,24,14,55,25],[16,45,15,14,46,16],[6,147,117,4,148,118],[6,73,45,14,74,46],[11,54,24,16,55,25],[30,46,16,2,47,17],[8,132,106,4,133,107],[8,75,47,13,76,48],[7,54,24,22,55,25],[22,45,15,13,46,16],[10,142,114,2,143,115],[19,74,46,4,75,47],[28,50,22,6,51,23],[33,46,16,4,47,17],[8,152,122,4,153,123],[22,73,45,3,74,46],[8,53,23,26,54,24],[12,45,15,28,46,16],[3,147,117,10,148,118],[3,73,45,23,74,46],[4,54,24,31,55,25],[11,45,15,31,46,16],[7,146,116,7,147,117],[21,73,45,7,74,46],[1,53,23,37,54,24],[19,45,15,26,46,16],[5,145,115,10,146,116],[19,75,47,10,76,48],[15,54,24,25,55,25],[23,45,15,25,46,16],[13,145,115,3,146,116],[2,74,46,29,75,47],[42,54,24,1,55,25],[23,45,15,28,46,16],[17,145,115],[10,74,46,23,75,47],[10,54,24,35,55,25],[19,45,15,35,46,16],[17,145,115,1,146,116],[14,74,46,21,75,47],[29,54,24,19,55,25],[11,45,15,46,46,16],[13,145,115,6,146,116],[14,74,46,23,75,47],[44,54,24,7,55,25],[59,46,16,1,47,17],[12,151,121,7,152,122],[12,75,47,26,76,48],[39,54,24,14,55,25],[22,45,15,41,46,16],[6,151,121,14,152,122],[6,75,47,34,76,48],[46,54,24,10,55,25],[2,45,15,64,46,16],[17,152,122,4,153,123],[29,74,46,14,75,47],[49,54,24,10,55,25],[24,45,15,46,46,16],[4,152,122,18,153,123],[13,74,46,32,75,47],[48,54,24,14,55,25],[42,45,15,32,46,16],[20,147,117,4,148,118],[40,75,47,7,76,48],[43,54,24,22,55,25],[10,45,15,67,46,16],[19,148,118,6,149,119],[18,75,47,31,76,48],[34,54,24,34,55,25],[20,45,15,61,46,16]];var h={getPatternPosition:function(r){return o[r-1]},getMaxLength:function(r,e,t){var n=a[4*(r-1)+(e={L:0,M:1,Q:2,H:3}[e])];if(void 0===n)throw"bad rs block @ typeNumber:"+r;return n[{Numeric:0,Alphanumeric:1,Byte:2}[t]]},getBCHTypeNumber:function(r){for(var e=r<<12;h.getBCHDigit(e)-h.getBCHDigit(7973)>=0;)e^=7973<<h.getBCHDigit(e)-h.getBCHDigit(7973);return r<<12|e},getBCHTypeInfo:function(r){for(var e=r<<10;h.getBCHDigit(e)-h.getBCHDigit(1335)>=0;)e^=1335<<h.getBCHDigit(e)-h.getBCHDigit(1335);return 21522^(r<<10|e)},getBCHDigit:function(r){for(var e=0;0!=r;)e++,r>>>=1;return e},getMaskFunction:function(r){switch(r){case 0:return function(r,e){return(r+e)%2==0};case 1:return function(r){return r%2==0};case 2:return function(r,e){return e%3==0};case 3:return function(r,e){return(r+e)%3==0};case 4:return function(r,e){return(Math.floor(r/2)+Math.floor(e/3))%2==0};case 5:return function(r,e){return r*e%2+r*e%3==0};case 6:return function(r,e){return(r*e%2+r*e%3)%2==0};case 7:return function(r,e){return(r*e%3+(r+e)%2)%2==0};default:throw"bad maskPattern:"+r}},getLengthInBits:function(r,e){return 1<=e&&e<10?1==r?10:2==r?9:4==r?8:void 0:e<27?1==r?12:2==r?11:4==r?16:void 0:e<41?1==r?14:2==r?13:4==r?16:void 0:void 0},getLostPoint:function(r){for(var e=r.getModuleCount(),t=0,n=0;n<e;n++)for(var o=0;o<e;o++){for(var i=0,a=r.isDark(n,o),u=-1;u<=1;u++)if(!(n+u<0||e<=n+u))for(var f=-1;f<=1;f++)o+f<0||e<=o+f||0==u&&0==f||a==r.isDark(n+u,o+f)&&i++;i>5&&(t+=3+i-5)}for(n=0;n<e-1;n++)for(o=0;o<e-1;o++){var c=0;r.isDark(n,o)&&c++,r.isDark(n+1,o)&&c++,r.isDark(n,o+1)&&c++,r.isDark(n+1,o+1)&&c++,0!=c&&4!=c||(t+=3)}for(n=0;n<e;n++)for(o=0;o<e-6;o++)r.isDark(n,o)&&!r.isDark(n,o+1)&&r.isDark(n,o+2)&&r.isDark(n,o+3)&&r.isDark(n,o+4)&&!r.isDark(n,o+5)&&r.isDark(n,o+6)&&(t+=40);for(o=0;o<e;o++)for(n=0;n<e-6;n++)r.isDark(n,o)&&!r.isDark(n+1,o)&&r.isDark(n+2,o)&&r.isDark(n+3,o)&&r.isDark(n+4,o)&&!r.isDark(n+5,o)&&r.isDark(n+6,o)&&(t+=40);var l=0;for(o=0;o<e;o++)for(n=0;n<e;n++)r.isDark(n,o)&&l++;return t+=10*(Math.abs(100*l/e/e-50)/5)}},o=[[],[6,18],[6,22],[6,26],[6,30],[6,34],[6,22,38],[6,24,42],[6,26,46],[6,28,50],[6,30,54],[6,32,58],[6,34,62],[6,26,46,66],[6,26,48,70],[6,26,50,74],[6,30,54,78],[6,30,56,82],[6,30,58,86],[6,34,62,90],[6,28,50,72,94],[6,26,50,74,98],[6,30,54,78,102],[6,28,54,80,106],[6,32,58,84,110],[6,30,58,86,114],[6,34,62,90,118],[6,26,50,74,98,122],[6,30,54,78,102,126],[6,26,52,78,104,130],[6,30,56,82,108,134],[6,34,60,86,112,138],[6,30,58,86,114,142],[6,34,62,90,118,146],[6,30,54,78,102,126,150],[6,24,50,76,102,128,154],[6,28,54,80,106,132,158],[6,32,58,84,110,136,162],[6,26,54,82,110,138,166],[6,30,58,86,114,142,170]],a=[[41,25,17],[34,20,14],[27,16,11],[17,10,7],[77,47,32],[63,38,26],[48,29,20],[34,20,14],[127,77,53],[101,61,42],[77,47,30],[58,35,24],[187,114,78],[149,90,62],[111,67,46],[82,50,34],[255,154,106],[202,122,84],[144,87,60],[106,64,44],[322,195,134],[255,154,106],[178,108,74],[139,84,58],[370,224,154],[293,178,122],[207,125,86],[154,93,64],[461,279,192],[365,221,152],[259,157,108],[202,122,76],[552,335,230],[432,262,180],[312,189,130],[235,143,98],[652,395,271],[513,311,213],[364,221,151],[288,174,119],[772,468,321],[604,366,251],[427,259,177],[331,200,137],[883,535,367],[691,419,287],[489,296,203],[374,227,155],[1022,619,425],[796,483,331],[580,352,241],[427,259,177],[1101,667,458],[871,528,362],[621,376,258],[468,283,194],[1250,758,520],[991,600,412],[703,426,292],[530,321,220],[1408,854,586],[1082,656,450],[775,470,322],[602,365,250],[1548,938,644],[1212,734,504],[870,531,364],[674,408,280],[1725,1046,718],[1346,816,560],[963,574,394],[746,452,310],[1903,1153,792],[1500,909,624],[1087,644,442],[813,493,338],[2061,1249,858],[1600,970,666],[1171,702,482],[919,557,382],[2232,1352,929],[1708,1035,711],[1273,764,524],[969,587,403],[2409,1460,1003],[1872,1134,779],[1367,820,563],[1056,640,439],[2620,1588,1091],[2059,1248,857],[1465,879,604],[1108,672,461],[2812,1704,1171],[2188,1326,911],[1528,920,632],[1228,744,511],[3057,1853,1273],[2395,1451,997],[1628,978,672],[1286,779,535],[3283,1990,1367],[2544,1542,1059],[1732,1040,714],[1425,864,593],[3517,2132,1465],[2701,1637,1125],[1840,1106,762],[1501,910,625],[3669,2223,1528],[2857,1732,1190],[1952,1174,808],[1581,958,658],[3909,2369,1628],[3035,1839,1264],[2068,1243,856],[1677,1016,698],[4158,2520,1732],[3289,1994,1370],[2170,1304,898],[1782,1080,742],[4417,2677,1840],[3486,2113,1452],[2303,1385,952],[1897,1150,790],[4686,2840,1952],[3693,2238,1538],[2431,1461,1002],[2022,1226,842],[4965,3009,2068],[3909,2369,1628],[2563,1541,1056],[2157,1307,898],[5253,3183,2188],[4134,2506,1722],[2699,1625,1112],[2301,1394,958],[5529,3351,2303],[4343,2632,1809],[2809,1690,1168],[2361,1431,983],[5836,3537,2431],[4588,2780,1911],[2953,1777,1228],[2524,1530,1051],[6153,3729,2563],[4775,2894,1989],[3063,1842,1264],[2625,1591,1093],[6479,3927,2699],[5039,3054,2099],[3247,1952,1340],[2735,1658,1139],[6743,4087,2809],[5313,3220,2213],[3417,2068,1418],[2927,1774,1219],[7089,4296,2953],[5596,3391,2331],[3599,2170,1490],[3057,1852,1273]];function R(){var r=[],e=0;this.getBuffer=function(){return r},this.getAt=function(e){return 1==(r[Math.floor(e/8)]>>>7-e%8&1)},this.put=function(r,e){for(var t=0;t<e;t++)this.putBit(1==(r>>>e-t-1&1))},this.getLengthInBits=function(){return e},this.putBit=function(t){var n=Math.floor(e/8);r.length<=n&&r.push(0),t&&(r[n]|=128>>>e%8),e++}}var v=function(r,e,t){for(var n=C.getRSBlocks(r,e),o=new R,i=0;i<t.length;i++){var a=t[i];o.put(a.getMode(),4),o.put(a.getLength(),h.getLengthInBits(a.getMode(),r)),a.write(o)}var u=0;for(i=0;i<n.length;i++)u+=n[i].dataCount;if(o.getLengthInBits()>8*u)throw"code length overflow. ("+o.getLengthInBits()+">"+8*u+")";for(o.getLengthInBits()+4<=8*u&&o.put(0,4);o.getLengthInBits()%8!=0;)o.putBit(!1);for(;!(o.getLengthInBits()>=8*u||(o.put(236,8),o.getLengthInBits()>=8*u));)o.put(17,8);return function(r,e){for(var t=0,n=0,o=0,i=new Array(e.length),a=new Array(e.length),u=0;u<e.length;u++){var c=e[u].dataCount,l=e[u].totalCount-c;n=Math.max(n,c),o=Math.max(o,l),i[u]=new Array(c);for(var s=0;s<i[u].length;s++)i[u][s]=255&r.getBuffer()[s+t];t+=c;var p=h.getErrorCorrectPolynomial(l),d=new g(i[u],p.getLength()-1).mod(p);a[u]=new Array(p.getLength()-1);for(s=0;s<a[u].length;s++){var S=s+d.getLength()-a[u].length;a[u][s]=S>=0?d.getAt(S):0}}var w=0;for(u=0;u<e.length;u++)w+=e[u].totalCount;var T=new Array(w),B=0;for(s=0;s<n;s++)for(u=0;u<e.length;u++)s<i[u].length&&(T[B++]=i[u][s]);for(s=0;s<o;s++)for(u=0;u<e.length;u++)s<a[u].length&&(T[B++]=a[u][s]);return T}(o,n)};function f(r,e){this.num=r,this.den=e}function i(r,e){if(void 0===r.length)throw r.length+"/"+e;var t=0;for(;t<r.length&&0==r[t];)t++;this.num=new Array(r.length-t+e);for(var n=0;n<r.length-t;n++)this.num[n]=r[n+t]}var g=i;i.prototype={getAt:function(r){return this.num[r]},getLength:function(){return this.num.length},multiply:function(r){for(var e=new Array(this.getLength()+r.getLength()-1),t=0;t<this.getLength();t++)for(var n=0;n<r.getLength();n++)e[t+n]^=u.gexp(u.glog(this.getAt(t))+u.glog(r.getAt(n)));return new i(e,0)},mod:function(r){if(this.getLength()-r.getLength()<0)return this;for(var e=u.glog(this.getAt(0))-u.glog(r.getAt(0)),t=new Array(this.getLength()),n=0;n<this.getLength();n++)t[n]=this.getAt(n);for(n=0;n<r.getLength();n++)t[n]^=u.gexp(u.glog(r.getAt(n))+e);return new i(t,0).mod(r)}},h.getErrorCorrectPolynomial=function(r){for(var e=new i([1],0),t=0;t<r;t++)e=e.multiply(new i([1,u.gexp(t)],0));return e};var u={glog:function(r){if(r<1)throw"glog("+r+")";return u.LOG_TABLE[r]},gexp:function(r){for(;r<0;)r+=255;for(;r>=256;)r-=255;return u.EXP_TABLE[r]},EXP_TABLE:new Array(256),LOG_TABLE:new Array(256)};for(var c=0;c<8;c++)u.EXP_TABLE[c]=1<<c;for(c=8;c<256;c++)u.EXP_TABLE[c]=u.EXP_TABLE[c-4]^u.EXP_TABLE[c-5]^u.EXP_TABLE[c-6]^u.EXP_TABLE[c-8];for(c=0;c<255;c++)u.LOG_TABLE[u.EXP_TABLE[c]]=c;return r}();
</script>

<script>
    function generatePortalQR() {
        var portalUrl = <?= json_encode(SITE_URL . '/employee/') ?>;
        document.getElementById('qrUrlDisplay').textContent = portalUrl;
        var container = document.getElementById('qrContainer');
        container.innerHTML = '';

        var qr = qrcode(0, 'M');
        qr.addData(portalUrl);
        qr.make();

        var moduleCount = qr.getModuleCount();
        var cellSize = 8;
        var margin = cellSize * 2;
        var size = moduleCount * cellSize + margin * 2;

        var canvas = document.createElement('canvas');
        var scale = 3;
        canvas.width = size * scale;
        canvas.height = size * scale;
        canvas.style.width = size + 'px';
        canvas.style.height = size + 'px';
        canvas.style.borderRadius = '12px';
        canvas.id = 'qrCanvas';

        var ctx = canvas.getContext('2d');
        ctx.scale(scale, scale);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);

        // Draw rounded QR modules
        ctx.fillStyle = '#1f2937';
        for (var r = 0; r < moduleCount; r++) {
            for (var c = 0; c < moduleCount; c++) {
                if (qr.isDark(r, c)) {
                    var x = c * cellSize + margin;
                    var y = r * cellSize + margin;
                    var radius = cellSize * 0.3;
                    ctx.beginPath();
                    ctx.moveTo(x + radius, y);
                    ctx.lineTo(x + cellSize - radius, y);
                    ctx.arcTo(x + cellSize, y, x + cellSize, y + radius, radius);
                    ctx.lineTo(x + cellSize, y + cellSize - radius);
                    ctx.arcTo(x + cellSize, y + cellSize, x + cellSize - radius, y + cellSize, radius);
                    ctx.lineTo(x + radius, y + cellSize);
                    ctx.arcTo(x, y + cellSize, x, y + cellSize - radius, radius);
                    ctx.lineTo(x, y + radius);
                    ctx.arcTo(x, y, x + radius, y, radius);
                    ctx.fill();
                }
            }
        }

        // Draw logo in center
        var logo = new Image();
        logo.crossOrigin = 'anonymous';
        logo.onload = function() {
            var logoSize = size * 0.22;
            var lx = (size - logoSize) / 2;
            var ly = (size - logoSize) / 2;
            var pad = logoSize * 0.18;

            // White circle background
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.arc(size / 2, size / 2, logoSize / 2 + pad, 0, 2 * Math.PI);
            ctx.fill();

            // Orange ring
            ctx.strokeStyle = '#F97316';
            ctx.lineWidth = 2.5;
            ctx.beginPath();
            ctx.arc(size / 2, size / 2, logoSize / 2 + pad - 1.5, 0, 2 * Math.PI);
            ctx.stroke();

            // Clip circle for logo
            ctx.save();
            ctx.beginPath();
            ctx.arc(size / 2, size / 2, logoSize / 2, 0, 2 * Math.PI);
            ctx.clip();
            ctx.drawImage(logo, lx, ly, logoSize, logoSize);
            ctx.restore();

            container.appendChild(canvas);
        };
        logo.onerror = function() {
            container.appendChild(canvas);
        };
        logo.src = <?= json_encode(SITE_URL . '/assets/images/loogo.png') ?>;
    }

    function downloadQR() {
        var canvas = document.getElementById('qrCanvas');
        if (!canvas) return;
        var link = document.createElement('a');
        link.download = 'بوابة-الحضور-QR.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    }

    function printQR() {
        var card = document.getElementById('qrCardPrint');
        if (!card) return;
        var win = window.open('', '_blank');
        win.document.write('<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"><title>باركود بوابة الحضور</title>');
        win.document.write('<style>*{margin:0;padding:0;box-sizing:border-box}body{display:flex;justify-content:center;align-items:center;min-height:100vh;font-family:Tajawal,sans-serif;background:#fff}img{max-width:100%}.card{text-align:center;padding:40px}</style>');
        win.document.write('</head><body><div class="card">');
        win.document.write(card.innerHTML);
        win.document.write('</div></body></html>');
        win.document.close();
        setTimeout(function() { win.print(); }, 600);
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