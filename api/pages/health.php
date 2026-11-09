<?php
declare(strict_types=1);
/* Included from index.php – installation self-check (general manager only). */

if (!is_admin()) {
    echo '<section class="panel">هذه الصفحة للمدير العام فقط.</section>';
    return;
}

$checks = [];
$add = function (string $name, bool $ok, string $hint = '') use (&$checks): void {
    $checks[] = [$name, $ok, $hint];
};

$add('إصدار PHP ' . PHP_VERSION . ' (المطلوب 8.1+)', PHP_VERSION_ID >= 80100, 'حدّث XAMPP إلى إصدار حديث.');
foreach (['pdo_mysql', 'mbstring', 'json'] as $ext) {
    $add("إضافة PHP: $ext", extension_loaded($ext), "فعّل $ext من php.ini.");
}
$add('مجلد uploads/requests قابل للكتابة', is_dir(__DIR__ . '/../uploads/requests') && is_writable(__DIR__ . '/../uploads/requests'), 'أعطِ المجلد صلاحية الكتابة.');
$add('الاتصال بقاعدة البيانات', true);

$have = [];
foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $have[$t] = true;
}
foreach (['users', 'user_permissions', 'profiles', 'beneficiaries', 'finance', 'aids', 'employees', 'warehouse_items', 'audit_logs',
    'institutions', 'tickets', 'data_requests', 'sector_allocations', 'announcements', 'login_attempts'] as $t) {
    $add("جدول $t", isset($have[$t]), 'نفّذ database/migration_v5.sql على قاعدتك.');
}
$cols = [];
if (isset($have['users'])) {
    $cols = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
}
foreach (['role', 'institution_id', 'assigned_sectors', 'last_login_at'] as $c) {
    $add("عمود users.$c", in_array($c, $cols, true), 'نفّذ database/migration_v5.sql.');
}
$add('ملف setup_admin.php محذوف', !is_file(__DIR__ . '/../setup_admin.php'), 'احذفه بعد أول استخدام.');
$add('ملف demo_seed.sql غير موجود على الخادم العام', !is_file(__DIR__ . '/../database/demo_seed.sql'), 'احذفه قبل النشر.');
$bad = count(array_filter($checks, fn($c) => !$c[1]));
?>
<section class="panel">
  <div class="panel-head"><h2>فحص النظام</h2><?= $bad ? badge($bad . ' ملاحظات', 'red') : badge('كل شيء سليم', 'green') ?></div>
  <div class="table-wrap"><table><thead><tr><th>الفحص</th><th>النتيجة</th><th>الحل</th></tr></thead><tbody>
  <?php foreach ($checks as [$n, $ok, $h]): ?>
    <tr><td><?= e($n) ?></td><td><?= $ok ? badge('سليم', 'green') : badge('مطلوب', 'red') ?></td><td><?= $ok ? '' : e($h) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
