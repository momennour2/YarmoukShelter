<?php
declare(strict_types=1);

/** $nav = [page => label]. */
function portal_head(string $title, string $who, string $sub, array $nav, string $active, string $logoutUrl = 'login.php?logout=1'): void
{
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' · مركز إيواء اليرموك</title>'
        . '<link rel="icon" href="' . e((string)logo_url('main', true)) . '"><link rel="stylesheet" href="/assets/luxe.css"></head><body class="portal-body">';
    echo '<header class="portal-top"><div class="portal-top-in">'
        . '<div class="portal-brand"><img class="brand-logo-sm" src="' . e((string)logo_url('main', true)) . '" alt="" width="44" height="44"><div><b>مركز إيواء اليرموك</b><small>' . e($sub) . '</small></div></div>'
        . '<nav class="portal-nav">';
    foreach ($nav as $p => $label) {
        echo '<a class="' . ($active === $p ? 'on' : '') . '" href="?page=' . e($p) . '">' . e($label) . '</a>';
    }
    echo '</nav><div class="portal-who"><span>' . e($who) . '</span><a class="logout-pill" href="' . e($logoutUrl) . '">خروج</a></div>'
        . '</div></header><main class="portal-main">';
}

function portal_foot(): void
{
    echo '</main><footer class="portal-foot">' . partner_logos_html('partner-strip') . 'مركز إيواء اليرموك · Developed by Momen Nour</footer>' . whatsapp_button() . dhikr_script() . '</body></html>';
}

