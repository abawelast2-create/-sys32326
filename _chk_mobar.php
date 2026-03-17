<?php
require __DIR__ . '/includes/db.php';
$rows = db()->query("SELECT id, name, pin, phone FROM employees WHERE branch_id = 6 ORDER BY id")->fetchAll();
echo "Branch 6 employees: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo $r['id'] . ' | ' . $r['name'] . ' | ' . $r['pin'] . ' | ' . $r['phone'] . "\n";
}
@unlink(__FILE__);
