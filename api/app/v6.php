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

const SCHEMA_VERSION = 7;

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
            $pdo->exec('CREATE TABLE IF NOT EXISTS resident_codes (national_id VARCHAR(40) NOT NULL PRIMARY KEY, code_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
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
        COUNT(DISTINCT CASE WHEN is_household_head=1 THEN hh_id END) families,
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

/* ---------- Religious reminder toast (on by default; the manager can switch it off / change timing) ---------- */
function dhikr_script(): string
{
    if (setting('dhikr_enabled', '1') !== '1') {
        return '';
    }
    $every = max(30, min(3600, (int)setting('dhikr_every', '120')));
    $stay = max(5, min(60, (int)setting('dhikr_stay', '12')));
    return '<script src="/assets/dhikr.js" defer data-every="' . $every . '" data-stay="' . $stay . '"></script>';
}

/* =====================================================================
 * XLSX writer (no library): used by every export instead of CSV
 * ===================================================================== */

function xlsx_col(int $i): string
{
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

function xlsx_xml(string $s): string
{
    $c = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    return htmlspecialchars($c ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Returns the .xlsx file as a binary string, or throws if the zip extension is missing. */
function xlsx_build(array $headers, array $rows, string $sheet = 'Data'): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('إضافة zip غير مفعّلة في PHP.');
    }
    $sheet = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $sheet) ?? '') ?: 'Data';
    $sheet = mb_substr($sheet, 0, 31);
    $numericHeader = [];
    foreach ($headers as $i => $h) {
        $numericHeader[$i] = (bool)preg_match('/(amount|qty|quantity|age|family|fam\b|المبلغ|الكمية)/i', (string)$h)
            && !preg_match('/(id|phone|fc|hh|tent)/i', (string)$h);
    }
    $nCols = max(1, count($headers));
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="' . $nCols . '" width="20" customWidth="1"/></cols><sheetData>';
    $r = 1;
    $x .= '<row r="1">';
    foreach ($headers as $i => $h) {
        $x .= '<c r="' . xlsx_col($i) . '1" t="inlineStr" s="1"><is><t xml:space="preserve">' . xlsx_xml((string)$h) . '</t></is></c>';
    }
    $x .= '</row>';
    foreach ($rows as $row) {
        $r++;
        $x .= '<row r="' . $r . '">';
        $i = 0;
        foreach ($row as $v) {
            $ref = xlsx_col($i) . $r;
            if ($v === null || $v === '') {
                $i++;
                continue;
            }
            if (($numericHeader[$i] ?? false) && is_numeric($v) && !preg_match('/^0\d/', (string)$v)) {
                $x .= '<c r="' . $ref . '"><v>' . (0 + $v) . '</v></c>';
            } else {
                // text cell: IDs keep their leading zeros and nothing is ever treated as a formula
                $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_xml((string)$v) . '</t></is></c>';
            }
            $i++;
        }
        $x .= '</row>';
    }
    $x .= '</sheetData></worksheet>';

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsx_xml($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . $ns . '"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => $x,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        throw new RuntimeException('تعذّر إنشاء ملف Excel.');
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

function xlsx_send(string $filename, array $headers, array $rows, string $sheet = 'Data'): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $filename) ?: 'export.xlsx';
    try {
        $bin = xlsx_build($headers, $rows, $sheet);
    } catch (Throwable $e) {
        // zip extension missing: never lose the export, fall back to a UTF-8 CSV that Excel opens
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/\.xlsx$/i', '.csv', $filename) . '"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, $headers);
        foreach ($rows as $r) {
            fputcsv($o, $r);
        }
        fclose($o);
        exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bin));
    header('X-Content-Type-Options: nosniff');
    echo $bin;
    exit;
}

/** Every old CSV export/template now goes out as .xlsx. */
function dt_send_csv($filename, $headers, $rows)
{
    xlsx_send((string)preg_replace('/\.csv$/i', '.xlsx', (string)$filename), (array)$headers, (array)$rows);
}

/* =====================================================================
 * HH list: heads of household only, R never included
 * ===================================================================== */

function hh_list_headers(): array
{
    return ['ID', 'Name', 'Gender', 'Birth Date', 'Social Status', 'Family', 'Partner ID', 'Partner Name', 'Phone'];
}

function hh_list_rows(array $sectors): array
{
    $sectors = array_values(array_diff($sectors, ['R']));
    if (!$sectors) {
        return [];
    }
    $act = ACTIVE_SQL;
    $sql = "SELECT p.hh_id,p.national_id,p.full_name,p.gender,p.birth_date,p.social_status,p.partner_name,p.phone,
        (SELECT sp.national_id FROM profiles sp WHERE sp.hh_id=p.hh_id AND sp.id<>p.id AND sp.archived_at IS NULL AND sp.sector<>'R'
           AND (sp.full_name=p.partner_name OR (sp.family_member IN ('Husband','Wife') AND sp.family_member<>p.family_member))
           ORDER BY (sp.full_name=p.partner_name) DESC, sp.id LIMIT 1) partner_id
      FROM profiles p
      WHERE p.archived_at IS NULL AND p.sector<>'R' AND p.is_household_head=1 AND $act AND p.sector IN (" . ph($sectors) . ")
      ORDER BY FIELD(p.sector,'A','AS','B','BS','C','CS','D','DS','E','S','S1'), CAST(p.tent AS UNSIGNED), p.tent, p.id";
    $q = db()->prepare($sql);
    $q->execute($sectors);
    $heads = $q->fetchAll();

    $sizes = [];
    $fq = db()->query("SELECT hh_id, COUNT(*) c FROM profiles WHERE archived_at IS NULL AND sector<>'R' AND $act GROUP BY hh_id");
    foreach ($fq as $r) {
        $sizes[(string)$r['hh_id']] = (int)$r['c'];
    }
    $out = [];
    foreach ($heads as $h) {
        $out[] = [
            $h['national_id'], $h['full_name'], $h['gender'], $h['birth_date'], $h['social_status'],
            $sizes[(string)$h['hh_id']] ?? 1, $h['partner_id'] ?? '', $h['partner_name'], $h['phone'],
        ];
    }
    return $out;
}

/* =====================================================================
 * WhatsApp contact
 * ===================================================================== */

function whatsapp_number(): string
{
    return preg_replace('/\D/', '', setting('whatsapp_number', '97256390038')) ?? '';
}

function whatsapp_button(string $text = 'مرحباً، أحتاج مساعدة بخصوص مخيم اليرموك'): string
{
    $n = whatsapp_number();
    if ($n === '') {
        return '';
    }
    return '<a class="wa-float" target="_blank" rel="noopener noreferrer" href="https://wa.me/' . e($n) . '?text=' . rawurlencode($text) . '" aria-label="واتساب"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M4 3h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H9l-5 4V4a1 1 0 0 1 0-1z"/></svg><span>تواصل عبر واتساب</span></a>';
}

/* =====================================================================
 * Security: headers, HTTPS, idle timeout
 * ===================================================================== */

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    // HTTPS only (Vercel terminates TLS and tells us the original scheme)
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === 'http' && PHP_SAPI !== 'cli') {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 308);
        exit;
    }
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store, max-age=0');      // pages hold personal data: never cached by browsers or proxies
    header_remove('X-Powered-By');
    // No CORS headers on purpose: the API is same-origin only.
}

/** Staff sessions end after 2 hours without activity; residents after 30 minutes (resident.php). */
function enforce_idle_timeout(): void
{
    if (empty($_SESSION['user'])) {
        return;
    }
    $now = time();
    if (isset($_SESSION['last_seen']) && $now - (int)$_SESSION['last_seen'] > 7200) {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['login_notice'] = 'انتهت الجلسة لعدم النشاط. سجّل الدخول من جديد.';
        header('Location: login.php?as=staff');
        exit;
    }
    $_SESSION['last_seen'] = $now;
}

/** Cheap per-session limiter for JSON endpoints (e.g. the ID lookup). */
function session_rate_limit(string $bucket, int $max, int $seconds): bool
{
    $now = time();
    $w = $_SESSION['rl'][$bucket] ?? ['t' => $now, 'n' => 0];
    if ($now - (int)$w['t'] > $seconds) {
        $w = ['t' => $now, 'n' => 0];
    }
    $w['n']++;
    $_SESSION['rl'][$bucket] = $w;
    return $w['n'] <= $max;
}

/* =====================================================================
 * Resident activation codes (replace the guessable "birth year" first login)
 * ===================================================================== */

/** 8 random characters, no look-alikes (no 0/O/1/I/L). Shown as XXXX-XXXX. */
function resident_code_issue(string $nid): array
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $raw = '';
    for ($i = 0; $i < 8; $i++) {
        $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $days = max(1, min(365, (int)setting('activation_days', '30')));
    $exp = date('Y-m-d H:i:s', time() + $days * 86400);
    db()->prepare('REPLACE INTO resident_codes(national_id,code_hash,expires_at) VALUES(?,?,?)')
        ->execute([$nid, hash_password($raw), $exp]);
    return ['code' => substr($raw, 0, 4) . '-' . substr($raw, 4), 'expires' => $exp];
}

function resident_code_valid(string $nid, string $typed): bool
{
    $c = strtoupper((string)preg_replace('/[\s\-]/', '', $typed));
    if (!preg_match('/^[A-Z0-9]{8}$/', $c)) {
        return false;
    }
    $q = db()->prepare('SELECT code_hash FROM resident_codes WHERE national_id=? AND expires_at>NOW()');
    $q->execute([$nid]);
    $h = $q->fetchColumn();
    return $h !== false && password_verify($c, (string)$h);
}

/** Per (ID + IP): 5 fails / 15 min.  Per ID from anywhere: 30 fails / 15 min (stops distributed guessing without letting one stranger lock a family out). */
function resident_throttle_blocked(string $nid): bool
{
    $pdo = db();
    $q = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind='resident' AND identifier=? AND success=0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $q->execute([$nid . '|' . client_ip()]);
    if ((int)$q->fetchColumn() >= 5) {
        return true;
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind='resident' AND identifier LIKE ? AND success=0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $q->execute([$nid . '|%']);
    if ((int)$q->fetchColumn() >= 30) {
        return true;
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE kind='resident' AND ip_address=? AND success=0 AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $q->execute([client_ip()]);
    return (int)$q->fetchColumn() >= 25;
}

/** Residents may pick a simple password, but not a guessable one. */
function check_resident_password(string $pw, string $nid, ?string $birth): void
{
    if (mb_strlen($pw) < 6) {
        throw new RuntimeException('كلمة المرور يجب ألا تقل عن 6 خانات.');
    }
    $low = mb_strtolower($pw);
    $weak = ['123456', '1234567', '12345678', '123456789', '654321', '111111', '000000', '121212', '123123', '112233', '666666', '777777', '888888', '999999', 'password', 'qwerty', 'iloveyou', '555555', '222222', '333333', '444444', 'abc123', '102030'];
    if (in_array($low, $weak, true) || preg_match('/^(.)\1+$/u', $pw)) {
        throw new RuntimeException('كلمة المرور سهلة التخمين. اختر غيرها.');
    }
    if ($pw === $nid || ($nid !== '' && str_contains($pw, substr($nid, -6)))) {
        throw new RuntimeException('لا تستخدم رقم الهوية في كلمة المرور.');
    }
    $y = $birth ? substr($birth, 0, 4) : '';
    if ($y !== '' && str_contains($pw, $y)) {
        throw new RuntimeException('لا تضع سنة الميلاد داخل كلمة المرور.');
    }
}
