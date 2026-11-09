<?php
declare(strict_types=1);

/* =====================================================================
 * Shared vulnerability / demographic filter fragments (alias: p).
 * Used by the advanced search page and by the dashboards, so the numbers
 * on a dashboard card always equal what the search page returns.
 * ===================================================================== */

function orphan_parent_exists(string $roleFilter): string
{
    $in = $roleFilter === '' ? "('Husband','Wife')" : "('" . $roleFilter . "')";
    return "EXISTS (SELECT 1 FROM profiles par WHERE par.hh_id=p.hh_id AND par.archived_at IS NULL AND par.family_member IN $in AND (par.death_date IS NOT NULL OR par.social_status IN ('Shahid','Dead')))";
}

/** key => [label, group, sql fragment]. Every fragment is a boolean expression over alias p. */
function flag_defs(): array
{
    $child = "(p.birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR,p.birth_date,CURDATE())<18 AND p.family_member IN ('Son','Daughter'))";
    return [
        'orphan'         => ['يتيم (فقد أحد الوالدين)', 'أطفال', "$child AND " . orphan_parent_exists('')],
        'orphan_father'  => ['يتيم الأب', 'أطفال', "$child AND " . orphan_parent_exists('Husband')],
        'orphan_mother'  => ['يتيم الأم', 'أطفال', "$child AND " . orphan_parent_exists('Wife')],
        'orphan_both'    => ['يتيم الأبوين', 'أطفال', "$child AND " . orphan_parent_exists('Husband') . ' AND ' . orphan_parent_exists('Wife')],
        'child'          => ['طفل (أقل من 18)', 'أطفال', "(p.birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR,p.birth_date,CURDATE())<18)"],
        'infant'         => ['رضيع (أقل من سنة)', 'أطفال', "(p.birth_date IS NOT NULL AND TIMESTAMPDIFF(MONTH,p.birth_date,CURDATE())<12)"],
        'under5'         => ['أقل من 5 سنوات', 'أطفال', "(p.birth_date IS NOT NULL AND TIMESTAMPDIFF(MONTH,p.birth_date,CURDATE())<60)"],
        'widow'          => ['أرمل / أرملة', 'الحالة الاجتماعية', "p.social_status='Widow'"],
        'divorced'       => ['مطلّق / مطلّقة', 'الحالة الاجتماعية', "p.social_status='Divorced'"],
        'abandoned'      => ['مهجورة / متروك', 'الحالة الاجتماعية', "p.social_status='Abandoned'"],
        'female_head'    => ['أسرة ترأسها امرأة', 'الأسرة', "(p.is_household_head=1 AND p.gender='Female')"],
        'martyr_family'  => ['أسرة شهيد', 'الأسرة', "EXISTS (SELECT 1 FROM profiles m WHERE m.hh_id=p.hh_id AND m.archived_at IS NULL AND m.social_status='Shahid')"],
        'elderly'        => ['كبير سن (60+)', 'صحة وهشاشة', "(p.birth_date IS NOT NULL AND TIMESTAMPDIFF(YEAR,p.birth_date,CURDATE())>=60)"],
        'disabled'       => ['ذو إعاقة', 'صحة وهشاشة', "COALESCE(p.disability,'')<>''"],
        'chronic'        => ['مرض مزمن', 'صحة وهشاشة', "COALESCE(p.chronic_diseases,'')<>''"],
        'injured'        => ['إصابة حرب', 'صحة وهشاشة', "COALESCE(p.war_injury,'')<>''"],
        'pregnant'       => ['حامل', 'صحة وهشاشة', "p.motherhood LIKE 'Pregnant%'"],
        'breastfeeding'  => ['مرضع', 'صحة وهشاشة', "p.motherhood LIKE '%Breast%'"],
    ];
}

/** Presets shown as chips on the search page. key => [label, [flags], extra GET params]. */
function search_presets(): array
{
    return [
        'أيتام' => [['orphan'], []],
        'يتيم الأب' => [['orphan_father'], []],
        'يتيم الأم' => [['orphan_mother'], []],
        'أرامل' => [['widow'], []],
        'مطلقات / مطلقون' => [['divorced'], []],
        'مهجورات' => [['abandoned'], []],
        'أطفال' => [['child'], []],
        'رضّع' => [['infant'], []],
        'كبار السن' => [['elderly'], []],
        'ذوو إعاقة' => [['disabled'], []],
        'أمراض مزمنة' => [['chronic'], []],
        'جرحى' => [['injured'], []],
        'حوامل' => [['pregnant'], []],
        'مرضعات' => [['breastfeeding'], []],
        'أسر ترأسها نساء' => [['female_head'], ['head' => 'yes']],
        'أسر شهداء' => [['martyr_family'], ['head' => 'yes']],
    ];
}

function search_url(array $flags, array $extra = []): string
{
    $q = ['page' => 'search', 'go' => '1'] + $extra;
    $url = '?' . http_build_query($q);
    foreach ($flags as $f) {
        $url .= '&flags[]=' . rawurlencode($f);
    }
    return $url;
}

/** Arabic-friendly normalisation for search terms (digits, ى/ي, ة/ه, tashkeel). */
function ar_norm(string $s): string
{
    $s = normalize_digits($s);
    $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s) ?? $s;
    return str_replace(['ى', 'ة', 'أ', 'إ', 'آ'], ['ي', 'ه', 'ا', 'ا', 'ا'], $s);
}

function ar_col(string $col): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,'ى','ي'),'ة','ه'),'أ','ا'),'إ','ا'),'آ','ا')";
}

/** Counts for dashboard cards; same fragments as the search page. */
function vulnerability_counts(array $sectorList): array
{
    $out = array_fill_keys(array_keys(flag_defs()), 0);
    if (!$sectorList) {
        return $out;
    }
    $parts = [];
    foreach (flag_defs() as $k => $d) {
        $parts[] = "SUM(($d[2])) AS `$k`";
    }
    $sql = 'SELECT ' . implode(',', $parts) . ' FROM profiles p WHERE p.archived_at IS NULL AND p.sector IN (' . ph($sectorList) . ') AND ' . ACTIVE_SQL;
    $q = db()->prepare($sql);
    $q->execute($sectorList);
    $row = $q->fetch() ?: [];
    foreach ($out as $k => $_) {
        $out[$k] = (int)($row[$k] ?? 0);
    }
    return $out;
}
