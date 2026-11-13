<?php
declare(strict_types=1);

/* =====================================================================
 * V6 helpers
 *  - self-migrating schema (no manual SQL needed on Vercel)
 *  - site settings + logos stored in the database (Vercel disk is read-only)
 *  - per-account access window (open / close dates) and manual lock
 *  - per-account hidden features (what a sector manager can / cannot see)
 *  - resident passwords (first login = birth year, then forced password change)
 *  - ONE counting rule for every statistic (R never counted)
 * ===================================================================== */

date_default_timezone_set('Asia/Hebron');

const SCHEMA_VERSION = 6;

/* ---------- Schema ---------- */

function col_exists(string $table, string $col): bool
{
    $q = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table, $col]);
    return (int)$q->fetchColumn() > 0;
}

function ensure_schema(): void
{
    if (($_SESSION['schema_v'] ?? 0) === SCHEMA_VERSION) {
        return;
    }
    try {
        $pdo = db();
        $pdo->exec('CREATE TABLE IF NOT EXISTS site_settings (k VARCHAR(60) NOT NULL PRIMARY KEY, v MEDIUMTEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $cur = 0;
        $q = $pdo->query("SELECT v FROM site_settings WHERE k='schema_v'");
        $cur = (int)($q ? $q->fetchColumn() : 0);
        if ($cur < SCHEMA_VERSION) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS site_assets (k VARCHAR(40) NOT NULL PRIMARY KEY, mime VARCHAR(60) NOT NULL, data MEDIUMBLOB NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $pdo->exec('CREATE TABLE IF NOT EXISTS resident_accounts (national_id VARCHAR(40) NOT NULL PRIMARY KEY, password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $adds = [
                'access_from'     => 'DATETIME NULL',
                'access_until'    => 'DATETIME NULL',
                'access_locked'   => 'TINYINT(1) NOT NULL DEFAULT 0',
                'hidden_features' => 'VARCHAR(600) NULL',
            ];
            foreach ($adds as $c => $def) {
                if (!col_exists('users', $c)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN $c $def");
                }
            }
            $pdo->prepare("REPLACE INTO site_settings(k,v) VALUES('schema_v',?)")->execute([(string)SCHEMA_VERSION]);
        }
        $_SESSION['schema_v'] = SCHEMA_VERSION;
    } catch (Throwable $e) {
        // never break the page because of the migration; /?page=health shows what is missing
        error_log('ensure_schema: ' . $e->getMessage());
    }
}

/* ---------- Settings ---------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT k,v FROM site_settings') as $r) {
                $cache[$r['k']] = (string)$r['v'];
            }
        } catch (Throwable $e) {
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('REPLACE INTO site_settings(k,v) VALUES(?,?)')->execute([$key, $value]);
}

/* ---------- Logos (stored in DB, served by asset.php) ---------- */

/** slots: main + up to 4 partner logos. */
function logo_slots(): array
{
    return ['main' => 'الشعار الرئيسي', 'p1' => 'شعار شريك 1', 'p2' => 'شعار شريك 2', 'p3' => 'شعار شريك 3', 'p4' => 'شعار شريك 4'];
}

function logo_versions(): array
{
    static $v = null;
    if ($v === null) {
        $v = [];
        try {
            foreach (db()->query('SELECT k,UNIX_TIMESTAMP(updated_at) t FROM site_assets') as $r) {
                $v[$r['k']] = (int)$r['t'];
            }
        } catch (Throwable $e) {
        }
    }
    return $v;
}

function logo_url(string $slot = 'main', bool $small = false): ?string
{
    $v = logo_versions();
    if (isset($v[$slot])) {
        return '/api/asset.php?k=' . rawurlencode($slot) . '&v=' . $v[$slot];
    }
    if ($slot === 'main') {
        return $small ? '/assets/logo-sm.jpg' : '/assets/logo.jpg';
    }
    return null;
}

/** <img> for the main logo + the text "Yarmouk Shelter V5" under it (as requested on the paper). */
function brand_block(int $size = 120, bool $withText = true): string
{
    $h = '<div class="brand-stack"><img class="brand-logo" src="' . e((string)logo_url('main')) . '" alt="" width="' . $size . '" height="' . $size . '">';
    if ($withText) {
        $h .= '<small class="brand-under">' . e(setting('site_tagline', 'Yarmouk Shelter V5')) . '</small>';
    }
    return $h . '</div>';
}

function partner_logos_html(string $cls = 'partner-strip'): string
{
    $out = '';
    foreach (['p1', 'p2', 'p3', 'p4'] as $s) {
        if ($u = logo_url($s)) {
            $out .= '<img src="' . e($u) . '" alt="" height="44">';
        }
    }
    return $out === '' ? '' : '<div class="' . e($cls) . '">' . $out . '</div>';
}

/* ---------- Access window + hidden features ---------- */

/** Features the general manager can hide from a given account. key => label. */
function hideable_features(): array
{
    return [
        'sensitive'       => 'أرقام الهويات والهواتف والبيانات الصحية (إخفاء كامل)',
        'mod_statistics'  => 'صفحة الإحصائيات',
        'mod_candidates'  => 'صفحة الترشيحات',
        'mod_beneficiaries' => 'سجل التوزيعات (BNFs)',
        'mod_allocations' => 'أرصدة القطاع (الترصيد)',
        'mod_tickets'     => 'شكاوى واقتراحات الأسر',
        'mod_family'      => 'بحث أفراد العائلة',
        'mod_reports'     => 'التقارير والتصدير',
        'dash_vuln'       => 'لوحة: الفئات الأكثر احتياجاً',
        'dash_age'        => 'لوحة: التركيبة العمرية',
        'dash_balances'   => 'لوحة: أرصدة القطاع',
        'dash_tickets'    => 'لوحة: رسائل الأسر',
        'dash_candidates' => 'لوحة: الترشيحات القادمة',
        'dash_donuts'     => 'لوحة: رسوم الجنس والحالة الاجتماعية',
    ];
}

function parse_hidden(?string $csv): array
{
    return array_values(array_intersect(array_keys(hideable_features()), array_filter(array_map('trim', explode(',', (string)$csv)))));
}

function feature_hidden(string $key): bool
{
    if (!function_exists('user') || !user() || is_admin()) {
        return false;
    }
    return in_array($key, (array)(user()['hidden'] ?? []), true);
}

/** null = allowed, string = reason (Arabic) shown on the login page. */
function access_block_reason(array $u): ?string
{
    if (($u['username'] ?? '') === 'admin' || ($u['role'] ?? '') === 'general_manager') {
        return null;                       // the general manager can never lock himself out
    }
    if (empty($u['is_active'])) {
        return 'هذا الحساب موقوف. تواصل مع الإدارة.';
    }
    if (!empty($u['access_locked'])) {
        return 'تم إغلاق هذا الحساب من قبل الإدارة.';
    }
    $now = time();
    if (!empty($u['access_from']) && strtotime((string)$u['access_from']) > $now) {
        return 'الحساب غير مفتوح بعد. يبدأ الدخول في ' . date('Y-m-d H:i', strtotime((string)$u['access_from'])) . '.';
    }
    if (!empty($u['access_until']) && strtotime((string)$u['access_until']) < $now) {
        return 'انتهت فترة صلاحية الحساب (' . date('Y-m-d H:i', strtotime((string)$u['access_until'])) . ').';
    }
    return null;
}

/** Called on every staff request: re-reads the account so closing it takes effect immediately. */
function enforce_staff_access(): void
{
    $u = user();
    if (!$u) {
        return;
    }
    $q = db()->prepare('SELECT id,username,role,is_active,access_from,access_until,access_locked,hidden_features FROM users WHERE id=?');
    $q->execute([$u['id']]);
    $row = $q->fetch();
    $reason = $row ? access_block_reason($row) : 'الحساب غير موجود.';
    if ($reason !== null) {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['login_notice'] = $reason;
        header('Location: login.php?as=staff');
        exit;
    }
    $_SESSION['user']['hidden'] = parse_hidden($row['hidden_features'] ?? '');
}

/* ---------- Resident passwords ---------- */

function resident_account(string $nid): ?array
{
    $q = db()->prepare('SELECT * FROM resident_accounts WHERE national_id=?');
    $q->execute([$nid]);
    return $q->fetch() ?: null;
}

/* ---------- ONE counting rule ----------
 * Never counted: sector R / archived rows, and persons whose status is in the
 * "not counted" list (Shahid, Dead, Missing, Detained, Absent — see ACTIVE_SQL).
 * Sector groups: A+AS, B+BS, C+CS, D+DS, then S, S1, E on their own.
 */

function sector_groups(): array
{
    return ['A' => ['A', 'AS'], 'B' => ['B', 'BS'], 'C' => ['C', 'CS'], 'D' => ['D', 'DS'], 'S' => ['S'], 'S1' => ['S1'], 'E' => ['E']];
}

/** Groups allowed for the current user (keeps only members the user may see). */
function visible_groups(): array
{
    $ok = scope_sectors();
    $out = [];
    foreach (sector_groups() as $g => $members) {
        $m = array_values(array_intersect($members, $ok));
        if ($m) {
            $out[$g] = $m;
        }
    }
    return $out;
}

/** Summary over a set of sectors as ONE row (used for a group or the grand total). */
function group_summary(array $sectors): array
{
    $sectors = array_values(array_diff($sectors, ['R']));
    $zero = ['families' => 0, 'people' => 0, 'tents' => 0, 'males' => 0, 'females' => 0, 'c0_1' => 0, 'c1_5' => 0, 'c5_12' => 0, 'c12_18' => 0, 'elderly' => 0, 'disabled' => 0, 'chronic' => 0, 'injured' => 0, 'mothers' => 0, 'heads' => 0];
    if (!$sectors) {
        return $zero;
    }
    $act = ACTIVE_SQL;
    $m = 'TIMESTAMPDIFF(MONTH,birth_date,CURDATE())';
    $sql = "SELECT
        COUNT(DISTINCT hh_id) families,
        SUM(is_household_head=1) heads,
        COUNT(*) people,
        COUNT(DISTINCT CONCAT(sector,'-',COALESCE(tent,''))) tents,
        SUM(gender='Male') males, SUM(gender='Female') females,
        SUM(birth_date IS NOT NULL AND $m<12) c0_1,
        SUM(birth_date IS NOT NULL AND $m>=12 AND $m<60) c1_5,
        SUM(birth_date IS NOT NULL AND $m>=60 AND $m<144) c5_12,
        SUM(birth_date IS NOT NULL AND $m>=144 AND $m<216) c12_18,
        SUM(birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR,birth_date,CURDATE())>=60) elderly,
        SUM(COALESCE(disability,'')<>'') disabled,
        SUM(COALESCE(chronic_diseases,'')<>'') chronic,
        SUM(COALESCE(war_injury,'')<>'') injured,
        SUM(COALESCE(motherhood,'')<>'') mothers
      FROM profiles WHERE archived_at IS NULL AND sector<>'R' AND sector IN (" . ph($sectors) . ") AND $act";
    $q = db()->prepare($sql);
    $q->execute($sectors);
    $r = $q->fetch() ?: [];
    foreach ($zero as $k => $_) {
        $zero[$k] = (int)($r[$k] ?? 0);
    }
    return $zero;
}

function orphans_count(array $sectors): int
{
    $sectors = array_values(array_diff($sectors, ['R']));
    if (!$sectors) {
        return 0;
    }
    $flags = flag_defs();
    $q = db()->prepare("SELECT COUNT(*) FROM profiles p WHERE p.archived_at IS NULL AND p.sector<>'R' AND p.sector IN (" . ph($sectors) . ') AND ' . ACTIVE_SQL . ' AND (' . $flags['orphan'][2] . ')');
    $q->execute($sectors);
    return (int)$q->fetchColumn();
}

/** Everything needed to reconcile the system's numbers with the person's own Excel numbers. */
function reconciliation(array $sectors): array
{
    $sectors = array_values(array_diff($sectors, ['R']));
    $in = ph($sectors);
    $out = ['all_rows' => 0, 'archived_R' => 0, 'by_status' => [], 'counted' => 0];
    $q = db()->prepare("SELECT COUNT(*) FROM profiles WHERE sector IN ($in)");
    $q->execute($sectors);
    $out['all_rows'] = (int)$q->fetchColumn();
    $out['archived_R'] = (int)db()->query("SELECT COUNT(*) FROM profiles WHERE sector='R' OR archived_at IS NOT NULL")->fetchColumn();
    $q = db()->prepare("SELECT COALESCE(NULLIF(social_status,''),'(blank)') s, COUNT(*) c FROM profiles WHERE archived_at IS NULL AND sector<>'R' AND sector IN ($in) AND NOT " . ACTIVE_SQL . ' GROUP BY s ORDER BY c DESC');
    $q->execute($sectors);
    $out['by_status'] = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $out['counted'] = group_summary($sectors)['people'];
    return $out;
}
