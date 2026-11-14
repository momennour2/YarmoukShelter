<?php
declare(strict_types=1);
/* Included from index.php – HR: the people of sectors AS, BS, CS, DS and S with their Job Position. */

if (!can('hr', 'view')) {
    echo '<section class="panel">لا تملك صلاحية عرض الموارد البشرية.</section>';
    return;
}

$hrSectors = ['AS', 'BS', 'CS', 'DS', 'S'];
$allowed = array_values(array_filter($hrSectors, fn($x) => is_admin() || can('hr', 'view', $x)));
$sf = (string)($_GET['sector_filter'] ?? '');
$sel = ($sf !== '' && in_array($sf, $allowed, true)) ? [$sf] : $allowed;
$q = trim((string)($_GET['q'] ?? ''));
$onlyJob = ($_GET['only_job'] ?? '') === '1';
$seeS = is_admin() || can('profiles', 'view_sensitive');

$rows = [];
if ($sel) {
    $where = "p.archived_at IS NULL AND p.sector<>'R' AND p.sector IN (" . ph($sel) . ')';
    $args = $sel;
    if ($onlyJob) {
        $where .= " AND COALESCE(p.job_position,'')<>''";
    }
    if ($q !== '') {
        $where .= ' AND (p.full_name LIKE ? OR p.national_id LIKE ? OR p.job_position LIKE ? OR p.tent LIKE ?)';
        array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    $st = db()->prepare("SELECT p.* FROM profiles p WHERE $where ORDER BY FIELD(p.sector,'AS','BS','CS','DS','S'), ".org_rank_sql('job_position','p').", p.job_position, p.full_name LIMIT 3000");
    $st->execute($args);
    $rows = $st->fetchAll();
}
$counts = [];
foreach ($rows as $r) {
    $counts[$r['sector']] = ($counts[$r['sector']] ?? 0) + 1;
}
?>
<section class="panel">
  <div class="panel-head"><h2>الموارد البشرية — قطاعات AS · BS · CS · DS · S</h2><span class="tag"><?= count($rows) ?> سجل</span></div>
  <p class="hint">تُسحب البيانات تلقائياً من ملفات الأفراد في هذه القطاعات. لتعيين أو تعديل «Job Position» عدّل ملف الشخص من صفحة الملفات.</p>
  <form method="get" class="search-form">
    <input type="hidden" name="page" value="hr">
    <input name="q" value="<?= e($q) ?>" placeholder="بحث بالاسم أو الهوية أو المهمة أو الخيمة">
    <select name="sector_filter"><option value="">كل القطاعات</option><?php foreach ($allowed as $x): ?><option <?= $sf === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
    <label class="check-line"><input type="checkbox" name="only_job" value="1" <?= $onlyJob ? 'checked' : '' ?>> من لديهم مهمة فقط</label>
    <button class="primary">تطبيق</button><button class="primary" type="button" onclick="window.print()">طباعة</button>
  </form>
  <?php if ($counts): ?><p class="muted"><?php foreach ($counts as $k => $v): ?><?= badge($k . ': ' . $v, 'blue') ?> <?php endforeach; ?></p><?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>القطاع</th><th>الخيمة</th><th>الهوية</th><th>الاسم</th><th>الجنس</th><th>العمر</th><th>الحالة الاجتماعية</th><th>الهاتف</th><th>Job Position</th><?php if (can('profiles', 'edit')): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr><td><?= $i + 1 ?></td><td><?= e($r['sector']) ?></td><td><?= e($r['tent']) ?></td><td><?= $seeS ? e($r['national_id']) : 'مخفي' ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['gender']) ?></td><td><?= e(age_years($r['birth_date'])) ?></td><td><?= e($r['social_status']) ?></td><td><?= $seeS ? e($r['phone']) : 'مخفي' ?></td><td><?= e($r['job_position']) ?></td>
      <?php if (can('profiles', 'edit')): ?><td><?php if (can('profiles', 'edit', (string)$r['sector'])): ?><a href="?page=profiles&edit=<?= (int)$r['id'] ?>">تعديل</a><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11">لا توجد سجلات.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
