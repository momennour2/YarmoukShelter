<?php
declare(strict_types=1);
/* Included from index.php – logos and site texts (general manager only). */

if (!is_admin()) {
    echo '<section class="panel">هذه الصفحة للمدير العام فقط.</section>';
    return;
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $do = pv('do');

        if ($do === 'logo_upload') {
            $slot = pv('slot');
            if (!array_key_exists($slot, logo_slots())) {
                throw new RuntimeException('خانة شعار غير معروفة.');
            }
            $f = $_FILES['logo'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('لم يصل الملف. الحد الأقصى 1.5 ميغابايت.');
            }
            if ((int)$f['size'] > 1_500_000) {
                throw new RuntimeException('حجم الصورة أكبر من 1.5 ميغابايت.');
            }
            $info = @getimagesize((string)$f['tmp_name']);
            $mimes = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];
            if (!$info || !isset($mimes[$info[2]])) {
                throw new RuntimeException('الصيغ المقبولة: PNG أو JPG أو WEBP أو GIF.');
            }
            if ($info[0] > 4000 || $info[1] > 4000) {
                throw new RuntimeException('أبعاد الصورة كبيرة جداً (الحد 4000 بكسل).');
            }
            db()->prepare('REPLACE INTO site_assets(k,mime,data) VALUES(?,?,?)')
                ->execute([$slot, $mimes[$info[2]], file_get_contents((string)$f['tmp_name'])]);
            audit('logo_upload', 'settings', null, $slot);
            $msg = 'تم حفظ الشعار.';
        }

        if ($do === 'logo_delete') {
            $slot = pv('slot');
            if (!array_key_exists($slot, logo_slots())) {
                throw new RuntimeException('خانة شعار غير معروفة.');
            }
            db()->prepare('DELETE FROM site_assets WHERE k=?')->execute([$slot]);
            audit('logo_delete', 'settings', null, $slot);
            $msg = $slot === 'main' ? 'تمت العودة إلى الشعار الأصلي.' : 'تم حذف الشعار.';
        }

        if ($do === 'dhikr_save') {
            set_setting('dhikr_enabled', !empty($_POST['dhikr_enabled']) ? '1' : '0');
            set_setting('dhikr_every', (string)max(30, min(3600, (int)($_POST['dhikr_every'] ?? 120))));
            set_setting('dhikr_stay', (string)max(5, min(60, (int)($_POST['dhikr_stay'] ?? 12))));
            audit('settings_save', 'settings', null, 'dhikr');
            $msg = 'تم حفظ إعدادات التذكير.';
        }

        if ($do === 'wa_save') {
            $n = preg_replace('/\D/', '', normalize_digits(pv('whatsapp_number'))) ?? '';
            if ($n !== '' && !preg_match('/^\d{10,15}$/', $n)) {
                throw new RuntimeException('رقم واتساب غير صالح. اكتبه بصيغة دولية مثل 970599123456.');
            }
            set_setting('whatsapp_number', $n);
            audit('settings_save', 'settings', null, 'whatsapp');
            $msg = 'تم حفظ رقم واتساب.';
        }

        if ($do === 'texts_save') {
            $tag = mb_substr(pv('site_tagline'), 0, 60);
            set_setting('site_tagline', $tag !== '' ? $tag : 'Yarmouk Shelter V5');
            audit('settings_save', 'settings');
            $msg = 'تم حفظ النصوص.';
        }
    } catch (Throwable $ex) {
        $err = $ex->getMessage();
    }
}

echo flash_box($msg) . flash_box($err, 'err');
?>
<section class="panel">
  <div class="panel-head"><h2>الشعارات</h2></div>
  <p class="hint">يظهر الشعار الرئيسي (مع النص الذي تحته) في صفحة الدخول والشريط الجانبي وبوابة النازح والمؤسسات والطباعة. شعارات الشركاء تظهر أسفل صفحة الدخول وفي تذييل البوابات. تُخزَّن الصور في قاعدة البيانات.</p>
  <div class="logo-grid">
  <?php foreach (logo_slots() as $slot => $label): $u = logo_url($slot); ?>
    <div class="logo-card">
      <b><?= e($label) ?></b>
      <div class="logo-preview"><?= $u ? '<img src="' . e($u) . '" alt="">' : '<span class="muted">— لا يوجد —</span>' ?></div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="logo_upload"><input type="hidden" name="slot" value="<?= e($slot) ?>">
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required>
        <button class="primary">رفع</button>
      </form>
      <?php if (isset(logo_versions()[$slot])): ?>
      <form method="post" onsubmit="return confirm('حذف هذا الشعار؟')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="logo_delete"><input type="hidden" name="slot" value="<?= e($slot) ?>"><button class="text-button"><?= $slot === 'main' ? 'العودة للشعار الأصلي' : 'حذف' ?></button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</section>

<section class="panel">
  <div class="panel-head"><h2>النص تحت الشعار</h2></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="texts_save">
    <label class="field"><span>النص (مثال: Yarmouk Shelter V5)</span><input name="site_tagline" maxlength="60" value="<?= e(setting('site_tagline', 'Yarmouk Shelter V5')) ?>"></label>
    <div><button class="primary">حفظ</button></div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>رسائل التذكير الدينية</h2></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="dhikr_save">
    <label class="check-line"><input type="checkbox" name="dhikr_enabled" value="1" <?= setting('dhikr_enabled', '1') === '1' ? 'checked' : '' ?>> تفعيل التذكير (آيات وأذكار وأحاديث قصيرة تظهر وتختفي لوحدها)</label>
    <label class="field"><span>تظهر كل (ثانية)</span><input type="number" name="dhikr_every" min="30" max="3600" value="<?= (int)setting('dhikr_every', '120') ?>"></label>
    <label class="field"><span>تبقى ظاهرة (ثانية)</span><input type="number" name="dhikr_stay" min="5" max="60" value="<?= (int)setting('dhikr_stay', '12') ?>"></label>
    <div><button class="primary">حفظ</button></div>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>رقم واتساب للتواصل</h2></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="wa_save">
    <label class="field"><span>الرقم بصيغة دولية بدون + (مثال: 970599123456). اتركه فارغاً لإخفاء الزر</span><input name="whatsapp_number" inputmode="numeric" value="<?= e(whatsapp_number()) ?>"></label>
    <div><button class="primary">حفظ</button></div>
  </form>
  <p class="hint">يظهر زر واتساب في صفحة الدخول وبوابة النازح وبوابة المؤسسات.</p>
</section>
