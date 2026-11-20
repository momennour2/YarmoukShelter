<?php
declare(strict_types=1);

/* =====================================================================
 * Never show a blank page: any uncaught error becomes a readable message.
 * The real reason is shown only to a logged-in general manager (and always logged).
 * ===================================================================== */

function render_fatal(string $detail): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $admin = false;
    try {
        $admin = function_exists('user') && user() && function_exists('is_admin') && is_admin();
    } catch (Throwable $ignore) {
    }
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>خطأ</title>'
        . '<style>body{font-family:Tahoma,sans-serif;background:#faf8f3;color:#0a1530;padding:40px 16px;line-height:1.9}.b{max-width:720px;margin:auto;background:#fff;border:1px solid #e8e2d3;border-radius:18px;padding:28px;box-shadow:0 10px 40px rgba(10,21,48,.1)}pre{background:#0a1530;color:#e6cf8f;padding:14px;border-radius:12px;direction:ltr;text-align:left;overflow:auto;white-space:pre-wrap}a{color:#1a3a6b}</style></head><body><div class="b">'
        . '<h2>حدث خطأ غير متوقع</h2><p>لم يكتمل تحميل الصفحة. جرّب مرة أخرى، وإن تكرر أبلغ مدير النظام.</p>';
    if ($admin) {
        echo '<p>تفاصيل الخطأ للمدير (أرسلها للمطوّر):</p><pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '<p><a href="index.php">العودة للوحة التحكم</a></p></div></body></html>';
}

set_exception_handler(function (Throwable $e): void {
    $d = get_class($e) . ': ' . $e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine();
    error_log($d);
    render_fatal($d);
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        $d = 'Fatal: ' . $err['message'] . "\n" . basename((string)$err['file']) . ':' . $err['line'];
        error_log($d);
        render_fatal($d);
    }
});
