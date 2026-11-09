<?php
declare(strict_types=1);

/* =====================================================================
 * Dependency-free SVG charts + icons + hero illustration.
 * Everything is rendered server-side, so dashboards work offline (XAMPP).
 * ===================================================================== */

function chart_palette(): array
{
    return ['#1a3a6b', '#c9a24b', '#1f8a8a', '#b8433a', '#2b6cb0', '#6b4fa3', '#1f8a5b', '#d08a3c', '#7a8497'];
}

function nfmt($n): string
{
    return number_format((float)$n, 0, '.', ',');
}

function icon(string $name, int $size = 22): string
{
    $p = [
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.2c2.9.2 5 2.5 5 5.3"/>',
        'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'tent' => '<path d="M2 20L12 4l10 16z"/><path d="M12 11l-3.2 9M12 11l3.2 9"/>',
        'gift' => '<rect x="3" y="9" width="18" height="11" rx="1.5"/><path d="M12 9v11M3 13h18M12 9c-2-4-6-4-5-1.5C7.500 9 12 9 12 9zM12 9c2-4 6-4 5-1.5C16.500 9 12 9 12 9z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2.5"/><path d="M3 10h18M16 15h2"/>',
        'building' => '<rect x="5" y="3" width="14" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M10 21v-4h4v4"/>',
        'heart' => '<path d="M12 20s-7-4.400-9-9.200C1.800 7.300 4 4.500 7 4.500c2 0 3.300 1 5 3 1.700-2 3-3 5-3 3 0 5.200 2.800 4 6.300-2 4.800-9 9.200-9 9.200z"/>',
        'alert' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.500 8-8 9-4.500-1-8-4-8-9V6z"/>',
        'child' => '<circle cx="12" cy="7" r="3"/><path d="M6 21v-5a6 6 0 0 1 12 0v5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'female' => '<circle cx="12" cy="8" r="4"/><path d="M12 12v8M9 17h6"/>',
        'elder' => '<circle cx="10" cy="6" r="3"/><path d="M10 10v6M10 16l-3 5M10 16l3 5M16 10v11"/>',
        'search' => '<circle cx="11" cy="11" r="6.500"/><path d="M16 16l5 5"/>',
        'trend' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'check' => '<path d="M5 12l5 5 9-10"/>',
    ];
    $body = $p[$name] ?? $p['users'];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function spark(array $vals, string $color = '#c9a24b', int $w = 120, int $h = 36): string
{
    $vals = array_values(array_map('floatval', $vals));
    if (count($vals) < 2) {
        return '';
    }
    $max = max(1.0, max($vals));
    $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i / ($n - 1) * $w, 1) . ',' . round($h - 3 - ($v / $max) * ($h - 8), 1);
    }
    $line = implode(' ', $pts);
    return '<svg class="spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true">'
        . '<polygon points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '" fill="' . $color . '" opacity=".16"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/** $data = [label => value]. */
function svg_donut(array $data, string $center = '', string $sub = ''): string
{
    $pal = chart_palette();
    $total = (float)array_sum($data);
    $r = 70;
    $c = 2 * M_PI * $r;
    $svg = '<svg class="donut-svg" viewBox="0 0 200 200" role="img"><circle cx="100" cy="100" r="' . $r . '" fill="none" stroke="#efe9d8" stroke-width="26"/>';
    $off = 0.0;
    $i = 0;
    foreach ($data as $v) {
        if ($total > 0 && $v > 0) {
            $len = $v / $total * $c;
            $svg .= '<circle cx="100" cy="100" r="' . $r . '" fill="none" stroke="' . $pal[$i % count($pal)] . '" stroke-width="26" stroke-dasharray="' . round(max(0, $len - 1.5), 2) . ' ' . round($c - max(0, $len - 1.5), 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 100 100)"/>';
            $off += $len;
        }
        $i++;
    }
    $svg .= '<text x="100" y="' . ($sub !== '' ? 98 : 106) . '" text-anchor="middle" class="d-num">' . e($center !== '' ? $center : nfmt($total)) . '</text>';
    if ($sub !== '') {
        $svg .= '<text x="100" y="120" text-anchor="middle" class="d-sub">' . e($sub) . '</text>';
    }
    $svg .= '</svg>';
    $leg = '<ul class="legend">';
    $i = 0;
    foreach ($data as $label => $v) {
        $pct = $total > 0 ? round($v / $total * 100) : 0;
        $leg .= '<li><i style="background:' . $pal[$i % count($pal)] . '"></i><span>' . e((string)$label) . '</span><b>' . nfmt($v) . '</b><em>' . $pct . '%</em></li>';
        $i++;
    }
    return '<div class="donut">' . $svg . $leg . '</ul></div>';
}

/** Grouped columns. $series = [name => [values aligned with $labels]]. */
function svg_columns(array $labels, array $series, int $h = 250): string
{
    $pal = chart_palette();
    $w = 640;
    $padL = 46;
    $padB = 30;
    $padT = 14;
    $max = 1.0;
    foreach ($series as $vals) {
        $max = max($max, (float)max($vals ?: [0]));
    }
    $step = 10 ** max(0, (int)floor(log10($max)));
    $top = ceil($max / $step) * $step;
    $n = max(1, count($labels));
    $ns = max(1, count($series));
    $gw = ($w - $padL - 10) / $n;
    $bw = min(34, ($gw - 12) / $ns);
    $ph = $h - $padB - $padT;
    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
    for ($g = 0; $g <= 4; $g++) {
        $y = $padT + $ph - $ph * $g / 4;
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - 6) . '" y1="' . $y . '" y2="' . $y . '" stroke="#ece5d1" stroke-dasharray="3 4"/><text x="' . ($padL - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="ax">' . nfmt($top * $g / 4) . '</text>';
    }
    foreach ($labels as $i => $lb) {
        $x0 = $padL + $i * $gw + ($gw - $bw * $ns) / 2;
        $si = 0;
        foreach ($series as $vals) {
            $v = (float)($vals[$i] ?? 0);
            $bh = $top > 0 ? $v / $top * $ph : 0;
            $svg .= '<rect x="' . round($x0 + $si * $bw, 1) . '" y="' . round($padT + $ph - $bh, 1) . '" width="' . round($bw - 3, 1) . '" height="' . round($bh, 1) . '" rx="5" fill="' . $pal[$si % count($pal)] . '"><title>' . e(nfmt($v)) . '</title></rect>';
            $si++;
        }
        $svg .= '<text x="' . round($padL + $i * $gw + $gw / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="ax">' . e((string)$lb) . '</text>';
    }
    $svg .= '</svg>';
    $leg = '<ul class="legend inline">';
    $si = 0;
    foreach (array_keys($series) as $nm) {
        $leg .= '<li><i style="background:' . $pal[$si++ % count($pal)] . '"></i><span>' . e((string)$nm) . '</span></li>';
    }
    return $leg . '</ul>' . $svg;
}

/** Multi-series area/line chart. */
function svg_line(array $labels, array $series, int $h = 250): string
{
    $pal = chart_palette();
    $w = 760;
    $padL = 44;
    $padB = 28;
    $padT = 12;
    $max = 1.0;
    foreach ($series as $vals) {
        $max = max($max, (float)max($vals ?: [0]));
    }
    $step = 10 ** max(0, (int)floor(log10($max)));
    $top = ceil($max / $step) * $step;
    $n = count($labels);
    if ($n < 2) {
        return '<p class="empty">لا توجد بيانات كافية.</p>';
    }
    $ph = $h - $padB - $padT;
    $xs = fn(int $i): float => $padL + $i * (($w - $padL - 12) / ($n - 1));
    $ys = fn(float $v): float => $padT + $ph - ($top > 0 ? $v / $top * $ph : 0);
    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img"><defs>';
    foreach (array_keys(array_values($series)) as $si) {
        $col = $pal[$si % count($pal)];
        $svg .= '<linearGradient id="lg' . $si . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $col . '" stop-opacity=".30"/><stop offset="1" stop-color="' . $col . '" stop-opacity="0"/></linearGradient>';
    }
    $svg .= '</defs>';
    for ($g = 0; $g <= 4; $g++) {
        $y = $padT + $ph - $ph * $g / 4;
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - 8) . '" y1="' . $y . '" y2="' . $y . '" stroke="#ece5d1" stroke-dasharray="3 4"/><text x="' . ($padL - 8) . '" y="' . ($y + 4) . '" text-anchor="end" class="ax">' . nfmt($top * $g / 4) . '</text>';
    }
    $every = max(1, (int)ceil($n / 8));
    foreach ($labels as $i => $lb) {
        if ($i % $every === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($xs($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="ax">' . e((string)$lb) . '</text>';
        }
    }
    $si = 0;
    foreach ($series as $vals) {
        $pts = [];
        foreach ($labels as $i => $_) {
            $pts[] = round($xs($i), 1) . ',' . round($ys((float)($vals[$i] ?? 0)), 1);
        }
        $line = implode(' ', $pts);
        $col = $pal[$si % count($pal)];
        $svg .= '<polygon points="' . round($xs(0), 1) . ',' . ($padT + $ph) . ' ' . $line . ' ' . round($xs($n - 1), 1) . ',' . ($padT + $ph) . '" fill="url(#lg' . $si . ')"/>'
            . '<polyline points="' . $line . '" fill="none" stroke="' . $col . '" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>';
        $si++;
    }
    $svg .= '</svg>';
    $leg = '<ul class="legend inline">';
    $si = 0;
    foreach (array_keys($series) as $nm) {
        $leg .= '<li><i style="background:' . $pal[$si++ % count($pal)] . '"></i><span>' . e((string)$nm) . '</span></li>';
    }
    return $leg . '</ul>' . $svg;
}

/** Age pyramid. $rows = [band label => [males, females]]. */
function svg_pyramid(array $rows): string
{
    $max = 1;
    foreach ($rows as [$m, $f]) {
        $max = max($max, $m, $f);
    }
    $w = 560;
    $mid = $w / 2;
    $rh = 34;
    $h = count($rows) * $rh + 30;
    $half = $mid - 70;
    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
    $i = 0;
    foreach ($rows as $label => [$m, $f]) {
        $y = 24 + $i * $rh;
        $mw = $m / $max * $half;
        $fw = $f / $max * $half;
        $svg .= '<rect x="' . round($mid - 34 - $mw, 1) . '" y="' . $y . '" width="' . round($mw, 1) . '" height="22" rx="6" fill="#1a3a6b"/>'
            . '<rect x="' . round($mid + 34, 1) . '" y="' . $y . '" width="' . round($fw, 1) . '" height="22" rx="6" fill="#c9a24b"/>'
            . '<text x="' . $mid . '" y="' . ($y + 16) . '" text-anchor="middle" class="ax">' . e((string)$label) . '</text>'
            . '<text x="' . round($mid - 40 - $mw, 1) . '" y="' . ($y + 16) . '" text-anchor="end" class="ax b">' . nfmt($m) . '</text>'
            . '<text x="' . round($mid + 40 + $fw, 1) . '" y="' . ($y + 16) . '" class="ax b">' . nfmt($f) . '</text>';
        $i++;
    }
    $svg .= '<text x="' . ($mid - 120) . '" y="12" class="ax b" fill="#1a3a6b">ذكور</text><text x="' . ($mid + 120) . '" y="12" text-anchor="end" class="ax b" fill="#8f6f26">إناث</text></svg>';
    return $svg;
}

function svg_gauge(float $pct, string $label): string
{
    $pct = max(0, min(100, $pct));
    $len = M_PI * 80;
    return '<div class="gauge"><svg viewBox="0 0 200 120" role="img"><defs><linearGradient id="gg" x1="0" x2="1"><stop offset="0" stop-color="#8f6f26"/><stop offset="1" stop-color="#e6cf8f"/></linearGradient></defs>'
        . '<path d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke="#efe9d8" stroke-width="18" stroke-linecap="round"/>'
        . '<path d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke="url(#gg)" stroke-width="18" stroke-linecap="round" stroke-dasharray="' . round($len * $pct / 100, 1) . ' ' . round($len, 1) . '"/>'
        . '<text x="100" y="92" text-anchor="middle" class="g-num">' . round($pct) . '%</text></svg><span>' . e($label) . '</span></div>';
}

/** Decorative camp skyline used behind the dashboard hero. */
function camp_art(): string
{
    $stars = '';
    foreach ([[40, 30], [120, 60], [210, 22], [330, 48], [470, 26], [560, 70], [650, 34], [740, 58], [820, 24], [900, 52], [60, 90], [280, 94], [610, 18]] as [$x, $y]) {
        $stars .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . (($x + $y) % 3 === 0 ? 1.8 : 1.2) . '" fill="#fff" opacity=".7"/>';
    }
    $tents = '';
    $pos = [[70, 188, 1.0], [190, 196, 0.8], [310, 184, 1.15], [450, 198, 0.85], [560, 186, 1.05], [690, 196, 0.9], [810, 188, 1.1]];
    foreach ($pos as [$x, $y, $s]) {
        $w = 78 * $s;
        $h = 54 * $s;
        $tents .= '<g><polygon points="' . ($x - $w / 2) . ',' . $y . ' ' . $x . ',' . ($y - $h) . ' ' . ($x + $w / 2) . ',' . $y . '" fill="url(#tentg)"/>'
            . '<polygon points="' . ($x - 9 * $s) . ',' . $y . ' ' . $x . ',' . ($y - 30 * $s) . ' ' . ($x + 9 * $s) . ',' . $y . '" fill="#e6cf8f" opacity=".85"/></g>';
    }
    return '<svg viewBox="0 0 960 240" preserveAspectRatio="xMidYMax slice" aria-hidden="true"><defs>'
        . '<linearGradient id="skyg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0a1530"/><stop offset="1" stop-color="#1d467f"/></linearGradient>'
        . '<linearGradient id="tentg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3f6aa8"/><stop offset="1" stop-color="#1a3a6b"/></linearGradient></defs>'
        . '<rect width="960" height="240" fill="url(#skyg)"/>' . $stars
        . '<circle cx="840" cy="70" r="34" fill="#e6cf8f"/><circle cx="854" cy="62" r="30" fill="#0e2146"/>'
        . '<path d="M0 200 Q160 150 320 190 T640 180 T960 190 V240 H0Z" fill="#0d2347"/>' . $tents
        . '<path d="M0 218 Q240 196 480 214 T960 208 V240 H0Z" fill="#081631"/></svg>';
}

/** KPI tile with icon and optional sparkline. */
function kpi_x(string $ico, string $label, string $value, string $hint = '', string $spark = '', string $tone = ''): string
{
    return '<article class="kpi-x ' . e($tone) . '"><div class="kx-ico">' . icon($ico, 24) . '</div><div class="kx-body"><span>' . e($label) . '</span><strong>' . e($value) . '</strong>'
        . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '') . '</div><div class="kx-spark">' . $spark . '</div></article>';
}
