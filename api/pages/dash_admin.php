<?php
declare(strict_types=1);
/* Included from pages/dashboard.php (general manager). Shares its scope: $sumRows, $money, $openTickets, $catNames. */

$allSectors = array_values(array_diff(sectors(), ['R']));
$rows = sector_summary($allSectors);
$tot = $sumRows($rows);
$vc = vulnerability_counts($allSectors);
$pdo = db();

/* ---------- Social status ---------- */
$L_st = ['Married' => 'متزوج/ة', 'Single' => 'أعزب/عزباء', 'Widow' => 'أرمل/ة', 'Divorced' => 'مطلّق/ة', 'Abandoned' => 'مهجور/ة', 'Abroad' => 'خارج البلاد'];
$statusData = [];
foreach ($pdo->query('SELECT COALESCE(social_status,\'\') s,COUNT(*) c FROM profiles WHERE archived_at IS NULL AND sector<>\'R\' AND ' . ACTIVE_SQL . ' GROUP BY s ORDER BY c DESC') as $r) {
    $statusData[$L_st[$r['s']] ?? ($r['s'] !== '' ? $r['s'] : 'غير محدد')] = (int)$r['c'];
}

/* ---------- Age pyramid ---------- */
$bands = ['0–4', '5–11', '12–17', '18–29', '30–44', '45–59', '60+'];
$pyr = array_fill_keys($bands, [0, 0]);
$sqlAge = 'SELECT CASE WHEN a<5 THEN 0 WHEN a<12 THEN 1 WHEN a<18 THEN 2 WHEN a<30 THEN 3 WHEN a<45 THEN 4 WHEN a<60 THEN 5 ELSE 6 END band,g,COUNT(*) c FROM (SELECT TIMESTAMPDIFF(YEAR,birth_date,CURDATE()) a,gender g FROM profiles WHERE archived_at IS NULL AND sector<>\'R\' AND birth_date IS NOT NULL AND ' . ACTIVE_SQL . ') t GROUP BY band,g';
foreach ($pdo->query($sqlAge) as $r) {
    $k = $bands[(int)$r['band']];
    if ($r['g'] === 'Male') {
        $pyr[$k][0] += (int)$r['c'];
    } elseif ($r['g'] === 'Female') {
        $pyr[$k][1] += (int)$r['c'];
    }
}

/* ---------- Deliveries, last 30 days ---------- */
$days = [];
for ($i = 29; $i >= 0; $i--) {
    $days[] = date('Y-m-d', strtotime("-$i day"));
}
$dm = [];
foreach ($pdo->query("SELECT delivery_date d,category c,COUNT(*) n FROM beneficiaries WHERE delivery_date>=DATE_SUB(CURDATE(),INTERVAL 29 DAY) AND distribution_type='NORMAL' GROUP BY d,c") as $r) {
    $dm[$r['c']][$r['d']] = (int)$r['n'];
}
$dSeries = ['غذائية' => [], 'نقدية' => [], 'لوجستية' => []];
$dTotalDay = [];
foreach ($days as $d) {
    $f = $dm['Food'][$d] ?? 0;
    $fi = $dm['Financial'][$d] ?? 0;
    $lo = $dm['Logistics'][$d] ?? 0;
    $dSeries['غذائية'][] = $f;
    $dSeries['نقدية'][] = $fi;
    $dSeries['لوجستية'][] = $lo;
    $dTotalDay[] = $f + $fi + $lo;
}
$dLabels = array_map(fn($d) => substr($d, 5), $days);
$todayDist = (int)end($dTotalDay);
$monthDist = (int)$pdo->query("SELECT COUNT(*) FROM beneficiaries WHERE delivery_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND distribution_type='NORMAL'")->fetchColumn();

/* ---------- Coverage gauge (households served in the last 30 days) ---------- */
$heads = (int)$pdo->query("SELECT COUNT(*) FROM profiles p WHERE p.archived_at IS NULL AND p.sector<>'R' AND p.is_household_head=1 AND " . ACTIVE_SQL)->fetchColumn();
$served = (int)$pdo->query("SELECT COUNT(*) FROM profiles p WHERE p.archived_at IS NULL AND p.sector<>'R' AND p.is_household_head=1 AND " . ACTIVE_SQL . " AND EXISTS (SELECT 1 FROM beneficiaries b WHERE b.national_id=p.national_id AND b.distribution_type='NORMAL' AND b.delivery_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY))")->fetchColumn();
$coverage = $heads > 0 ? $served / $heads * 100 : 0;

/* ---------- Finance ---------- */
$fin = $pdo->query('SELECT flow,SUM(amount) s FROM finance GROUP BY flow')->fetchAll(PDO::FETCH_KEY_PAIR);
$balance = (float)($fin['Income'] ?? 0) - (float)($fin['Expenses'] ?? 0);
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("first day of -$i month"));
}
$fm = ['Income' => [], 'Expenses' => []];
foreach ($pdo->query("SELECT DATE_FORMAT(transaction_date,'%Y-%m') m,flow,SUM(amount) s FROM finance WHERE transaction_date>=DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 5 MONTH) GROUP BY m,flow") as $r) {
    $fm[$r['flow']][$r['m']] = (float)$r['s'];
}
$finIn = array_map(fn($m) => round($fm['Income'][$m] ?? 0), $months);
$finOut = array_map(fn($m) => round($fm['Expenses'][$m] ?? 0), $months);

/* ---------- Tickets / requests ---------- */
$tk = array_fill_keys(array_keys(ticket_statuses()), 0);
foreach ($pdo->query('SELECT status,COUNT(*) c FROM tickets GROUP BY status') as $r) {
    $tk[$r['status']] = (int)$r['c'];
}
$tkData = [];
foreach (ticket_statuses() as $k => $l) {
    $tkData[$l] = $tk[$k];
}
$tkSpark = array_fill(0, 14, 0);
foreach ($pdo->query('SELECT DATEDIFF(CURDATE(),DATE(created_at)) ago,COUNT(*) c FROM tickets WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY ago') as $r) {
    $tkSpark[13 - (int)$r['ago']] = (int)$r['c'];
}
$rq = array_fill_keys(['pending', 'fulfilled', 'rejected'], 0);
foreach ($pdo->query('SELECT status,COUNT(*) c FROM data_requests GROUP BY status') as $r) {
    $rq[$r['status']] = (int)$r['c'];
}
$recentT = $pdo->query('SELECT id,full_name,sector,subject,status,ticket_type FROM tickets ORDER BY id DESC LIMIT 6')->fetchAll();

/* ---------- Sectors table ---------- */
$bySec = [];
foreach ($rows as $r) {
    $bySec[$r['sector']] = $r;
}
$perSector = [];
foreach ($rows as $r) {
    $perSector[$r['sector']] = (int)$r['people'];
}
arsort($perSector);

/* ---------- Stock + activity ---------- */
$stock = $pdo->query('SELECT item_name,unit,SUM(in_qty)-SUM(out_qty) bal FROM warehouse_items GROUP BY item_name,unit ORDER BY bal ASC LIMIT 6')->fetchAll();
$stockBars = [];
foreach ($stock as $s) {
    $stockBars[$s['item_name'] . ' (' . $s['unit'] . ')'] = max(0, (float)$s['bal']);
}
$acts = $pdo->query('SELECT a.created_at,u.username,a.action,a.module,a.details FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 8')->fetchAll();
$openT = $openTickets();
?>
<section class="hero-lux art">
  <div class="hero-art"><?= camp_art() ?></div>
  <div class="hero-text">
    <span class="eyebrow"><?= e(role_label(role())) ?> · <?= e(date('Y-m-d')) ?></span>
    <h1>مرحباً، <?= e(user()['full_name']) ?></h1>
    <p>نظرة شاملة على المخيم: السكان، الاحتياجات، التوزيعات، المالية، والمراسلات — لحظة بلحظة.</p>
    <div class="btn-row hero-actions">
      <a class="primary gold-btn" href="?page=search">البحث المتقدم</a>
      <a class="secondary glass" href="?page=beneficiaries">تسجيل توزيع</a>
      <a class="secondary glass" href="?page=tickets">الشكاوى (<?= $openT ?>)</a>
    </div>
  </div>
</section>

<div class="kpi-x-grid">
  <?= kpi_x('home', 'الأسر', nfmt($tot['families']), $heads . ' ربّ أسرة نشط') ?>
  <?= kpi_x('users', 'الأفراد', nfmt($tot['people']), 'دون الشهداء والمتوفين والمفقودين') ?>
  <?= kpi_x('tent', 'الخيام', nfmt($tot['tents']), count($rows) . ' قطاعات') ?>
  <?= kpi_x('gift', 'استلامات اليوم', nfmt($todayDist), nfmt($monthDist) . ' هذا الشهر', spark(array_slice($dTotalDay, -14)), 'green') ?>
  <?= kpi_x('mail', 'شكاوى مفتوحة', nfmt($openT), nfmt($tk['resolved']) . ' تم حلها', spark($tkSpark, '#b8433a'), 'gold') ?>
  <?= kpi_x('building', 'طلبات المؤسسات', nfmt($rq['pending']), 'معلّقة · ' . $rq['fulfilled'] . ' منفّذة', '', 'gold') ?>
  <?= kpi_x('wallet', 'الرصيد المالي', $money($balance), 'إيرادات − مصروفات', spark($finIn, '#1f8a5b'), $balance < 0 ? 'red' : 'green') ?>
  <?= kpi_x('shield', 'تغطية آخر 30 يوماً', round($coverage) . '%', $served . ' من ' . $heads . ' أسرة', '', $coverage < 40 ? 'red' : 'green') ?>
</div>

<div class="cols-3">
  <section class="panel"><div class="panel-head"><h2>توزيع الجنس</h2></div><?= svg_donut(['ذكور' => $tot['males'], 'إناث' => $tot['females']], nfmt($tot['people']), 'فرد') ?></section>
  <section class="panel"><div class="panel-head"><h2>الحالة الاجتماعية</h2></div><?= svg_donut($statusData, nfmt(array_sum($statusData)), 'فرد') ?></section>
  <section class="panel"><div class="panel-head"><h2>تغطية المساعدات</h2></div><?= svg_gauge($coverage, 'نسبة الأسر المستفيدة خلال 30 يوماً') ?>
    <div class="mini-row"><div><b><?= nfmt($served) ?></b><span>استلمت</span></div><div><b><?= nfmt(max(0, $heads - $served)) ?></b><span>لم تستلم</span></div></div></section>
</div>

<section class="panel">
  <div class="panel-head"><h2>الفئات الأكثر احتياجاً</h2><span class="tag">اضغط على أي بطاقة لعرض الأسماء</span></div>
  <div class="vuln-grid">
    <?php
    $cards = [
        ['orphan', 'heart', 'أيتام', []], ['widow', 'female', 'أرامل', []], ['divorced', 'users', 'مطلّقات / مطلّقون', []], ['abandoned', 'alert', 'مهجورات', []],
        ['female_head', 'female', 'أسر ترأسها نساء', ['head' => 'yes']], ['martyr_family', 'shield', 'أسر شهداء', ['head' => 'yes']],
        ['elderly', 'elder', 'كبار السن 60+', []], ['disabled', 'plus', 'ذوو إعاقة', []], ['chronic', 'plus', 'أمراض مزمنة', []], ['injured', 'alert', 'جرحى', []],
        ['pregnant', 'child', 'حوامل', []], ['breastfeeding', 'child', 'مرضعات', []], ['infant', 'child', 'رضّع (أقل من سنة)', []], ['under5', 'child', 'أطفال دون 5', []],
    ];
    foreach ($cards as [$k, $ic, $lb, $ex]): ?>
      <a class="vuln" href="<?= e(search_url([$k], $ex)) ?>"><span class="vi"><?= icon($ic, 22) ?></span><b><?= nfmt($vc[$k] ?? 0) ?></b><em><?= e($lb) ?></em></a>
    <?php endforeach; ?>
  </div>
</section>

<div class="cols-2">
  <section class="panel"><div class="panel-head"><h2>الهرم العمري</h2></div><?= svg_pyramid($pyr) ?></section>
  <section class="panel"><div class="panel-head"><h2>الأفراد حسب القطاع</h2></div><?= bars($perSector) ?>
    <div class="table-wrap slim"><table><thead><tr><th>القطاع</th><th>أسر</th><th>أفراد</th><th>خيام</th><th>أطفال &lt;18</th><th>لم تستلم غذائياً</th></tr></thead><tbody>
    <?php foreach (['A', 'B', 'C', 'D', 'S'] as $s): $r = $bySec[$s] ?? null; if (!$r) { continue; }
        $kids = (int)$r['c0_1'] + (int)$r['c1_5'] + (int)$r['c5_12'] + (int)$r['c12_18']; ?>
      <tr><td><b><?= e($s) ?></b></td><td><?= nfmt($r['families']) ?></td><td><?= nfmt($r['people']) ?></td><td><?= nfmt($r['tents']) ?></td><td><?= nfmt($kids) ?></td><td><?= nfmt(never_received_count($s, 'Food')) ?></td></tr>
    <?php endforeach; ?></tbody></table></div></section>
</div>

<section class="panel"><div class="panel-head"><h2>التسليمات اليومية · آخر 30 يوماً</h2><span class="tag"><?= nfmt(array_sum($dTotalDay)) ?> تسليم</span></div><?= svg_line($dLabels, $dSeries) ?></section>

<div class="cols-2">
  <section class="panel"><div class="panel-head"><h2>المالية · آخر 6 أشهر</h2><a href="?page=finance">فتح المالية</a></div>
    <?= svg_columns(array_map(fn($m) => substr($m, 2), $months), ['إيرادات' => $finIn, 'مصروفات' => $finOut]) ?></section>
  <section class="panel"><div class="panel-head"><h2>أصناف المستودع الأقل رصيداً</h2><a href="?page=storage">المستودعات</a></div>
    <?= $stockBars ? bars($stockBars) : '<p class="empty">لا توجد أصناف مسجلة.</p>' ?></section>
</div>

<div class="cols-3">
  <section class="panel"><div class="panel-head"><h2>الشكاوى حسب الحالة</h2></div><?= svg_donut($tkData, nfmt(array_sum($tkData)), 'رسالة') ?></section>
  <section class="panel col-span-2"><div class="panel-head"><h2>أحدث الرسائل</h2><a href="?page=tickets">عرض الكل</a></div>
    <div class="table-wrap slim"><table><thead><tr><th>#</th><th>الاسم</th><th>القطاع</th><th>العنوان</th><th>الحالة</th></tr></thead><tbody>
    <?php foreach ($recentT as $t): ?><tr><td><a href="?page=tickets&id=<?= (int)$t['id'] ?>"><?= e(ticket_code((int)$t['id'])) ?></a></td><td><?= e($t['full_name']) ?></td><td><?= e($t['sector']) ?></td><td><?= e($t['subject']) ?></td><td><?= status_badge($t['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$recentT): ?><tr><td colspan="5">لا توجد رسائل.</td></tr><?php endif; ?></tbody></table></div></section>
</div>

<section class="panel"><div class="panel-head"><h2>آخر النشاطات</h2><a href="?page=audit">السجل الكامل</a></div>
  <ul class="timeline">
  <?php foreach ($acts as $o): ?><li><i></i><div><b><?= e($o['username'] ?: 'النظام') ?></b> · <?= e($o['action']) ?> · <?= e($o['module']) ?><?= $o['details'] ? ' — ' . e(mb_substr((string)$o['details'], 0, 80)) : '' ?><small><?= e($o['created_at']) ?></small></div></li><?php endforeach; ?>
  <?php if (!$acts): ?><li><div>لا يوجد نشاط بعد.</div></li><?php endif; ?>
  </ul></section>
