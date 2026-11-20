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
$per = in_array((int)($_GET['per'] ?? 300), [100, 300, 1000, 3000], true) ? (int)($_GET['per'] ?? 300) : 300;
$pg = max(1, (int)($_GET['pg'] ?? 1));

/* Permission checks hit the database: do them once per sector, not once per row. */
$editAny = can('profiles', 'edit');
$editSec = [];
foreach ($sel as $x) {
    $editSec[$x] = $editAny && (is_admin() || can('profiles', 'edit', $x));
}

$rows = [];
$total = 0;
$counts = [];
$warn = '';
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
    try {
        $cq = db()->prepare("SELECT p.sector, COUNT(*) c FROM profiles p WHERE $where GROUP BY p.sector");
        $cq->execute($args);
        foreach ($cq->fetchAll() as $r) {
            $counts[$r['sector']] = (int)$r['c'];
            $total += (int)$r['c'];
        }
        $cols = 'p.id,p.sector,p.tent,p.national_id,p.full_name,p.gender,p.birth_date,p.social_status,p.phone,p.job_position';
        $off = ($pg - 1) * $per;
        try {
            $order = "FIELD(p.sector,'AS','BS','CS','DS','S'), " . org_rank_sql('job_position', 'p') . ', p.job_position, p.full_name';
            $st = db()->prepare("SELECT $cols FROM profiles p WHERE $where ORDER BY $order LIMIT $per OFFSET $off");
            $st->execute($args);
        } catch (Throwable $orderErr) {
            // The administrative-structure ordering failed: still show the people, in a simple order.
            error_log('HR order failed: ' . $orderErr->getMessage());
            $warn = 'تعذّر الترتيب حسب الهيكل الإداري، تم عرض القائمة بترتيب بسيط.';
            $st = db()->prepare("SELECT $cols FROM profiles p WHERE $where ORDER BY FIELD(p.sector,'AS','BS','CS','DS','S'), p.job_position, p.full_name LIMIT $per OFFSET $off");
            $st->execute($args);
        }
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('HR page: ' . $e->getMessage());
        $warn = 'تعذّر تحميل القائمة: ' . (is_admin() ? $e->getMessage() : 'حاول مرة أخرى.');
    }
}
$pages = max(1, (int)ceil($total / $per));
$gmap = ['Male' => 'ذكر', 'Female' => 'أنثى'];
$qs = $_GET;
unset($qs['pg']);
$base = '?' . http_build_query($qs);
?>
<section class="panel">
  <div class="panel-head"><h2>الموارد البشرية — قطاعات AS · BS · CS · DS · S</h2><span class="tag"><?= $total ?> سجل</span></div>
  <p class="hint">تُسحب البيانات تلقائياً من ملفات الأفراد في هذه القطاعات. لتعيين أو تعديل «Job Position» عدّل ملف الشخص من صفحة الملفات.</p>
  <?php if ($warn !== ''): ?><div class="alert"><?= e($warn) ?></div><?php endif; ?>
  <form method="get" class="search-form">
    <input type="hidden" name="page" value="hr">
    <input name="q" value="<?= e($q) ?>" placeholder="بحث بالاسم أو الهوية أو المهمة أو الخيمة">
    <select name="sector_filter"><option value="">كل القطاعات</option><?php foreach ($allowed as $x): ?><option <?= $sf === $x ? 'selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
    <select name="per"><?php foreach ([100, 300, 1000, 3000] as $n): ?><option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= $n ?> بالصفحة</option><?php endforeach; ?></select>
    <label class="check-line"><input type="checkbox" name="only_job" value="1" <?= $onlyJob ? 'checked' : '' ?>> من لديهم مهمة فقط</label>
    <button class="primary">تطبيق</button><button class="primary" type="button" onclick="window.print()">طباعة</button>
  </form>
  <?php if ($counts): ?><p class="muted"><?php foreach ($counts as $k => $v): ?><?= badge($k . ': ' . $v, 'blue') ?> <?php endforeach; ?></p><?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>القطاع</th><th>الخيمة</th><th>الهوية</th><th>الاسم</th><th>الجنس</th><th>العمر</th><th>الحالة الاجتماعية</th><th>الهاتف</th><th>Job Position</th><?php if ($editAny): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr><td><?= ($pg - 1) * $per + $i + 1 ?></td><td><?= e($r['sector']) ?></td><td><?= e($r['tent']) ?></td><td><?= $seeS ? e($r['national_id']) : 'مخفي' ?></td><td><?= e($r['full_name']) ?></td><td><?= e($gmap[(string)$r['gender']] ?? (string)$r['gender']) ?></td><td><?= e(age_years($r['birth_date'] === null ? null : (string)$r['birth_date'])) ?></td><td><?= e($r['social_status']) ?></td><td><?= $seeS ? e($r['phone']) : 'مخفي' ?></td><td><?= e($r['job_position']) ?></td>
      <?php if ($editAny): ?><td><?php if (!empty($editSec[$r['sector']])): ?><a href="?page=profiles&edit=<?= (int)$r['id'] ?>">تعديل</a><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11">لا توجد سجلات.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="pager"><?php for ($i = 1; $i <= $pages; $i++): if ($i === 1 || $i === $pages || abs($i - $pg) <= 2): ?><a class="<?= $i === $pg ? 'on' : '' ?>" href="<?= e($base . '&pg=' . $i) ?>"><?= $i ?></a><?php elseif (abs($i - $pg) === 3): ?><span>…</span><?php endif; endfor; ?></div>
  <?php endif; ?>
</section>
