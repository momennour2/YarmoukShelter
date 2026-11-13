<?php
declare(strict_types=1);
/* Included from index.php – data quality checks (general manager only). Helps reconcile the numbers with the Excel file. */

if (!is_admin()) {
    echo '<section class="panel">هذه الصفحة للمدير العام فقط.</section>';
    return;
}
$pdo = db();
$base = "archived_at IS NULL AND sector<>'R'";
$act = ACTIVE_SQL;

$checks = [
    'أسر بدون ربّ أسرة (HHID ليس له ربّ أسرة)' =>
        "SELECT hh_id k, MIN(sector) s, MIN(tent) t, COUNT(*) n FROM profiles WHERE $base AND hh_id<>'' GROUP BY hh_id HAVING SUM(is_household_head=1)=0 LIMIT 100",
    'أسر فيها أكثر من ربّ أسرة' =>
        "SELECT hh_id k, MIN(sector) s, MIN(tent) t, COUNT(*) n FROM profiles WHERE $base AND hh_id<>'' GROUP BY hh_id HAVING SUM(is_household_head=1)>1 LIMIT 100",
    'رقم هوية مكرر (سجلات نشطة)' =>
        "SELECT national_id k, MIN(sector) s, MIN(tent) t, COUNT(*) n FROM profiles WHERE $base AND national_id<>'' GROUP BY national_id HAVING COUNT(*)>1 LIMIT 100",
    'سجلات بدون رقم خيمة' =>
        "SELECT national_id k, sector s, tent t, 1 n FROM profiles WHERE $base AND (tent IS NULL OR tent='') LIMIT 100",
    'سجلات بدون تاريخ ميلاد أو بجنس غير محدد' =>
        "SELECT national_id k, sector s, tent t, 1 n FROM profiles WHERE $base AND (birth_date IS NULL OR gender IS NULL OR gender='') LIMIT 100",
    'أفراد في أسرة ربّ أسرتها مؤرشف (R)' =>
        "SELECT p.national_id k, p.sector s, p.tent t, 1 n FROM profiles p WHERE p.archived_at IS NULL AND p.sector<>'R' AND p.hh_id<>'' AND NOT EXISTS (SELECT 1 FROM profiles h WHERE h.hh_id=p.hh_id AND h.is_household_head=1 AND h.archived_at IS NULL AND h.sector<>'R') AND EXISTS (SELECT 1 FROM profiles h2 WHERE h2.hh_id=p.hh_id AND h2.is_household_head=1 AND h2.sector='R') LIMIT 100",
];
?>
<section class="panel">
  <div class="panel-head"><h2>فحص جودة البيانات</h2></div>
  <p class="hint">هذه الصفحة تكشف السجلات التي تسبب اختلاف الأرقام عن ملف Excel عندك. صحّحها من صفحة الملفات.</p>
  <?php foreach ($checks as $title => $sql): $rows = $pdo->query($sql)->fetchAll(); ?>
    <h3><?= e($title) ?> <?= badge((string)count($rows) . (count($rows) >= 100 ? '+' : ''), count($rows) ? 'red' : 'green') ?></h3>
    <?php if ($rows): ?>
    <div class="table-wrap"><table><thead><tr><th>الهوية / HHID</th><th>القطاع</th><th>الخيمة</th><th>عدد السجلات</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?><tr><td><?= e($r['k']) ?></td><td><?= e($r['s']) ?></td><td><?= e($r['t']) ?></td><td><?= e($r['n']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  <?php endforeach; ?>
</section>
