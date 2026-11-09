<?php
declare(strict_types=1);
/* Included from index.php – sector allocations ("ترصيد") vs. what was actually delivered. */

if (!can('allocations', 'view')) {
    echo '<section class="panel">لا تملك صلاحية عرض ترصيد القطاعات.</section>';
    return;
}

$msg = '';
$err = '';
$catNames = ['Food' => 'غذائية', 'Financial' => 'نقدية', 'Logistics' => 'لوجستية'];
$scope = sector_alloc_scope();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $do = pv('do');
        if ($do === 'alloc_add') {
            $sec = pv('sector');
            $cat = pv('category');
            $pkg = pv('package');
            $qty = (int)pv('quantity');
            $date = pv('alloc_date');
            if (!in_array($sec, ['A', 'B', 'C', 'D', 'S'], true) || !can('allocations', 'create', $sec)) {
                throw new RuntimeException('لا تملك صلاحية الترصيد لهذا القطاع.');
            }
            if (!isset($catNames[$cat]) || $pkg === '' || $qty === 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new RuntimeException('أكمل الحقول: القطاع، النوع، الطرد، الكمية (لا تساوي صفر)، التاريخ.');
            }
            if (abs($qty) > 1000000) {
                throw new RuntimeException('الكمية غير منطقية.');
            }
            db()->prepare('INSERT INTO sector_allocations(sector,category,package,quantity,alloc_date,note,created_by) VALUES(?,?,?,?,?,?,?)')
                ->execute([$sec, $cat, $pkg, $qty, $date, pv('note') ?: null, user()['id']]);
            audit('create', 'allocations', (int)db()->lastInsertId(), "$sec $pkg $qty");
            $msg = 'تم تسجيل الترصيد. (استخدم كمية سالبة لتصحيح خطأ.)';
        } elseif ($do === 'alloc_delete') {
            if (!is_admin()) {
                throw new RuntimeException('الحذف للمدير العام فقط.');
            }
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('DELETE FROM sector_allocations WHERE id=?')->execute([$id]);
            audit('delete', 'allocations', $id);
            $msg = 'تم حذف السجل.';
        }
    } catch (Throwable $ex) {
        $err = $ex->getMessage();
    }
}

$bal = sector_balances($scope);
$entries = [];
if ($scope) {
    $q = db()->prepare('SELECT * FROM sector_allocations WHERE sector IN (' . ph($scope) . ') ORDER BY alloc_date DESC,id DESC LIMIT 60');
    $q->execute($scope);
    $entries = $q->fetchAll();
}
$pkgs = db()->query("SELECT DISTINCT package FROM beneficiaries ORDER BY package LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
echo flash_box($msg) . flash_box($err, 'err');
?>
<section class="panel">
  <div class="panel-head"><h2>أرصدة القطاعات</h2><span class="tag">المتبقي = المرصّد − عدد التسليمات المسجّلة لنفس الطرد</span></div>
  <div class="table-wrap"><table><thead><tr><th>القطاع</th><th>النوع</th><th>الطرد</th><th>المرصّد</th><th>المسلَّم</th><th>المتبقي</th><th>الاستهلاك</th></tr></thead><tbody>
  <?php foreach ($bal as $b): $pct = $b['allocated'] > 0 ? min(100, (int)round($b['distributed'] / $b['allocated'] * 100)) : 0; ?>
    <tr><td><b><?= e($b['sector']) ?></b></td><td><?= e($catNames[$b['category']] ?? '') ?></td><td><?= e($b['package']) ?></td><td><?= (int)$b['allocated'] ?></td><td><?= (int)$b['distributed'] ?></td>
      <td><b class="<?= $b['remaining'] < 0 ? 'neg' : '' ?>"><?= (int)$b['remaining'] ?></b></td>
      <td style="min-width:140px"><span class="bar-track"><i style="width:<?= $pct ?>%"></i></span></td></tr>
  <?php endforeach; ?>
  <?php if (!$bal): ?><tr><td colspan="7">لا توجد ترصيدات بعد.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

<?php if (can('allocations', 'create')): ?>
<section class="panel">
  <div class="panel-head"><h2>ترصيد جديد لقطاع</h2></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="alloc_add">
    <div class="form-grid">
      <label class="field"><span>القطاع</span><select name="sector"><?php foreach ($scope as $s): if (!can('allocations', 'create', $s)) { continue; } ?><option><?= e($s) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>النوع</span><select name="category"><?php foreach ($catNames as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>الطرد (نفس الاسم المستخدم في التسليم)</span><input name="package" list="pk" required></label>
      <label class="field"><span>الكمية</span><input type="number" name="quantity" required></label>
      <label class="field"><span>التاريخ</span><input type="date" name="alloc_date" value="<?= e(date('Y-m-d')) ?>" required></label>
      <label class="field"><span>ملاحظة</span><input name="note"></label>
    </div>
    <datalist id="pk"><?php foreach ($pkgs as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
    <button class="primary">حفظ الترصيد</button>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel-head"><h2>سجل الترصيدات</h2></div>
  <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>القطاع</th><th>النوع</th><th>الطرد</th><th>الكمية</th><th>ملاحظة</th><?php if (is_admin()): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($entries as $x): ?>
    <tr><td><?= e($x['alloc_date']) ?></td><td><?= e($x['sector']) ?></td><td><?= e($catNames[$x['category']] ?? '') ?></td><td><?= e($x['package']) ?></td><td><?= (int)$x['quantity'] ?></td><td><?= e($x['note']) ?></td>
    <?php if (is_admin()): ?><td><form method="post" onsubmit="return confirm('حذف هذا السجل؟')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="alloc_delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="text-button">حذف</button></form></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if (!$entries): ?><tr><td colspan="7">لا توجد سجلات.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
