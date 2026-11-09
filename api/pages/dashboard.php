<?php
declare(strict_types=1);
/* Included from index.php – role-aware dashboards. */

$role = role();
$catNames = ['Food' => 'غذائية', 'Financial' => 'نقدية', 'Logistics' => 'لوجستية'];
$money = fn($v): string => number_format((float)$v, 2);

/* Sum helper over sector_summary rows. */
$sumRows = function (array $rows): array {
    $t = array_fill_keys(array_keys(snapshot_fields()), 0);
    foreach ($rows as $r) {
        foreach ($t as $k => $_) {
            $t[$k] += (int)$r[$k];
        }
    }
    return $t;
};
$openTickets = function (): int {
    if (!can('tickets', 'view')) {
        return 0;
    }
    if (is_admin()) {
        return (int)db()->query("SELECT COUNT(*) FROM tickets WHERE status IN ('new','in_review')")->fetchColumn();
    }
    $s = allowed_module_sectors('tickets');
    if (!$s) {
        return 0;
    }
    $q = db()->prepare("SELECT COUNT(*) FROM tickets WHERE status IN ('new','in_review') AND sector IN (" . ph($s) . ')');
    $q->execute($s);
    return (int)$q->fetchColumn();
};
?>
<?php if (!is_admin()): ?>
<section class="hero-lux">
  <div>
    <span class="eyebrow"><?= e(role_label($role)) ?></span>
    <h1>مرحباً، <?= e(user()['full_name']) ?></h1>
    <p><?= e(date('Y-m-d')) ?> · لوحة المتابعة الخاصة بصلاحياتك</p>
  </div>
  <div class="hero-orb">YS</div>
</section>
<?php endif; ?>

<?php
/* =========================================================
 * GENERAL MANAGER
 * ========================================================= */
if (is_admin()):
    require __DIR__ . '/dash_admin.php';

elseif ($role === 'sector_manager'):
    $aq = db()->prepare('SELECT assigned_sectors FROM users WHERE id=?');
    $aq->execute([user()['id']]);
    $assigned = array_values(array_filter(explode(',', (string)$aq->fetchColumn())));
    $assigned = array_values(array_intersect(['A', 'B', 'C', 'D', 'E', 'S'], $assigned));
    if (!$assigned) {
        echo '<section class="panel"><p class="empty">لم يتم تعيين قطاع لحسابك بعد. تواصل مع المدير العام.</p></section>';
    } else {
        $sec = in_array($_GET['sec'] ?? '', $assigned, true) ? (string)$_GET['sec'] : $assigned[0];
        $cat = isset($catNames[$_GET['cat'] ?? '']) ? (string)$_GET['cat'] : 'Food';
        $rows = sector_summary(expand_sectors([$sec]));
        $t = $sumRows($rows);
        $balances = sector_balances(array_values(array_intersect($assigned, ['A', 'B', 'C', 'D', 'S'])));
        $next = next_candidates($sec, $cat, 8);
        $tk = db()->prepare('SELECT id,full_name,subject,status,created_at FROM tickets WHERE sector IN (' . ph(expand_sectors([$sec])) . ') ORDER BY id DESC LIMIT 6');
        $tk->execute(expand_sectors([$sec]));
        $tickets = $tk->fetchAll(); ?>
<form method="get" class="filter-toolbar">
  <input type="hidden" name="page" value="dashboard">
  <label>القطاع <select name="sec" onchange="this.form.submit()"><?php foreach ($assigned as $s): ?><option <?= $s === $sec ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
  <label>نوع المساعدة <select name="cat" onchange="this.form.submit()"><?php foreach ($catNames as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $cat ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
</form>

<div class="kpi-grid">
  <?= kpi('أسر القطاع ' . $sec, $t['families']) ?>
  <?= kpi('الأفراد', $t['people']) ?>
  <?= kpi('الخيام', $t['tents']) ?>
  <?= kpi('لم يستلموا (' . $catNames[$cat] . ')', never_received_count($sec, $cat), 'أسر بانتظار أول استلام', 'gold') ?>
  <?= kpi('شكاوى مفتوحة', $openTickets(), '', 'gold') ?>
</div>

<?php
    $vcs = vulnerability_counts(expand_sectors([$sec]));
    $stq = db()->prepare('SELECT COALESCE(social_status,\'\') s,COUNT(*) c FROM profiles WHERE archived_at IS NULL AND sector IN (' . ph(expand_sectors([$sec])) . ') AND ' . ACTIVE_SQL . ' GROUP BY s ORDER BY c DESC');
    $stq->execute(expand_sectors([$sec]));
    $Lst = ['Married' => 'متزوج/ة', 'Single' => 'أعزب/عزباء', 'Widow' => 'أرمل/ة', 'Divorced' => 'مطلّق/ة', 'Abandoned' => 'مهجور/ة', 'Abroad' => 'خارج البلاد'];
    $stData = [];
    foreach ($stq->fetchAll() as $x) { $stData[$Lst[$x['s']] ?? ($x['s'] !== '' ? $x['s'] : 'غير محدد')] = (int)$x['c']; } ?>
<div class="cols-3">
  <section class="panel"><div class="panel-head"><h2>الجنس</h2></div><?= svg_donut(['ذكور' => $t['males'], 'إناث' => $t['females']], nfmt($t['people']), 'فرد') ?></section>
  <section class="panel"><div class="panel-head"><h2>الحالة الاجتماعية</h2></div><?= svg_donut($stData, nfmt(array_sum($stData)), 'فرد') ?></section>
  <section class="panel"><div class="panel-head"><h2>الفئات الأكثر احتياجاً</h2></div>
    <div class="vuln-grid compact"><?php foreach ([['orphan','heart','أيتام'],['widow','female','أرامل'],['divorced','users','مطلقات'],['disabled','plus','إعاقة'],['elderly','elder','كبار سن'],['infant','child','رضّع']] as [$k,$ic,$lb]): ?>
      <a class="vuln" href="<?= e(search_url([$k], ['sec[]' => $sec])) ?>"><span class="vi"><?= icon($ic, 20) ?></span><b><?= nfmt($vcs[$k]) ?></b><em><?= e($lb) ?></em></a><?php endforeach; ?></div></section>
</div>

<div class="cols-2">
  <section class="panel"><div class="panel-head"><h2>الترشيحات القادمة · <?= e($catNames[$cat]) ?></h2><a href="?page=candidates&sector=<?= e($sec) ?>&category=<?= e($cat) ?>">القائمة الكاملة</a></div>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>الخيمة</th><th>الاسم</th><th>آخر استلام</th></tr></thead><tbody>
    <?php foreach ($next as $i => $c): ?><tr><td><?= $i + 1 ?></td><td><?= e($c['tent']) ?></td><td><?= e($c['full_name']) ?></td><td><?= e($c['last_date'] ?? 'لم يستلم') ?></td></tr><?php endforeach; ?>
    <?php if (!$next): ?><tr><td colspan="4">لا يوجد مرشحون.</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="hint">الترتيب: من لم يستلم أولاً، ثم أقدم استلام، ثم تاريخ إضافة الأسرة.</p></section>

  <section class="panel"><div class="panel-head"><h2>أرصدة القطاع</h2><a href="?page=allocations">التفاصيل</a></div>
    <div class="table-wrap"><table><thead><tr><th>القطاع</th><th>الطرد</th><th>المرصّد</th><th>المسلَّم</th><th>المتبقي</th></tr></thead><tbody>
    <?php foreach ($balances as $b): ?><tr><td><?= e($b['sector']) ?></td><td><?= e($b['package']) ?></td><td><?= (int)$b['allocated'] ?></td><td><?= (int)$b['distributed'] ?></td><td><b class="<?= $b['remaining'] < 0 ? 'neg' : '' ?>"><?= (int)$b['remaining'] ?></b></td></tr><?php endforeach; ?>
    <?php if (!$balances): ?><tr><td colspan="5">لا توجد ترصيدات مسجّلة لقطاعك.</td></tr><?php endif; ?>
    </tbody></table></div></section>
</div>

<div class="cols-2">
  <section class="panel"><div class="panel-head"><h2>التركيبة العمرية</h2></div>
    <?= bars(['أقل من سنة' => $t['c0_1'], '1–5 سنوات' => $t['c1_5'], '5–12 سنة' => $t['c5_12'], '12–18 سنة' => $t['c12_18'], '60+ سنة' => $t['elderly']]) ?></section>
  <section class="panel"><div class="panel-head"><h2>رسائل أسر القطاع</h2><a href="?page=tickets">الكل</a></div>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>الاسم</th><th>العنوان</th><th>الحالة</th></tr></thead><tbody>
    <?php foreach ($tickets as $x): ?><tr><td><a href="?page=tickets&id=<?= (int)$x['id'] ?>"><?= e(ticket_code((int)$x['id'])) ?></a></td><td><?= e($x['full_name']) ?></td><td><?= e($x['subject']) ?></td><td><?= status_badge($x['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$tickets): ?><tr><td colspan="4">لا توجد رسائل.</td></tr><?php endif; ?>
    </tbody></table></div></section>
</div>
<?php }
/* =========================================================
 * FINANCE
 * ========================================================= */
elseif ($role === 'finance'):
    $fin = db()->query("SELECT flow,SUM(amount) s FROM finance GROUP BY flow")->fetchAll(PDO::FETCH_KEY_PAIR);
    $inc = (float)($fin['Income'] ?? 0);
    $exp = (float)($fin['Expenses'] ?? 0);
    $mq = db()->query("SELECT flow,SUM(amount) s FROM finance WHERE transaction_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY flow")->fetchAll(PDO::FETCH_KEY_PAIR);
    $monthly = db()->query("SELECT DATE_FORMAT(transaction_date,'%Y-%m') m,flow,SUM(amount) s FROM finance WHERE transaction_date>=DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 5 MONTH) GROUP BY m,flow ORDER BY m")->fetchAll();
    $mi = [];
    $me = [];
    foreach ($monthly as $r) {
        if ($r['flow'] === 'Income') {
            $mi[$r['m']] = (float)$r['s'];
        } else {
            $me[$r['m']] = (float)$r['s'];
        }
    }
    $cats = db()->query("SELECT category,SUM(amount) s FROM finance WHERE flow='Expenses' GROUP BY category ORDER BY s DESC LIMIT 6")->fetchAll(PDO::FETCH_KEY_PAIR);
    $recent = db()->query("SELECT transaction_date,flow,category,item,amount FROM finance ORDER BY transaction_date DESC,id DESC LIMIT 8")->fetchAll(); ?>
<div class="kpi-grid">
  <?= kpi('إجمالي الإيرادات', $money($inc), '', 'green') ?>
  <?= kpi('إجمالي المصروفات', $money($exp), '', 'red') ?>
  <?= kpi('الرصيد', $money($inc - $exp), '', ($inc - $exp) < 0 ? 'red' : 'green') ?>
  <?= kpi('إيرادات هذا الشهر', $money($mq['Income'] ?? 0)) ?>
  <?= kpi('مصروفات هذا الشهر', $money($mq['Expenses'] ?? 0)) ?>
</div>
<div class="cols-2">
  <section class="panel"><div class="panel-head"><h2>آخر 6 أشهر</h2></div>
    <?php $ml = []; for ($i = 5; $i >= 0; $i--) { $ml[] = date('Y-m', strtotime("first day of -$i month")); }
    echo svg_columns(array_map(fn($m) => substr($m, 2), $ml), ['إيرادات' => array_map(fn($m) => round($mi[$m] ?? 0), $ml), 'مصروفات' => array_map(fn($m) => round($me[$m] ?? 0), $ml)]); ?></section>
  <section class="panel"><div class="panel-head"><h2>أعلى بنود المصروفات</h2></div><?= bars(array_map(fn($v) => round((float)$v, 2), $cats)) ?></section>
</div>
<section class="panel"><div class="panel-head"><h2>آخر الحركات</h2><a href="?page=finance">فتح المالية</a></div>
  <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>النوع</th><th>البند</th><th>التفصيل</th><th>المبلغ</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?><tr><td><?= e($r['transaction_date']) ?></td><td><?= $r['flow'] === 'Income' ? badge('إيراد', 'green') : badge('مصروف', 'red') ?></td><td><?= e($r['category']) ?></td><td><?= e($r['item']) ?></td><td><?= e($money($r['amount'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="5">لا توجد حركات.</td></tr><?php endif; ?>
  </tbody></table></div></section>

<?php
/* =========================================================
 * RELIEF
 * ========================================================= */
elseif ($role === 'relief'):
    $today = (int)db()->query("SELECT COUNT(*) FROM beneficiaries WHERE delivery_date=CURDATE() AND distribution_type='NORMAL'")->fetchColumn();
    $month = (int)db()->query("SELECT COUNT(*) FROM beneficiaries WHERE delivery_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND distribution_type='NORMAL'")->fetchColumn();
    $byCat = db()->query("SELECT category,COUNT(*) c FROM beneficiaries WHERE delivery_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) AND distribution_type='NORMAL' GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);
    $stock = db()->query("SELECT item_name,unit,SUM(in_qty) i,SUM(out_qty) o FROM warehouse_items GROUP BY item_name,unit ORDER BY (SUM(in_qty)-SUM(out_qty)) ASC LIMIT 10")->fetchAll();
    $aidIssues = (int)db()->query("SELECT COUNT(*) FROM tickets WHERE ticket_type='aid_issue' AND status IN ('new','in_review')")->fetchColumn();
    $bal = sector_balances(['A', 'B', 'C', 'D', 'S']);
    $lowCount = 0;
    foreach ($stock as $s) {
        if ((float)$s['i'] - (float)$s['o'] <= 0) {
            $lowCount++;
        }
    } ?>
<div class="kpi-grid">
  <?= kpi('تسليمات اليوم', $today, '', 'green') ?>
  <?= kpi('تسليمات هذا الشهر', $month) ?>
  <?= kpi('مشاكل استلام مفتوحة', $aidIssues, 'من النازحين', 'gold') ?>
  <?= kpi('أصناف نفدت', $lowCount, 'ضمن أقل 10 أصناف رصيداً', $lowCount ? 'red' : '') ?>
</div>
<div class="cols-2">
  <section class="panel"><div class="panel-head"><h2>التسليمات (آخر 30 يوماً)</h2></div>
    <?= bars(['غذائية' => (int)($byCat['Food'] ?? 0), 'نقدية' => (int)($byCat['Financial'] ?? 0), 'لوجستية' => (int)($byCat['Logistics'] ?? 0)]) ?>
    <p><a class="primary" href="?page=beneficiaries">تسجيل توزيع</a> <a class="secondary" href="?page=candidates">المرشحون</a></p></section>
  <section class="panel"><div class="panel-head"><h2>أرصدة المستودع (الأقل أولاً)</h2><a href="?page=storage">المستودعات</a></div>
    <div class="table-wrap"><table><thead><tr><th>الصنف</th><th>وارد</th><th>صادر</th><th>الرصيد</th></tr></thead><tbody>
    <?php foreach ($stock as $s): $r = (float)$s['i'] - (float)$s['o']; ?><tr><td><?= e($s['item_name']) ?></td><td><?= e($s['i']) ?></td><td><?= e($s['o']) ?></td><td><b class="<?= $r <= 0 ? 'neg' : '' ?>"><?= e((string)$r) ?> <?= e($s['unit']) ?></b></td></tr><?php endforeach; ?>
    <?php if (!$stock): ?><tr><td colspan="4">لا توجد أصناف.</td></tr><?php endif; ?>
    </tbody></table></div></section>
</div>
<?php
    $rdays = [];
    for ($i = 29; $i >= 0; $i--) { $rdays[] = date('Y-m-d', strtotime("-$i day")); }
    $rm = [];
    foreach (db()->query("SELECT delivery_date d,COUNT(*) n FROM beneficiaries WHERE delivery_date>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND distribution_type='NORMAL' GROUP BY d") as $x) { $rm[$x['d']] = (int)$x['n']; } ?>
<section class="panel"><div class="panel-head"><h2>التسليمات اليومية · آخر 30 يوماً</h2></div><?= svg_line(array_map(fn($d) => substr($d, 5), $rdays), ['تسليمات' => array_map(fn($d) => $rm[$d] ?? 0, $rdays)]) ?></section>

<section class="panel"><div class="panel-head"><h2>ترصيد القطاعات</h2><a href="?page=allocations">إدارة الترصيد</a></div>
  <div class="table-wrap"><table><thead><tr><th>القطاع</th><th>النوع</th><th>الطرد</th><th>المرصّد</th><th>المسلَّم</th><th>المتبقي</th></tr></thead><tbody>
  <?php foreach ($bal as $b): ?><tr><td><?= e($b['sector']) ?></td><td><?= e($catNames[$b['category']] ?? '') ?></td><td><?= e($b['package']) ?></td><td><?= (int)$b['allocated'] ?></td><td><?= (int)$b['distributed'] ?></td><td><b class="<?= $b['remaining'] < 0 ? 'neg' : '' ?>"><?= (int)$b['remaining'] ?></b></td></tr><?php endforeach; ?>
  <?php if (!$bal): ?><tr><td colspan="6">لا توجد ترصيدات.</td></tr><?php endif; ?>
  </tbody></table></div></section>

<?php
/* =========================================================
 * CUSTOM STAFF (fallback)
 * ========================================================= */
else: ?>
<section class="panel"><h2>الأقسام المتاحة لك</h2><div class="quick-grid">
  <?php foreach (modules() as $m => $label): if (!can($m, 'view')) { continue; } ?>
    <a class="quick-card" href="?page=<?= e($m) ?>"><span class="quick-icon"><?= e(strtoupper(substr($m, 0, 2))) ?></span><b><?= e(t($m)) ?></b><span>فتح القسم ←</span></a>
  <?php endforeach; ?>
</div></section>
<?php endif; ?>
