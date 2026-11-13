<?php
declare(strict_types=1);
/* Included from index.php – activity log + suspicious activity (general manager only). */

if (!is_admin()) {
    echo '<section class="panel">هذه الصفحة للمدير العام فقط.</section>';
    return;
}
$pdo = db();

$failed = $pdo->query("SELECT kind, identifier, ip_address, COUNT(*) n, MAX(created_at) last_at
    FROM login_attempts WHERE success=0 AND created_at > (NOW() - INTERVAL 24 HOUR)
    GROUP BY kind, identifier, ip_address HAVING n >= 3 ORDER BY n DESC, last_at DESC LIMIT 30")->fetchAll();

$q = trim((string)($_GET['q'] ?? ''));
$args = [];
$where = '';
if ($q !== '') {
    $where = 'WHERE (a.action LIKE ? OR a.module LIKE ? OR a.details LIKE ? OR u.username LIKE ?)';
    $args = array_fill(0, 4, "%$q%");
}
$st = $pdo->prepare("SELECT a.created_at, u.username, a.action, a.module, a.record_id, a.details, a.ip_address
    FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id $where ORDER BY a.id DESC LIMIT 200");
$st->execute($args);
$logs = $st->fetchAll();
?>
<section class="panel">
  <div class="panel-head"><h2>نشاط مشبوه — آخر 24 ساعة</h2></div>
  <p class="hint">محاولات دخول فاشلة 3 مرات أو أكثر لنفس الحساب/الهوية من نفس العنوان. بعد 5 محاولات يُقفل الحساب 15 دقيقة تلقائياً.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>النوع</th><th>الحساب / الهوية</th><th>عنوان IP</th><th>المحاولات</th><th>آخر محاولة</th></tr></thead>
    <tbody>
    <?php foreach ($failed as $r): ?>
      <tr><td><?= e($r['kind'] === 'staff' ? 'موظف' : 'نازح') ?></td><td><?= e($r['identifier']) ?></td><td><?= e($r['ip_address']) ?></td><td><?= badge((string)$r['n'], 'red') ?></td><td><?= e($r['last_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$failed): ?><tr><td colspan="5">لا يوجد نشاط مشبوه.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <div class="panel-head"><h2>سجل النشاط</h2><span class="tag">آخر 200 عملية</span></div>
  <form method="get" class="search-form"><input type="hidden" name="page" value="security"><input name="q" value="<?= e($q) ?>" placeholder="بحث: مستخدم، عملية، قسم، تفاصيل"><button class="primary">بحث</button></form>
  <div class="table-wrap"><table>
    <thead><tr><th>الوقت</th><th>المستخدم</th><th>العملية</th><th>القسم</th><th>رقم السجل</th><th>تفاصيل</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td><td><?= e($r['username'] ?? '—') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['module']) ?></td><td><?= e($r['record_id']) ?></td><td><?= e($r['details']) ?></td><td><?= e($r['ip_address']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
