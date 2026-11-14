<?php
declare(strict_types=1);
/* Included from index.php – advanced search & filters over families / individuals. */

if (!can('profiles', 'view')) {
    echo '<section class="panel">لا تملك صلاحية عرض ملفات الأسر.</section>';
    return;
}

/* ---------- Labels ---------- */
$L_status = ['Married' => 'متزوج/ة', 'Single' => 'أعزب/عزباء', 'Widow' => 'أرمل/ة', 'Divorced' => 'مطلّق/ة', 'Abandoned' => 'مهجور/ة', 'Abroad' => 'خارج البلاد',
    'Shahid' => 'شهيد', 'Dead' => 'متوفى', 'Missing' => 'مفقود', 'Detained' => 'معتقل', 'Absent' => 'غائب'];
$L_member = ['Husband' => 'الزوج', 'Wife' => 'الزوجة', 'Son' => 'ابن', 'Daughter' => 'ابنة'];
$L_house = ['Full Damage' => 'ضرر كلي', 'Partial Damage' => 'ضرر جزئي', 'Healthy' => 'سليم', 'No Place' => 'لا مأوى'];
$L_ttype = ['Original' => 'أصلية', 'Collected' => 'مجمّعة', 'Building Part' => 'جزء من مبنى'];
$L_tcond = ['Good' => 'جيدة', 'Fair' => 'متوسطة', 'Worn out' => 'متهالكة'];
$L_cat = ['Food' => 'غذائية', 'Financial' => 'نقدية', 'Logistics' => 'لوجستية'];

/* ---------- Read inputs ---------- */
$gs = fn(string $k, string $d = ''): string => trim((string)($_GET[$k] ?? $d));
$ga = fn(string $k): array => array_values(array_filter(array_map('strval', (array)($_GET[$k] ?? [])), 'strlen'));
$ck = fn(array $a, string $v): string => in_array($v, $a, true) ? 'checked' : '';

$scope = scope_sectors();
$useSec = array_values(array_intersect($scope, $ga('sec')));
if (!$useSec) {
    $useSec = $scope;
}
$flagsSel = array_values(array_intersect(array_keys(flag_defs()), $ga('flags')));
$stSel = array_values(array_intersect(array_keys($L_status), $ga('st')));
$fmSel = array_values(array_intersect(array_keys($L_member), $ga('fm')));
$hsSel = array_values(array_intersect(array_keys($L_house), $ga('house')));
$q = $gs('q');
$ran = isset($_GET['go']) || $q !== '' || $flagsSel || $stSel;

$mayId = is_admin() || can('profiles', 'view_sensitive');

/* ---------- Build WHERE ---------- */
$w = ["p.archived_at IS NULL", "p.sector<>'R'"];
$a = [];
if ($useSec) {
    $w[] = 'p.sector IN (' . ph($useSec) . ')';
    array_push($a, ...$useSec);
} else {
    $w[] = '1=0';
}
if ($gs('inactive') !== '1') {
    $w[] = ACTIVE_SQL;
}
if ($q !== '') {
    foreach (preg_split('/\s+/u', ar_norm($q)) ?: [] as $word) {
        if ($word === '') {
            continue;
        }
        $like = '%' . $word . '%';
        $parts = [ar_col('p.full_name') . ' LIKE ?', ar_col('COALESCE(p.partner_name,\'\')') . ' LIKE ?', 'p.hh_id LIKE ?', 'p.tent LIKE ?'];
        $pa = [$like, $like, $like, $like];
        if ($mayId) {
            array_push($parts, 'p.national_id LIKE ?', 'p.phone LIKE ?', 'p.other_phone LIKE ?');
            array_push($pa, $like, $like, $like);
        }
        $w[] = '(' . implode(' OR ', $parts) . ')';
        array_push($a, ...$pa);
    }
}
if (in_array($gs('gender'), ['Male', 'Female'], true)) {
    $w[] = 'p.gender=?';
    $a[] = $gs('gender');
}
if (in_array($gs('host'), ['Host', 'Guest'], true)) {
    $w[] = 'p.hospitality=?';
    $a[] = $gs('host');
}
$unit = $gs('age_unit') === 'm' ? 'MONTH' : 'YEAR';
if ($gs('age_min') !== '' || $gs('age_max') !== '') {
    $w[] = 'p.birth_date IS NOT NULL';
    if ($gs('age_min') !== '') {
        $w[] = "TIMESTAMPDIFF($unit,p.birth_date,CURDATE())>=?";
        $a[] = max(0, (int)$gs('age_min'));
    }
    if ($gs('age_max') !== '') {
        $w[] = "TIMESTAMPDIFF($unit,p.birth_date,CURDATE())<=?";
        $a[] = max(0, (int)$gs('age_max'));
    }
}
if ($stSel) {
    $w[] = 'p.social_status IN (' . ph($stSel) . ')';
    array_push($a, ...$stSel);
}
if ($fmSel) {
    $w[] = 'p.family_member IN (' . ph($fmSel) . ')';
    array_push($a, ...$fmSel);
}
if ($gs('head') === 'yes') {
    $w[] = 'p.is_household_head=1';
} elseif ($gs('head') === 'no') {
    $w[] = 'p.is_household_head=0';
}
if ($flagsSel) {
    $frags = array_map(fn($k) => '(' . flag_defs()[$k][2] . ')', $flagsSel);
    $w[] = '(' . implode($gs('fmode') === 'any' ? ' OR ' : ' AND ', $frags) . ')';
}
if ($hsSel) {
    $w[] = 'p.house_status IN (' . ph($hsSel) . ')';
    array_push($a, ...$hsSel);
}
if (isset($L_ttype[$gs('ttype')])) {
    $w[] = 'p.tent_type=?';
    $a[] = $gs('ttype');
}
if (isset($L_tcond[$gs('tcond')])) {
    $w[] = 'p.tent_condition=?';
    $a[] = $gs('tcond');
}
foreach (['region' => 'p.region', 'tent' => 'p.tent', 'job' => 'p.job_position'] as $k => $col) {
    if ($gs($k) !== '') {
        $w[] = "$col LIKE ?";
        $a[] = '%' . $gs($k) . '%';
    }
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $gs('added_from'))) {
    $w[] = 'p.added_at>=?';
    $a[] = $gs('added_from');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $gs('added_to'))) {
    $w[] = 'p.added_at<=?';
    $a[] = $gs('added_to');
}
if ($gs('has_phone') === 'yes') {
    $w[] = "COALESCE(p.phone,'')<>''";
} elseif ($gs('has_phone') === 'no') {
    $w[] = "COALESCE(p.phone,'')=''";
}
if ($gs('fam_min') !== '' || $gs('fam_max') !== '') {
    $sz = "(SELECT COUNT(*) FROM profiles f WHERE f.hh_id=p.hh_id AND f.sector<>'R' AND f.archived_at IS NULL AND (f.social_status IS NULL OR f.social_status NOT IN ('Shahid','Dead','Missing','Detained','Absent')))";
    if ($gs('fam_min') !== '') {
        $w[] = "$sz>=?";
        $a[] = (int)$gs('fam_min');
    }
    if ($gs('fam_max') !== '') {
        $w[] = "$sz<=?";
        $a[] = (int)$gs('fam_max');
    }
}
/* Aid history – household level (aid is recorded under the head of household's ID). */
$aidMode = $gs('aid_mode');
if (in_array($aidMode, ['received', 'never', 'since'], true)) {
    $cond = "b.distribution_type='NORMAL'";
    $ba = [];
    if (isset($L_cat[$gs('aid_cat')])) {
        $cond .= ' AND b.category=?';
        $ba[] = $gs('aid_cat');
    }
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $gs('aid_from')) ? $gs('aid_from') : null;
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $gs('aid_to')) ? $gs('aid_to') : null;
    if ($aidMode === 'received' || $aidMode === 'since') {
        if ($from) {
            $cond .= ' AND b.delivery_date>=?';
            $ba[] = $from;
        }
        if ($to && $aidMode === 'received') {
            $cond .= ' AND b.delivery_date<=?';
            $ba[] = $to;
        }
    }
    $exists = "EXISTS (SELECT 1 FROM beneficiaries b JOIN profiles h ON h.national_id=b.national_id AND h.is_household_head=1 AND h.archived_at IS NULL WHERE h.hh_id=p.hh_id AND $cond)";
    $w[] = $aidMode === 'received' ? $exists : "NOT $exists";
    array_push($a, ...$ba);
}
$whereSql = implode(' AND ', $w);

/* ---------- Result view options ---------- */
$view = $gs('view') === 'households' ? 'households' : 'persons';
if ($view === 'households') {
    $whereSql .= ' AND p.is_household_head=1';
}
$sortMap = ['sector' => 'p.sector', 'tent' => 'p.tent', 'full_name' => 'p.full_name', 'age' => 'p.birth_date', 'added_at' => 'p.added_at', 'id' => 'p.id'];
$sortKey = isset($sortMap[$gs('sort')]) ? $gs('sort') : 'sector';
$campOrder = ($sortKey === 'sector' && $gs('dir') !== 'desc');
$dir = $gs('dir') === 'desc' ? 'DESC' : 'ASC';
$per = in_array((int)$gs('per', '100'), [50, 100, 250, 500], true) ? (int)$gs('per', '100') : 100;
$pageNo = max(1, (int)$gs('pg', '1'));
$orderSql = $campOrder ? profile_order_sql('p') : $sortMap[$sortKey] . " $dir, p.tent, p.id";

/* ---------- Export ---------- */
if ($ran && isset($_GET['csv'])) {
    if (!can('reports', 'export')) {
        echo flash_box('لا تملك صلاحية التصدير.', 'err');
    } else {
        $sx = db()->prepare("SELECT p.sector,p.tent,p.national_id,p.full_name,p.gender,p.birth_date,TIMESTAMPDIFF(YEAR,p.birth_date,CURDATE()) age_years,p.social_status,p.family_member,p.hh_id,p.phone,p.region,p.house_status FROM profiles p WHERE $whereSql ORDER BY $orderSql LIMIT 50000");
        $sx->execute($a);
        audit('export', 'search', null, 'advanced search csv');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Yarmouk_Search_' . date('Ymd_His') . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['Sector', 'Tent', 'National ID', 'Name', 'Gender', 'Birth Date', 'Age', 'Social Status', 'Family Member', 'HHID', 'Phone', 'Region', 'House Status'], ',', '"', '\\');
        while ($r = $sx->fetch()) {
            if (!$mayId) {
                $r['national_id'] = '';
                $r['phone'] = '';
            }
            fputcsv($o, array_values($r), ',', '"', '\\');
        }
        fclose($o);
        exit;
    }
}

/* ---------- Run ---------- */
$sum = ['n' => 0, 'fam' => 0, 'm' => 0, 'f' => 0];
$rows = [];
if ($ran) {
    $s1 = db()->prepare("SELECT COUNT(*) n,COUNT(DISTINCT p.hh_id) fam,COALESCE(SUM(p.gender='Male'),0) m,COALESCE(SUM(p.gender='Female'),0) f FROM profiles p WHERE $whereSql");
    $s1->execute($a);
    $sum = $s1->fetch() ?: $sum;
    $off = ($pageNo - 1) * $per;
    $fl = flag_defs();
    $s2 = db()->prepare("SELECT p.id,p.sector,p.tent,p.national_id,p.full_name,p.gender,p.birth_date,p.social_status,p.family_member,p.is_household_head,p.hh_id,p.phone,p.region,p.house_status,
        TIMESTAMPDIFF(YEAR,p.birth_date,CURDATE()) age_y,TIMESTAMPDIFF(MONTH,p.birth_date,CURDATE()) age_m,
        (" . $fl['orphan'][2] . ") f_orphan,(" . $fl['disabled'][2] . ") f_disabled,(" . $fl['chronic'][2] . ") f_chronic,(" . $fl['injured'][2] . ") f_injured,
        (" . $fl['pregnant'][2] . ") f_pregnant,(" . $fl['breastfeeding'][2] . ") f_breast,(" . $fl['elderly'][2] . ") f_elderly
        FROM profiles p WHERE $whereSql ORDER BY $orderSql LIMIT $per OFFSET $off");
    $s2->execute($a);
    $rows = $s2->fetchAll();
}
$pages = $ran ? max(1, (int)ceil(((int)$sum['n']) / $per)) : 1;
$qs = $_GET;
unset($qs['pg'], $qs['csv']);
$base = '?' . http_build_query($qs);
$ageTxt = function (array $r): string {
    if ($r['birth_date'] === null) {
        return '—';
    }
    return (int)$r['age_y'] < 3 ? (int)$r['age_m'] . ' شهر' : (int)$r['age_y'] . ' سنة';
};
?>
<section class="panel search-hero">
  <div class="panel-head"><h2>البحث المتقدم</h2><span class="tag">اضغط على أي تصنيف سريع أو ركّب فلاتر متعددة</span></div>
  <div class="chips">
    <?php foreach (search_presets() as $label => [$fl2, $extra]): ?>
      <a class="chip" href="<?= e(search_url($fl2, $extra)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a class="chip ghost" href="?page=search">مسح الكل</a>
  </div>

  <form method="get" class="adv-form">
    <input type="hidden" name="page" value="search"><input type="hidden" name="go" value="1">

    <div class="fgroup">
      <div class="field wide"><span>بحث نصي ذكي (اسم، رقم أسرة، خيمة<?= $mayId ? '، هوية، هاتف' : '' ?>) — كلمات متعددة، يتجاهل الهمزات والتشكيل والأرقام الهندية</span>
        <input name="q" value="<?= e($q) ?>" placeholder="مثال: محمد خطيب"></div>
      <div class="field wide"><span>القطاعات</span>
        <div class="sector-checks"><?php foreach ($scope as $sx): ?><label><input type="checkbox" name="sec[]" value="<?= e($sx) ?>" <?= in_array($sx, $ga('sec'), true) ? 'checked' : '' ?>> <?= e($sx) ?></label><?php endforeach; ?></div></div>
    </div>

    <details class="fgroup" open><summary>التصنيفات الخاصة (أيتام، أرامل، مطلقات، ذوو إعاقة…)</summary>
      <?php
      $byGroup = [];
      foreach (flag_defs() as $k => [$lbl, $grp]) {
          $byGroup[$grp][$k] = $lbl;
      } ?>
      <?php foreach ($byGroup as $grp => $items): ?>
        <div class="gblock"><b><?= e($grp) ?></b><div class="chk-grid">
          <?php foreach ($items as $k => $lbl): ?><label class="chk"><input type="checkbox" name="flags[]" value="<?= e($k) ?>" <?= $ck($flagsSel, $k) ?>> <?= e($lbl) ?></label><?php endforeach; ?>
        </div></div>
      <?php endforeach; ?>
      <label class="field inline-f"><span>الجمع بين التصنيفات</span><select name="fmode"><option value="all">كلها معاً (و)</option><option value="any" <?= $gs('fmode') === 'any' ? 'selected' : '' ?>>أي واحد منها (أو)</option></select></label>
    </details>

    <details class="fgroup" open><summary>الديموغرافيا والحالة الاجتماعية</summary>
      <div class="form-grid">
        <label class="field"><span>الجنس</span><select name="gender"><option value="">الكل</option><option value="Male" <?= $gs('gender') === 'Male' ? 'selected' : '' ?>>ذكر</option><option value="Female" <?= $gs('gender') === 'Female' ? 'selected' : '' ?>>أنثى</option></select></label>
        <label class="field"><span>العمر من</span><input type="number" min="0" name="age_min" value="<?= e($gs('age_min')) ?>"></label>
        <label class="field"><span>العمر إلى</span><input type="number" min="0" name="age_max" value="<?= e($gs('age_max')) ?>"></label>
        <label class="field"><span>وحدة العمر</span><select name="age_unit"><option value="y">سنوات</option><option value="m" <?= $gs('age_unit') === 'm' ? 'selected' : '' ?>>أشهر</option></select></label>
        <label class="field"><span>ربّ أسرة؟</span><select name="head"><option value="">الكل</option><option value="yes" <?= $gs('head') === 'yes' ? 'selected' : '' ?>>أرباب الأسر فقط</option><option value="no" <?= $gs('head') === 'no' ? 'selected' : '' ?>>بدون أرباب الأسر</option></select></label>
        <label class="field"><span>الضيافة</span><select name="host"><option value="">الكل</option><option value="Host" <?= $gs('host') === 'Host' ? 'selected' : '' ?>>مضيف</option><option value="Guest" <?= $gs('host') === 'Guest' ? 'selected' : '' ?>>ضيف</option></select></label>
        <label class="field"><span>حجم الأسرة من</span><input type="number" min="1" name="fam_min" value="<?= e($gs('fam_min')) ?>"></label>
        <label class="field"><span>حجم الأسرة إلى</span><input type="number" min="1" name="fam_max" value="<?= e($gs('fam_max')) ?>"></label>
      </div>
      <div class="gblock"><b>الحالة الاجتماعية</b><div class="chk-grid"><?php foreach ($L_status as $k => $l): ?><label class="chk"><input type="checkbox" name="st[]" value="<?= e($k) ?>" <?= $ck($stSel, $k) ?>> <?= e($l) ?></label><?php endforeach; ?></div></div>
      <div class="gblock"><b>صلة القرابة</b><div class="chk-grid"><?php foreach ($L_member as $k => $l): ?><label class="chk"><input type="checkbox" name="fm[]" value="<?= e($k) ?>" <?= $ck($fmSel, $k) ?>> <?= e($l) ?></label><?php endforeach; ?></div></div>
      <label class="check-line"><input type="checkbox" name="inactive" value="1" <?= $gs('inactive') === '1' ? 'checked' : '' ?>> تضمين الشهداء والمتوفين والمفقودين والمعتقلين والغائبين (مستثنون افتراضياً)</label>
    </details>

    <details class="fgroup"><summary>السكن والخيمة</summary>
      <div class="gblock"><b>حالة المنزل الأصلي</b><div class="chk-grid"><?php foreach ($L_house as $k => $l): ?><label class="chk"><input type="checkbox" name="house[]" value="<?= e($k) ?>" <?= $ck($hsSel, $k) ?>> <?= e($l) ?></label><?php endforeach; ?></div></div>
      <div class="form-grid">
        <label class="field"><span>رقم الخيمة</span><input name="tent" value="<?= e($gs('tent')) ?>"></label>
        <label class="field"><span>نوع الخيمة</span><select name="ttype"><option value="">الكل</option><?php foreach ($L_ttype as $k => $l): ?><option value="<?= e($k) ?>" <?= $gs('ttype') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>حالة الخيمة</span><select name="tcond"><option value="">الكل</option><?php foreach ($L_tcond as $k => $l): ?><option value="<?= e($k) ?>" <?= $gs('tcond') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>المنطقة الأصلية</span><input name="region" value="<?= e($gs('region')) ?>"></label>
        <label class="field"><span>الوظيفة / المهنة</span><input name="job" value="<?= e($gs('job')) ?>"></label>
        <label class="field"><span>يوجد هاتف؟</span><select name="has_phone"><option value="">الكل</option><option value="yes" <?= $gs('has_phone') === 'yes' ? 'selected' : '' ?>>نعم</option><option value="no" <?= $gs('has_phone') === 'no' ? 'selected' : '' ?>>لا</option></select></label>
        <label class="field"><span>تاريخ الإضافة من</span><input type="date" name="added_from" value="<?= e($gs('added_from')) ?>"></label>
        <label class="field"><span>تاريخ الإضافة إلى</span><input type="date" name="added_to" value="<?= e($gs('added_to')) ?>"></label>
      </div>
    </details>

    <details class="fgroup"><summary>سجل الاستلام (على مستوى الأسرة)</summary>
      <div class="form-grid">
        <label class="field"><span>الشرط</span><select name="aid_mode"><option value="">بدون</option>
          <option value="received" <?= $aidMode === 'received' ? 'selected' : '' ?>>استلمت خلال الفترة</option>
          <option value="never" <?= $aidMode === 'never' ? 'selected' : '' ?>>لم تستلم أبداً</option>
          <option value="since" <?= $aidMode === 'since' ? 'selected' : '' ?>>لم تستلم منذ تاريخ…</option></select></label>
        <label class="field"><span>نوع المساعدة</span><select name="aid_cat"><option value="">أي نوع</option><?php foreach ($L_cat as $k => $l): ?><option value="<?= e($k) ?>" <?= $gs('aid_cat') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>من تاريخ</span><input type="date" name="aid_from" value="<?= e($gs('aid_from')) ?>"></label>
        <label class="field"><span>إلى تاريخ</span><input type="date" name="aid_to" value="<?= e($gs('aid_to')) ?>"></label>
      </div>
      <p class="hint">«لم تستلم منذ تاريخ» يعرض الأسر التي لا يوجد لها استلام من هذا التاريخ فصاعداً (استخدم «من تاريخ»).</p>
    </details>

    <div class="fgroup">
      <div class="form-grid">
        <label class="field"><span>عرض</span><select name="view"><option value="persons">أفراد</option><option value="households" <?= $view === 'households' ? 'selected' : '' ?>>أسر (أرباب الأسر)</option></select></label>
        <label class="field"><span>ترتيب حسب</span><select name="sort"><?php foreach (['sector' => 'القطاع', 'tent' => 'الخيمة', 'full_name' => 'الاسم', 'age' => 'تاريخ الميلاد', 'added_at' => 'تاريخ الإضافة', 'id' => 'الإدخال'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $sortKey === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>الاتجاه</span><select name="dir"><option value="asc">تصاعدي</option><option value="desc" <?= $dir === 'DESC' ? 'selected' : '' ?>>تنازلي</option></select></label>
        <label class="field"><span>عدد النتائج بالصفحة</span><select name="per"><?php foreach ([50, 100, 250, 500] as $n): ?><option <?= $per === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="btn-row"><button class="primary gold-btn">بحث</button><a class="secondary" href="?page=search">إعادة ضبط</a></div>
    </div>
  </form>
</section>

<?php if ($ran): ?>
<div class="kpi-grid">
  <?= kpi('النتائج', nfmt($sum['n']), $view === 'households' ? 'أسرة' : 'فرد', 'gold') ?>
  <?= kpi('عدد الأسر', nfmt($sum['fam'])) ?>
  <?= kpi('ذكور', nfmt($sum['m'])) ?>
  <?= kpi('إناث', nfmt($sum['f'])) ?>
</div>
<section class="panel">
  <div class="panel-head"><h2>النتائج</h2>
    <div class="btn-row">
      <button type="button" class="secondary" onclick="window.print()">طباعة</button>
      <?php if (can('reports', 'export')): ?><a class="primary" href="<?= e($base . '&csv=1') ?>">تصدير CSV (كل النتائج)</a><?php endif; ?>
    </div></div>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>القطاع</th><th>الخيمة</th><?php if ($mayId): ?><th>الهوية</th><?php endif; ?><th>الاسم</th><th>الجنس</th><th>العمر</th><th>الحالة</th><th>الصلة</th><th>رقم الأسرة</th><?php if ($mayId): ?><th>الهاتف</th><?php endif; ?><th>وسوم</th></tr></thead><tbody>
  <?php foreach ($rows as $i => $r): ?>
    <tr><td><?= ($pageNo - 1) * $per + $i + 1 ?></td><td><?= e($r['sector']) ?></td><td><?= e($r['tent']) ?></td><?php if ($mayId): ?><td><?= e($r['national_id']) ?></td><?php endif; ?>
      <td><?= e($r['full_name']) ?><?= (int)$r['is_household_head'] === 1 ? ' ' . badge('ربّ أسرة', 'gold') : '' ?></td>
      <td><?= $r['gender'] === 'Male' ? 'ذكر' : ($r['gender'] === 'Female' ? 'أنثى' : '') ?></td><td><?= e($ageTxt($r)) ?></td>
      <td><?= e($L_status[$r['social_status']] ?? (string)$r['social_status']) ?></td><td><?= e($L_member[$r['family_member']] ?? (string)$r['family_member']) ?></td><td><?= e($r['hh_id']) ?></td>
      <?php if ($mayId): ?><td><?= e($r['phone']) ?></td><?php endif; ?>
      <td class="tags"><?= ($r['f_orphan'] ? badge('يتيم', 'blue') : '') . ($r['f_disabled'] ? badge('إعاقة', 'red') : '') . ($r['f_chronic'] ? badge('مزمن', 'red') : '') . ($r['f_injured'] ? badge('جريح', 'red') : '') . ($r['f_pregnant'] ? badge('حامل', 'gold') : '') . ($r['f_breast'] ? badge('مرضع', 'gold') : '') . ($r['f_elderly'] ? badge('كبير سن', 'gray') : '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="12">لا توجد نتائج مطابقة.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?>
  <div class="pager"><?php for ($i = 1; $i <= $pages; $i++): if ($i === 1 || $i === $pages || abs($i - $pageNo) <= 2): ?>
    <a class="<?= $i === $pageNo ? 'on' : '' ?>" href="<?= e($base . '&pg=' . $i) ?>"><?= $i ?></a><?php elseif (abs($i - $pageNo) === 3): ?><span>…</span><?php endif; endfor; ?></div>
  <?php endif; ?>
  <?php if (!$mayId): ?><p class="hint">أرقام الهوية والهاتف مخفية لعدم امتلاكك صلاحية البيانات الحساسة.</p><?php endif; ?>
</section>
<?php else: ?>
<section class="panel"><p class="empty">اختر تصنيفاً سريعاً من الأعلى أو حدّد الفلاتر ثم اضغط «بحث».</p></section>
<?php endif; ?>
