<?php
// =============================================================
// admin/documents-expiry.php — لوحة انتهاء الوثائق
// =============================================================

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdminLogin();

// التأكد من وجود الجداول
try {
    db()->exec("CREATE TABLE IF NOT EXISTS emp_document_groups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_id INT UNSIGNED NOT NULL,
        group_name VARCHAR(200) NOT NULL DEFAULT '',
        expiry_date DATE NOT NULL,
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_emp (employee_id), INDEX idx_exp (expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS emp_document_files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id INT UNSIGNED NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type ENUM('image','pdf') NOT NULL DEFAULT 'image',
        original_name VARCHAR(255) NOT NULL DEFAULT '',
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {}

// ─── جلب كل المجموعات مع بيانات الموظف مرتبة حسب الانتهاء ─────────
$rows = db()->query("
    SELECT
        g.id            AS group_id,
        g.group_name,
        g.expiry_date,
        DATEDIFF(g.expiry_date, CURDATE()) AS days_left,
        e.id            AS employee_id,
        e.name          AS employee_name,
        e.job_title,
        e.profile_photo,
        b.name          AS branch_name,
        (SELECT COUNT(*) FROM emp_document_files f WHERE f.group_id = g.id) AS file_count
    FROM emp_document_groups g
    JOIN employees e ON g.employee_id = e.id AND e.deleted_at IS NULL AND e.is_active = 1
    LEFT JOIN branches b ON e.branch_id = b.id
    ORDER BY g.expiry_date ASC
")->fetchAll();

$pageTitle  = 'وثائق المنتهية';
$activePage = 'documents-expiry';

function statusClass(int $days): string {
    if ($days < 0)   return 'expired';
    if ($days <= 10) return 'critical';
    if ($days <= 30) return 'warning';
    if ($days <= 90) return 'caution';
    return 'ok';
}
function statusLabel(int $days): string {
    if ($days < 0)   return 'منتهية';
    if ($days <= 10) return $days . ' يوم';
    if ($days <= 30) return $days . ' يوم';
    if ($days <= 90) return $days . ' يوم';
    return $days . ' يوم';
}

require_once __DIR__ . '/../includes/admin_layout.php';
?>

<style>
.exp-filter-bar{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.exp-filter{padding:7px 16px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface);color:var(--text2);font-family:'Tajawal',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s}
.exp-filter:hover,.exp-filter.active{border-color:var(--primary);background:var(--primary);color:#fff}
.exp-filter.active-expired{border-color:#64748B;background:#64748B;color:#fff}
.exp-filter.active-critical{border-color:#DC2626;background:#DC2626;color:#fff}
.exp-filter.active-warning{border-color:#EF4444;background:#EF4444;color:#fff}
.exp-filter.active-caution{border-color:#D97706;background:#D97706;color:#fff}
.exp-filter.active-ok{border-color:#059669;background:#059669;color:#fff}

.exp-table-wrap{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;border:1px solid var(--border)}
.exp-table{width:100%;border-collapse:collapse}
.exp-table th{background:var(--surface2);padding:12px 16px;text-align:right;font-size:.8rem;color:var(--text3);font-weight:700;border-bottom:1px solid var(--border);white-space:nowrap}
.exp-table td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;font-size:.88rem;color:var(--text)}
.exp-table tr:last-child td{border-bottom:none}
.exp-table tr:hover td{background:var(--surface2)}
.exp-table tr.hidden-row{display:none}

/* Employee cell */
.emp-cell{display:flex;align-items:center;gap:10px}
.emp-avatar-sm{width:38px;height:38px;border-radius:50%;object-fit:cover;background:var(--primary-l);flex-shrink:0}
.emp-avatar-init{width:38px;height:38px;border-radius:50%;background:var(--primary-l);color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.9rem;flex-shrink:0}
.emp-name{font-weight:700;color:var(--text)}
.emp-branch-sm{font-size:.72rem;color:var(--text3)}

/* Days badge */
.exp-badge{padding:5px 14px;border-radius:20px;font-size:.78rem;font-weight:700;white-space:nowrap;display:inline-flex;align-items:center;gap:4px}
.exp-badge.expired{background:#F1F5F9;color:#64748B}
.exp-badge.critical{background:#FEE2E2;color:#991B1B;animation:pulse-red 1.4s ease-in-out infinite}
.exp-badge.warning{background:#FEE2E2;color:#B91C1C}
.exp-badge.caution{background:#FEF3C7;color:#92400E}
.exp-badge.ok{background:#D1FAE5;color:#065F46}
@keyframes pulse-red{0%,100%{box-shadow:0 0 0 2px rgba(220,38,38,.4)}50%{box-shadow:0 0 0 6px rgba(220,38,38,.05)}}

/* Summary cards */
.exp-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.exp-sum-card{background:#fff;border-radius:var(--radius);padding:16px 20px;box-shadow:var(--shadow);border:1px solid var(--border);text-align:center;cursor:pointer;transition:all .2s;border-top:4px solid transparent}
.exp-sum-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.exp-sum-card.c-expired{border-top-color:#94A3B8}
.exp-sum-card.c-critical{border-top-color:#DC2626}
.exp-sum-card.c-warning{border-top-color:#EF4444}
.exp-sum-card.c-caution{border-top-color:#D97706}
.exp-sum-card.c-ok{border-top-color:#059669}
.exp-sum-num{font-size:1.8rem;font-weight:800;margin-bottom:4px}
.exp-sum-label{font-size:.75rem;color:var(--text3);font-weight:600}

/* View docs button */
.btn-view-docs{padding:6px 14px;background:var(--primary-l);color:var(--primary-d);border:none;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:.8rem;font-weight:700;cursor:pointer;transition:all .2s;white-space:nowrap}
.btn-view-docs:hover{background:var(--primary);color:#fff}
.btn-profile-sm{padding:6px 10px;background:var(--surface3);color:var(--text2);border:none;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:.78rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.btn-profile-sm:hover{background:var(--primary-l);color:var(--primary-d)}
.empty-state{text-align:center;padding:60px 20px;color:var(--text3)}
.empty-state-icon{font-size:3rem;margin-bottom:12px}

/* Modal Docs Viewer */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(3px)}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-box{background:#fff;border-radius:20px;padding:28px;width:100%;max-width:680px;transform:translateY(20px);transition:transform .25s;position:relative;max-height:85vh;overflow-y:auto}
.modal-overlay.open .modal-box{transform:translateY(0)}
.modal-close{position:absolute;top:16px;left:20px;background:var(--surface3);border:none;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1rem;color:var(--text2);display:flex;align-items:center;justify-content:center}
.modal-close:hover{background:var(--red-l);color:var(--red)}
.modal-title{font-size:1.05rem;font-weight:800;color:var(--text);margin-bottom:16px}
.docs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:12px}
.doc-thumb-lg{border-radius:12px;overflow:hidden;border:1.5px solid var(--border);background:var(--surface3);aspect-ratio:1;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center}
.doc-thumb-lg:hover{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-l)}
.doc-thumb-lg img{width:100%;height:100%;object-fit:cover}
.doc-thumb-pdf{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;height:100%;padding:12px;color:var(--red)}
.doc-thumb-pdf span{font-size:.7rem;color:var(--text3);font-weight:600;text-align:center;word-break:break-all}
.no-docs{color:var(--text3);font-size:.88rem;padding:20px;text-align:center}

/* Lightbox */
.lightbox-overlay{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:2000;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity .2s}
.lightbox-overlay.open{opacity:1;pointer-events:all}
.lightbox-img{max-width:90vw;max-height:85vh;border-radius:10px;object-fit:contain}
.lightbox-iframe{width:80vw;height:85vh;border:none;border-radius:10px}
.lightbox-close-btn{position:fixed;top:16px;left:20px;background:rgba(255,255,255,.15);border:none;border-radius:10px;padding:10px 16px;color:#fff;font-size:.9rem;cursor:pointer}
.lightbox-close-btn:hover{background:rgba(255,255,255,.3)}
</style>

<?php
// حساب الإحصائيات للبطاقات
$counts = ['expired' => 0, 'critical' => 0, 'warning' => 0, 'caution' => 0, 'ok' => 0];
foreach ($rows as $r) {
    $sc = statusClass((int)$r['days_left']);
    $counts[$sc]++;
}
?>

<!-- Summary cards -->
<div class="exp-summary">
    <div class="exp-sum-card c-all" onclick="filterRows('all')">
        <div class="exp-sum-num"><?= count($rows) ?></div>
        <div class="exp-sum-label">الكل</div>
    </div>
    <div class="exp-sum-card c-expired" onclick="filterRows('expired')">
        <div class="exp-sum-num" style="color:#64748B"><?= $counts['expired'] ?></div>
        <div class="exp-sum-label">منتهية</div>
    </div>
    <div class="exp-sum-card c-critical" onclick="filterRows('critical')">
        <div class="exp-sum-num" style="color:#DC2626"><?= $counts['critical'] ?></div>
        <div class="exp-sum-label">أقل من 10 أيام</div>
    </div>
    <div class="exp-sum-card c-warning" onclick="filterRows('warning')">
        <div class="exp-sum-num" style="color:#EF4444"><?= $counts['warning'] ?></div>
        <div class="exp-sum-label">10–30 يوم</div>
    </div>
    <div class="exp-sum-card c-caution" onclick="filterRows('caution')">
        <div class="exp-sum-num" style="color:#D97706"><?= $counts['caution'] ?></div>
        <div class="exp-sum-label">شهر – 3 أشهر</div>
    </div>
    <div class="exp-sum-card c-ok" onclick="filterRows('ok')">
        <div class="exp-sum-num" style="color:#059669"><?= $counts['ok'] ?></div>
        <div class="exp-sum-label">أكثر من 3 أشهر</div>
    </div>
</div>

<!-- Search -->
<div style="margin-bottom:16px">
    <input type="text" id="searchInput" placeholder="🔍 بحث باسم الموظف أو المجموعة..."
           oninput="filterRows(currentFilter)"
           style="width:100%;padding:10px 16px;border:1.5px solid var(--border);border-radius:10px;font-family:'Tajawal',sans-serif;font-size:.9rem;background:var(--surface2)">
</div>

<!-- Table -->
<div class="exp-table-wrap">
<?php if (empty($rows)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📂</div>
        <div style="font-weight:700;font-size:1rem;color:var(--text2);margin-bottom:8px">لا توجد وثائق مسجّلة</div>
        <div>أضف مجموعات وثائق من صفحة بروفايل الموظف</div>
    </div>
<?php else: ?>
    <table class="exp-table">
        <thead>
            <tr>
                <th>الموظف</th>
                <th>المجموعة</th>
                <th>تاريخ الانتهاء</th>
                <th>المتبقي</th>
                <th>الملفات</th>
                <th>إجراء</th>
            </tr>
        </thead>
        <tbody id="expTableBody">
        <?php foreach ($rows as $r):
            $days  = (int)$r['days_left'];
            $sc    = statusClass($days);
            $sl    = statusLabel($days);
            $photoUrl = !empty($r['profile_photo'])
                ? SITE_URL . '/api/serve-file.php?f=' . urlencode($r['profile_photo'])
                : '';
            $initials = mb_substr($r['employee_name'], 0, 1);
        ?>
        <tr data-status="<?= $sc ?>" data-search="<?= htmlspecialchars(strtolower($r['employee_name'] . ' ' . $r['group_name'])) ?>">
            <td>
                <div class="emp-cell">
                    <?php if ($photoUrl): ?>
                        <img src="<?= htmlspecialchars($photoUrl) ?>" class="emp-avatar-sm" alt="">
                    <?php else: ?>
                        <div class="emp-avatar-init"><?= htmlspecialchars($initials) ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="emp-name"><?= htmlspecialchars($r['employee_name']) ?></div>
                        <div class="emp-branch-sm"><?= htmlspecialchars($r['branch_name'] ?: $r['job_title']) ?></div>
                    </div>
                </div>
            </td>
            <td><?= $r['group_name'] ? htmlspecialchars($r['group_name']) : '<span style="color:var(--text3);font-style:italic">بدون اسم</span>' ?></td>
            <td style="direction:ltr;text-align:right;font-size:.85rem">
                <?= htmlspecialchars($r['expiry_date']) ?>
            </td>
            <td>
                <span class="exp-badge <?= $sc ?>"><?= htmlspecialchars($sl) ?></span>
            </td>
            <td style="text-align:center;color:var(--text3);font-size:.82rem"><?= (int)$r['file_count'] ?> ملف</td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <button class="btn-view-docs" onclick="viewDocs(<?= (int)$r['group_id'] ?>, '<?= htmlspecialchars(addslashes($r['employee_name'])) ?>', '<?= htmlspecialchars(addslashes($r['group_name'] ?: 'وثائق')) ?>')">
                        👁️ عرض
                    </button>
                    <a class="btn-profile-sm" href="employee-profile.php?id=<?= (int)$r['employee_id'] ?>">
                        👤 بروفايل
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<!-- Modal: عرض الوثائق -->
<div class="modal-overlay" id="docsModal">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('docsModal').classList.remove('open')">✕</button>
        <div class="modal-title" id="docsModalTitle">وثائق الموظف</div>
        <div id="docsModalBody"><div class="no-docs">جار التحميل…</div></div>
    </div>
</div>

<!-- Lightbox -->
<div class="lightbox-overlay" id="lightbox2" onclick="if(event.target===this)closeLb2()">
    <div id="lb2Content"></div>
    <button class="lightbox-close-btn" onclick="closeLb2()">✕ إغلاق</button>
</div>

<script>
const SITE_URL = '<?= SITE_URL ?>';
let currentFilter = 'all';

function filterRows(status) {
    currentFilter = status;
    const q = document.getElementById('searchInput').value.trim().toLowerCase();
    document.querySelectorAll('#expTableBody tr').forEach(tr => {
        const matchStatus = (status === 'all') || tr.dataset.status === status;
        const matchSearch = !q || tr.dataset.search.includes(q);
        tr.classList.toggle('hidden-row', !(matchStatus && matchSearch));
    });
}

// Fetch and show docs for a group
async function viewDocs(groupId, empName, groupName) {
    document.getElementById('docsModalTitle').textContent = empName + ' — ' + groupName;
    document.getElementById('docsModalBody').innerHTML = '<div class="no-docs">جار التحميل…</div>';
    document.getElementById('docsModal').classList.add('open');

    const res  = await fetch(SITE_URL + '/api/get-group-files.php?group_id=' + groupId);
    const data = await res.json();
    if (!data.success || !data.files.length) {
        document.getElementById('docsModalBody').innerHTML = '<div class="no-docs">لا توجد ملفات في هذه المجموعة</div>';
        return;
    }
    let html = '<div class="docs-grid">';
    data.files.forEach((f, i) => {
        const url = SITE_URL + '/api/serve-file.php?f=' + encodeURIComponent(f.file_path);
        if (f.file_type === 'image') {
            html += `<div class="doc-thumb-lg" onclick="openLb2('${url}','image')"><img src="${url}" alt="" loading="lazy"></div>`;
        } else {
            html += `<div class="doc-thumb-lg" onclick="openLb2('${url}','pdf')"><div class="doc-thumb-pdf"><svg width="36" height="36" viewBox="0 0 24 24" fill="#DC2626"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg><span>${f.original_name||'PDF'}</span></div></div>`;
        }
    });
    html += '</div>';
    document.getElementById('docsModalBody').innerHTML = html;
}

function openLb2(url, type) {
    const cnt = document.getElementById('lb2Content');
    cnt.innerHTML = type === 'image'
        ? `<img class="lightbox-img" src="${url}" alt="">`
        : `<iframe class="lightbox-iframe" src="${url}" title="PDF"></iframe>`;
    document.getElementById('lightbox2').classList.add('open');
}
function closeLb2() {
    document.getElementById('lightbox2').classList.remove('open');
    document.getElementById('lb2Content').innerHTML = '';
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeLb2();
        document.getElementById('docsModal').classList.remove('open');
    }
});
</script>
