<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/app/functions.php';

$error = '';
$tab = ($_GET['as'] ?? '') === 'staff' ? 'staff' : 'resident';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $kind = pv('kind');

        if ($kind === 'resident') {
            $tab = 'resident';
            $nid = normalize_digits(pv('national_id'));
            $year = normalize_digits(pv('birth_year'));
            if (!preg_match('/^\d{5,20}$/', $nid) || !preg_match('/^(19|20)\d{2}$/', $year)) {
                throw new RuntimeException('تأكد من كتابة رقم الهوية وسنة الميلاد (4 أرقام) بشكل صحيح.');
            }
            if (throttle_blocked('resident', $nid)) {
                throw new RuntimeException('تم إيقاف المحاولات مؤقتاً بسبب كثرة المحاولات الخاطئة. حاول بعد 15 دقيقة.');
            }
            $q = db()->prepare("SELECT * FROM profiles WHERE national_id=? AND YEAR(birth_date)=? AND archived_at IS NULL AND sector<>'R' ORDER BY is_household_head DESC,id LIMIT 1");
            $q->execute([$nid, (int)$year]);
            $p = $q->fetch();
            throttle_log('resident', $nid, (bool)$p);
            if (!$p) {
                throw new RuntimeException('البيانات غير مطابقة. تأكد من رقم الهوية وسنة الميلاد.');
            }
            session_regenerate_id(true);
            unset($_SESSION['user']);
            $_SESSION['resident'] = [
                'profile_id' => (int)$p['id'],
                'national_id' => $p['national_id'],
                'hh_id' => $p['hh_id'],
                'full_name' => $p['full_name'],
                'sector' => $p['sector'],
                'tent' => $p['tent'],
                'at' => time(),
            ];
            
            // توجيه قسري عبر JS لضمان عدم التعليق على Vercel
            echo "<script>window.location.href='resident.php';</script>";
            exit;
        }

        if ($kind === 'staff') {
            $tab = 'staff';
            $unTyped = normalize_username(pv('username')); 
            $un = mb_strtolower($unTyped);
            if (throttle_blocked('staff', $un)) {
                throw new RuntimeException('تم إيقاف المحاولات مؤقتاً. حاول بعد 15 دقيقة.');
            }
            $q = db()->prepare('SELECT * FROM users WHERE username=? AND is_active=1');
            $q->execute([$unTyped]);
            $u = $q->fetch();
            $plain = (string)($_POST['password'] ?? '');
            $ok = $u && password_verify($plain, $u['password_hash']);
            throttle_log('staff', $un, (bool)$ok);
            if (!$ok) {
                throw new RuntimeException('اسم المستخدم أو كلمة المرور غير صحيحة.');
            }
            if (pw_needs_rehash((string)$u['password_hash'])) {   
                db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([hash_password($plain), $u['id']]);
            }
            session_regenerate_id(true);
            unset($_SESSION['resident']);
            $role = $u['username'] === 'admin' ? 'general_manager' : (string)($u['role'] ?? 'staff');
            $_SESSION['user'] = [
                'id' => (int)$u['id'],
                'username' => $u['username'],
                'full_name' => $u['full_name'],
                'role' => $role,
                'institution_id' => isset($u['institution_id']) ? (int)$u['institution_id'] : null,
                'must_change_password' => (int)$u['must_change_password'],
                'is_admin' => $u['username'] === 'admin' || $role === 'general_manager',
            ];
            db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$u['id']]);
            audit('login', 'auth');

            $redirect_url = ($role === 'institution') 
                ? '../institution.php' . ($u['must_change_password'] ? '?page=password' : '')
                : '../index.php' . ($u['must_change_password'] ? '?page=change_password' : '');

            // توجيه قسري عبر JS لتجاوز عقبة الـ Serverless Headers
            echo "<script>window.location.href='" . $redirect_url . "';</script>";
            exit;
        }
    } catch (PDOException $e) {
        $error = 'قاعدة البيانات غير مهيأة لـ V5. نفّذ database/migration_v5.sql ثم أعد المحاولة. (' . $e->getCode() . ')';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>مخيم اليرموك للإيواء · تسجيل الدخول</title>
<link rel="icon" href="assets/logo-sm.jpg">
<link rel="stylesheet" href="assets/luxe.css">
</head>
<body class="auth-body">
<div class="auth-wrap">
  <section class="auth-brand">
    <div class="auth-pattern"></div>
    <img class="brand-logo" src="assets/logo.jpg" alt="شعار مخيم اليرموك للإيواء" width="150" height="150">
    <h1> إيواء اليرموك</h1>
    <p class="auth-lead">بوابة موحّدة للنازحين والمؤسسات الشريكة وفرق الإدارة، بخدمة أسرع وشفافية أكبر.</p>
    <ul class="auth-points">
      <li><b>النازحون</b><span>تحقّق من استلام اليوم، وأرسل شكوى أو اقتراحاً</span></li>
      <li><b>المؤسسات</b><span>اطلب البيانات والإحصائيات وتابع الرد</span></li>
      <li><b>مسؤولو القطاعات</b><span>ترشيحات القطاع وأرصدته فقط</span></li>
      <li><b>الإدارة والمالية والإغاثة</b><span>لوحات تحكم كاملة بصلاحيات دقيقة</span></li>
    </ul>
    <small class="auth-credit">Developed by Momen Nour</small>
  </section>

  <section class="auth-panel">
    <div class="auth-card">
      <div class="tabs" role="tablist">
        <button type="button" class="tab <?= $tab === 'resident' ? 'on' : '' ?>" data-tab="resident">أنا نازح</button>
        <button type="button" class="tab <?= $tab === 'staff' ? 'on' : '' ?>" data-tab="staff">متطوع / مؤسسة</button>
      </div>

      <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

      <form method="post" class="auth-form <?= $tab === 'resident' ? '' : 'hidden' ?>" data-pane="resident" autocomplete="off">
        <h2>مرحباً بك</h2>
        <p class="muted">أدخل رقم هويتك وسنة ميلادك للاطلاع على حالتك.</p>
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="kind" value="resident">
        <label class="field"><span>رقم الهوية</span><input name="national_id" inputmode="numeric" required placeholder="مثال: 123456789"></label>
        <label class="field"><span>سنة الميلاد</span><input name="birth_year" inputmode="numeric" maxlength="4" required placeholder="مثال: 1985"></label>
        <button class="primary wide gold-btn">دخول</button>
        <p class="fine">بياناتك محمية. لا يظهر لك إلا ما يخصّك وأسرتك.</p>
      </form>

      <form method="post" class="auth-form <?= $tab === 'staff' ? '' : 'hidden' ?>" data-pane="staff">
        <h2>دخول الفريق والمؤسسات</h2>
        
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="kind" value="staff">
        <label class="field"><span>اسم المستخدم</span><input name="username" required autocomplete="username" dir="ltr" placeholder="AB-123456789" autocapitalize="characters" spellcheck="false" style="text-align:left"></label>
        <label class="field"><span>كلمة المرور</span><input type="password" name="password" required autocomplete="current-password"></label>
        <button class="primary wide gold-btn">تسجيل الدخول</button>
      </form>
    </div>
  </section>
</div>
<script>
document.querySelectorAll('.tab').forEach(function (b) {
  b.addEventListener('click', function () {
    document.querySelectorAll('.tab').forEach(function (x) { x.classList.toggle('on', x === b); });
    document.querySelectorAll('[data-pane]').forEach(function (p) { p.classList.toggle('hidden', p.dataset.pane !== b.dataset.tab); });
  });
});
</script>
</body>
</html>