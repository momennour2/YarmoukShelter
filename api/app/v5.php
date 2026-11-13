<?php
declare(strict_types=1);

/* =====================================================================
 * Yarmouk Shelter V5 – shared helpers
 * Roles, permission presets, login throttling, aggregate statistics,
 * small UI helpers used by the staff console and both portals.
 * ===================================================================== */

const ACTIVE_SQL = "(social_status IS NULL OR social_status NOT IN ('Shahid','Dead','Missing','Detained','Absent'))";

/* ---------- Roles ---------- */

function roles(): array
{
    return [
        'general_manager' => 'المدير العام',
        'sector_manager'  => 'مسؤول قطاع',
        'finance'         => 'المالي',
        'relief'          => 'الإغاثي',
        'institution'     => 'مؤسسة شريكة',
        'staff'           => 'متطوع (صلاحيات مخصصة)',
    ];
}

function role(): string
{
    return (string)(user()['role'] ?? 'staff');
}

function role_label(string $r): string
{
    return roles()[$r] ?? $r;
}

/** A sector manager of A also sees AS, etc. (same logic as the V4 candidate sets). */
function expand_sectors(array $sel): array
{
    $map = ['A' => ['A', 'AS'], 'B' => ['B', 'BS'], 'C' => ['C', 'CS'], 'D' => ['D', 'DS'], 'S' => ['S', 'S1'], 'E' => ['E']];
    $out = [];
    foreach ($sel as $s) {
        foreach ($map[$s] ?? [] as $x) {
            $out[$x] = true;
        }
    }
    return array_keys($out);
}

/** Returns [[module, action, sectors], ...] for a role. */
function role_preset(string $role, array $sectors): array
{
    $p = [];
    $add = function (string $m, array $acts, string $s) use (&$p): void {
        foreach ($acts as $a) {
            $p[] = [$m, $a, $s];
        }
    };
    switch ($role) {
        case 'sector_manager':
            $exp = expand_sectors($sectors);
            if (!$exp) {
                return [];               // never fall back to "all sectors"
            }
            $sec = implode(',', $exp);
            $add('profiles', ['view', 'view_sensitive'], $sec);
            foreach (['statistics', 'family', 'candidates', 'beneficiaries', 'allocations'] as $m) {
                $add($m, ['view'], $sec);
            }
            $add('reports', ['view', 'export'], $sec);
            $add('tickets', ['view', 'edit'], $sec);
            break;

        case 'finance':
            $add('finance', ['view', 'create', 'edit', 'export'], '*');
            foreach (['aids', 'storage', 'statistics', 'beneficiaries', 'allocations'] as $m) {
                $add($m, ['view'], '*');
            }
            $add('reports', ['view', 'export'], '*');
            break;

        case 'relief':
            $add('beneficiaries', ['view', 'create', 'edit', 'export'], '*');
            $add('profiles', ['view', 'view_sensitive'], '*');
            foreach (['candidates', 'family', 'statistics'] as $m) {
                $add($m, ['view'], '*');
            }
            $add('aids', ['view', 'create', 'edit', 'export'], '*');
            $add('storage', ['view', 'create', 'edit', 'export'], '*');
            $add('allocations', ['view', 'create', 'edit'], '*');
            $add('tickets', ['view', 'edit'], '*');
            $add('reports', ['view', 'export'], '*');
            break;
    }
    return $p;
}

function apply_role_preset(int $uid, string $role, array $sectors): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$uid]);
    $ins = $pdo->prepare('INSERT INTO user_permissions(user_id,module,action,sectors) VALUES(?,?,?,?)');
    foreach (role_preset($role, $sectors) as [$m, $a, $s]) {
        $ins->execute([$uid, $m, $a, $s]);
    }
}

/* ---------- Small utilities ---------- */

function normalize_digits(string $s): string
{
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return trim(str_replace($ar, $en, $s));
}

function client_ip(): string
{
    // Behind Vercel REMOTE_ADDR is the proxy; the real client is in these headers (set by Vercel, not by the visitor).
    foreach (['HTTP_X_VERCEL_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        $v = trim(explode(',', (string)($_SERVER[$h] ?? ''))[0]);
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) {
            return substr($v, 0, 45);
        }
    }
    return '';
}

function go(string $url): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $url);
    exit;
}

function flash_box(string $msg, string $type = 'ok'): string
{
    if ($msg === '') {
        return '';
    }
    return '<div class="' . ($type === 'ok' ? 'success' : 'alert') . '">' . e($msg) . '</div>';
}

function ph(array $a): string
{
    return $a ? implode(',', array_fill(0, count($a), '?')) : "'__none__'";
}

function ticket_code(int $id): string
{
    return 'T-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function ticket_types(): array
{
    return ['complaint' => 'شكوى', 'suggestion' => 'اقتراح', 'inquiry' => 'استفسار', 'aid_issue' => 'مشكلة في الاستلام'];
}

function ticket_statuses(): array
{
    return ['new' => 'جديد', 'in_review' => 'قيد المراجعة', 'resolved' => 'تم الحل', 'rejected' => 'مرفوض'];
}

function badge(string $text, string $tone = 'gray'): string
{
    return '<span class="badge badge-' . e($tone) . '">' . e($text) . '</span>';
}

function status_badge(string $st): string
{
    $tone = ['new' => 'gold', 'in_review' => 'blue', 'resolved' => 'green', 'rejected' => 'red', 'pending' => 'gold', 'fulfilled' => 'green'][$st] ?? 'gray';
    $label = ticket_statuses()[$st] ?? (['pending' => 'قيد الدراسة', 'fulfilled' => 'تم التنفيذ'][$st] ?? $st);
    return badge($label, $tone);
}

function kpi(string $label, string|int|float $value, string $hint = '', string $tone = ''): string
{
    return '<article class="kpi ' . e($tone) . '"><span>' . e($label) . '</span><strong>' . e((string)$value) . '</strong>' . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '') . '</article>';
}

/** Pure-CSS horizontal bars. $rows = [label => value]. */
function bars(array $rows, string $unit = ''): string
{
    $max = max(1, (int)max($rows ?: [0]));
    $h = '<div class="bars">';
    foreach ($rows as $label => $v) {
        $w = (int)round(((float)$v / $max) * 100);
        $h .= '<div class="bar-row"><span class="bar-label">' . e((string)$label) . '</span><span class="bar-track"><i style="width:' . $w . '%"></i></span><b>' . e((string)$v) . ($unit !== '' ? ' ' . e($unit) : '') . '</b></div>';
    }
    return $h . '</div>';
}

function suppress(int $n): string
{
    return ($n > 0 && $n < 5) ? '< 5' : (string)$n;   // small-cell suppression for external parties
}

/* ---------- Login throttling ---------- */

function throttle_blocked(string $kind, string $identifier): bool
{
    $pdo = db();
    $q = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind=? AND identifier=? AND success=0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $q->execute([$kind, $identifier]);
    if ((int)$q->fetchColumn() >= 5) {
        return true;
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind=? AND ip_address=? AND success=0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $q->execute([$kind, client_ip()]);
    return (int)$q->fetchColumn() >= 25;
}

function throttle_log(string $kind, string $identifier, bool $ok): void
{
    db()->prepare('INSERT INTO login_attempts(kind,identifier,ip_address,success) VALUES(?,?,?,?)')
        ->execute([$kind, substr($identifier, 0, 80), client_ip(), $ok ? 1 : 0]);
    if ($ok) {   // a good login clears earlier failures for this identifier
        db()->prepare('DELETE FROM login_attempts WHERE kind=? AND identifier=? AND success=0')->execute([$kind, substr($identifier, 0, 80)]);
    }
}

/* ---------- Scope helpers ---------- */

/** Sectors (without R) the current staff user may see in profiles. */
function scope_sectors(): array
{
    $s = is_admin() ? sectors() : allowed_profile_sectors();
    return array_values(array_diff($s, ['R']));
}

/* ---------- Aggregate statistics ---------- */

function sector_summary(array $sectorList): array
{
    $sectorList = array_values(array_diff($sectorList, ['R']));
    if (!$sectorList) {
        return [];
    }
    $in = ph($sectorList);
    $act = ACTIVE_SQL;
    $m = 'TIMESTAMPDIFF(MONTH,birth_date,CURDATE())';
    // One rule for every number: R / archived never counted; persons with Shahid/Dead/Missing/Detained/Absent not counted.
    $sql = "SELECT sector,
        COUNT(DISTINCT CASE WHEN is_household_head=1 THEN hh_id END) families,
        COUNT(*) people,
        COUNT(DISTINCT tent) tents,
        SUM(gender='Male') males,
        SUM(gender='Female') females,
        SUM(birth_date IS NOT NULL AND $m<12) c0_1,
        SUM(birth_date IS NOT NULL AND $m>=12 AND $m<60) c1_5,
        SUM(birth_date IS NOT NULL AND $m>=60 AND $m<144) c5_12,
        SUM(birth_date IS NOT NULL AND $m>=144 AND $m<216) c12_18,
        SUM(birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR,birth_date,CURDATE())>=60) elderly,
        SUM(COALESCE(disability,'')<>'') disabled,
        SUM(COALESCE(chronic_diseases,'')<>'') chronic,
        SUM(COALESCE(war_injury,'')<>'') injured,
        SUM(COALESCE(motherhood,'')<>'') mothers
      FROM profiles WHERE archived_at IS NULL AND sector<>'R' AND sector IN ($in) AND $act GROUP BY sector ORDER BY sector";
    $q = db()->prepare($sql);
    $q->execute($sectorList);
    return $q->fetchAll();
}

function distribution_summary(array $allocSectors, ?string $from = null, ?string $to = null): array
{
    if (!$allocSectors) {
        return [];
    }
    $where = "distribution_type='NORMAL' AND allocation_sector IN (" . ph($allocSectors) . ')';
    $args = $allocSectors;
    if ($from) {
        $where .= ' AND delivery_date>=?';
        $args[] = $from;
    }
    if ($to) {
        $where .= ' AND delivery_date<=?';
        $args[] = $to;
    }
    $q = db()->prepare("SELECT category, COUNT(*) deliveries, COUNT(DISTINCT COALESCE(NULLIF(national_id,''),CONCAT('n',id))) households FROM beneficiaries WHERE $where GROUP BY category");
    $q->execute($args);
    return $q->fetchAll();
}

/** Families that never received a given category (head of household rows only). */
function never_received_count(string $sector, string $category): int
{
    $set = expand_sectors([$sector]) ?: [$sector];
    $alloc = in_array($sector, ['A', 'B', 'C', 'D', 'S'], true) ? $sector : 'S';
    $sql = "SELECT COUNT(*) FROM profiles p WHERE p.archived_at IS NULL AND p.sector<>'R' AND (p.social_status IS NULL OR p.social_status NOT IN ('Shahid','Dead','Missing','Detained','Absent')) AND p.sector IN (" . ph($set) . ") AND p.is_household_head=1
        AND NOT EXISTS (SELECT 1 FROM beneficiaries b WHERE b.national_id=p.national_id AND b.category=? AND b.allocation_sector=? AND COALESCE(b.distribution_type,'NORMAL')<>'FULL')";
    $q = db()->prepare($sql);
    $q->execute(array_merge($set, [$category, $alloc]));
    return (int)$q->fetchColumn();
}

/** Same ordering rules as the V4 candidate list, limited – used for dashboard previews. */
function next_candidates(string $sector, string $category, int $limit = 5): array
{
    $set = expand_sectors([$sector]) ?: [$sector];
    $alloc = in_array($sector, ['A', 'B', 'C', 'D', 'S'], true) ? $sector : 'S';
    $head = $sector === 'S' ? '' : ' AND p.is_household_head=1';
    $sql = "SELECT p.sector,p.tent,p.national_id,p.full_name,p.hh_id,
        (SELECT MAX(b.delivery_date) FROM beneficiaries b WHERE b.national_id=p.national_id AND b.category=? AND b.allocation_sector=? AND COALESCE(b.distribution_type,'NORMAL')<>'FULL') last_date
      FROM profiles p WHERE p.archived_at IS NULL AND p.sector<>'R' AND (p.social_status IS NULL OR p.social_status NOT IN ('Shahid','Dead','Missing','Detained','Absent')) AND p.sector IN (" . ph($set) . ")$head
      ORDER BY (last_date IS NOT NULL), last_date, p.added_at, p.tent, TIMESTAMPDIFF(YEAR,p.birth_date,CURDATE()) LIMIT " . max(1, min(50, $limit));
    $q = db()->prepare($sql);
    $q->execute(array_merge([$category, $alloc], $set));
    return $q->fetchAll();
}

/** Allocation vs distributed per sector/package. */
function sector_balances(array $allocSectors): array
{
    if (!$allocSectors) {
        return [];
    }
    $q = db()->prepare('SELECT sector,category,package,SUM(quantity) allocated FROM sector_allocations WHERE sector IN (' . ph($allocSectors) . ') GROUP BY sector,category,package ORDER BY sector,category,package');
    $q->execute($allocSectors);
    $rows = $q->fetchAll();
    $d = db()->prepare("SELECT COUNT(*) FROM beneficiaries WHERE allocation_sector=? AND package=? AND category=? AND distribution_type='NORMAL'");
    foreach ($rows as &$r) {
        $d->execute([$r['sector'], $r['package'], $r['category']]);
        $r['distributed'] = (int)$d->fetchColumn();
        $r['remaining'] = (int)$r['allocated'] - $r['distributed'];
    }
    unset($r);
    return $rows;
}

function sector_alloc_scope(): array
{
    $s = is_admin() ? ['A', 'B', 'C', 'D', 'S'] : allowed_module_sectors('allocations');
    return array_values(array_intersect(['A', 'B', 'C', 'D', 'S'], $s));
}

/* ---------- Aggregate snapshots for institution requests ---------- */

function snapshot_fields(): array
{
    return [
        'families' => 'الأسر', 'people' => 'الأفراد', 'tents' => 'الخيام', 'males' => 'ذكور', 'females' => 'إناث',
        'c0_1' => 'أقل من سنة', 'c1_5' => '1–5 سنوات', 'c5_12' => '5–12 سنة', 'c12_18' => '12–18 سنة',
        'elderly' => '60+ سنة', 'disabled' => 'ذوو إعاقة', 'chronic' => 'أمراض مزمنة', 'injured' => 'إصابات حرب', 'mothers' => 'حوامل/مرضعات',
    ];
}

function build_snapshot(array $sel, ?string $from, ?string $to): array
{
    $sel = array_values(array_intersect(['A', 'B', 'C', 'D', 'E', 'S'], $sel)) ?: ['A', 'B', 'C', 'D', 'E', 'S'];
    $parent = ['AS' => 'A', 'BS' => 'B', 'CS' => 'C', 'DS' => 'D', 'S1' => 'S'];
    $merged = [];
    foreach (sector_summary(expand_sectors($sel)) as $r) {
        $k = $parent[$r['sector']] ?? $r['sector'];
        foreach (array_keys(snapshot_fields()) as $f) {
            $merged[$k][$f] = (int)($merged[$k][$f] ?? 0) + (int)$r[$f];
        }
    }
    ksort($merged);
    $dist = distribution_summary(array_values(array_intersect($sel, ['A', 'B', 'C', 'D', 'S'])), $from, $to);
    return ['generated_at' => date('Y-m-d H:i'), 'sectors' => $sel, 'from' => $from, 'to' => $to, 'summary' => $merged, 'distributions' => $dist];
}

function render_snapshot(array $snap, bool $hideSmall): string
{
    $fmt = fn(int $n): string => $hideSmall ? suppress($n) : (string)$n;
    $fields = snapshot_fields();
    $h = '<p class="muted">أُعدّ بتاريخ ' . e((string)($snap['generated_at'] ?? '')) . ' · القطاعات: ' . e(implode('، ', $snap['sectors'] ?? [])) . '</p>';
    $h .= '<div class="table-wrap"><table><thead><tr><th>القطاع</th>';
    foreach ($fields as $l) {
        $h .= '<th>' . e($l) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    $tot = array_fill_keys(array_keys($fields), 0);
    foreach (($snap['summary'] ?? []) as $sec => $row) {
        $h .= '<tr><td><b>' . e((string)$sec) . '</b></td>';
        foreach (array_keys($fields) as $f) {
            $tot[$f] += (int)($row[$f] ?? 0);
            $h .= '<td>' . e($fmt((int)($row[$f] ?? 0))) . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '<tr class="total"><td><b>الإجمالي</b></td>';
    foreach (array_keys($fields) as $f) {
        $h .= '<td><b>' . e($fmt($tot[$f])) . '</b></td>';
    }
    $h .= '</tr></tbody></table></div>';
    if (!empty($snap['distributions'])) {
        $names = ['Food' => 'غذائية', 'Financial' => 'نقدية', 'Logistics' => 'لوجستية'];
        $h .= '<h3>ملخص التوزيعات' . (($snap['from'] || $snap['to']) ? ' (' . e((string)($snap['from'] ?: '…')) . ' → ' . e((string)($snap['to'] ?: '…')) . ')' : '') . '</h3>';
        $h .= '<div class="table-wrap"><table><thead><tr><th>النوع</th><th>عدد التسليمات</th><th>عدد المستفيدين</th></tr></thead><tbody>';
        foreach ($snap['distributions'] as $d) {
            $h .= '<tr><td>' . e($names[$d['category']] ?? $d['category']) . '</td><td>' . e($fmt((int)$d['deliveries'])) . '</td><td>' . e($fmt((int)$d['households'])) . '</td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    if ($hideSmall) {
        $h .= '<p class="hint">الأعداد الأقل من 5 تُعرض هكذا (&lt; 5) حفاظاً على خصوصية الأفراد.</p>';
    }
    return $h;
}

function request_types(): array
{
    return [
        'statistics' => 'إحصائيات عامة', 'demographics' => 'بيانات سكانية مجمّعة', 'distributions' => 'تقارير التوزيع',
        'needs' => 'تقييم احتياجات', 'coordination' => 'تنسيق / زيارة ميدانية', 'other' => 'أخرى',
    ];
}
