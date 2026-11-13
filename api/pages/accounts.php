<?php
declare(strict_types=1);
/* Included from index.php – accounts, roles and partner institutions (general manager only). */

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

        /* ---------- Save user ---------- */
        if ($do === 'user_save') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $name = pv('full_name');
            $roleSel = pv('role');
            $secs = array_values(array_intersect(['A', 'B', 'C', 'D', 'E', 'S'], (array)($_POST['sectors'] ?? [])));
            $inst = (int)($_POST['institution_id'] ?? 0);
            $pw = (string)($_POST['password'] ?? '');
            $active = (int)(!empty($_POST['is_active']));
            $toDt = function (string $v): ?string {
                $v = trim($v);
                if ($v === '') {
                    return null;
                }
                $t = strtotime(str_replace('T', ' ', $v));
                if ($t === false) {
                    throw new RuntimeException('تاريخ غير صالح.');
                }
                return date('Y-m-d H:i:s', $t);
            };
            $accFrom = $toDt((string)($_POST['access_from'] ?? ''));
            $accUntil = $toDt((string)($_POST['access_until'] ?? ''));
            if ($accFrom && $accUntil && $accUntil <= $accFrom) {
                throw new RuntimeException('موعد الإغلاق يجب أن يكون بعد موعد الفتح.');
            }
            $accLocked = (int)(!empty($_POST['access_locked']));
            $hiddenCsv = implode(',', array_values(array_intersect(array_keys(hideable_features()), (array)($_POST['hide'] ?? [])))) ?: null;

            if ($name === '' || !isset(roles()[$roleSel])) {
                throw new RuntimeException('الاسم والدور مطلوبان.');
            }
            if ($roleSel === 'sector_manager' && !$secs) {
                throw new RuntimeException('اختر قطاعاً واحداً على الأقل لمسؤول القطاع.');
            }
            if ($roleSel === 'institution') {
                $iq = db()->prepare('SELECT COUNT(*) FROM institutions WHERE id=?');
                $iq->execute([$inst]);
                if (!(int)$iq->fetchColumn()) {
                    throw new RuntimeException('اختر المؤسسة المرتبطة بالحساب.');
                }
            } else {
                $inst = 0;
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                if (!$uid) {
                    $un = normalize_username(pv('username'));
                    if (!username_format_ok($un)) {
                        throw new RuntimeException('اسم المستخدم: حرفان إنجليزيان ثم شرطة ثم رقم الهوية، مثال AB-123456789.');
                    }
                    check_password_rules($pw);
                    $pdo->prepare('INSERT INTO users(username,full_name,role,institution_id,assigned_sectors,password_hash,is_active,must_change_password,access_from,access_until,access_locked,hidden_features) VALUES(?,?,?,?,?,?,?,1,?,?,?,?)')
                        ->execute([$un, $name, $roleSel, $inst ?: null, implode(',', $secs) ?: null, hash_password($pw), $active, $accFrom, $accUntil, $accLocked, $hiddenCsv]);
                    $uid = (int)$pdo->lastInsertId();
                } else {
                    $cq = $pdo->prepare('SELECT username FROM users WHERE id=?');
                    $cq->execute([$uid]);
                    $cur = $cq->fetchColumn();
                    if ($cur === false) {
                        throw new RuntimeException('المستخدم غير موجود.');
                    }
                    if ($cur === 'admin') {
                        $roleSel = 'general_manager';
                        $active = 1;
                    }
                    if ($cur === 'admin') {
                        $accFrom = $accUntil = $hiddenCsv = null;
                        $accLocked = 0;
                    }
                    $pdo->prepare('UPDATE users SET full_name=?,role=?,institution_id=?,assigned_sectors=?,is_active=?,access_from=?,access_until=?,access_locked=?,hidden_features=? WHERE id=?')
                        ->execute([$name, $roleSel, $inst ?: null, implode(',', $secs) ?: null, $active, $accFrom, $accUntil, $accLocked, $hiddenCsv, $uid]);
                    if ($pw !== '') {
                        check_password_rules($pw);
                        $pdo->prepare('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?')->execute([hash_password($pw), $uid]);
                    }
                }
                if (in_array($roleSel, ['sector_manager', 'finance', 'relief'], true)) {
                    apply_role_preset($uid, $roleSel, $secs);
                } elseif (in_array($roleSel, ['general_manager', 'institution'], true)) {
                    $pdo->prepare('DELETE FROM user_permissions WHERE user_id=?')->execute([$uid]);
                }
                $pdo->commit();
            } catch (Throwable $inner) {
                $pdo->rollBack();
                throw $inner;
            }
            audit('save_account', 'users', $uid, $roleSel);
            $msg = $roleSel === 'staff'
                ? 'تم حفظ الحساب. اضبط صلاحياته من صفحة «المستخدمون والصلاحيات».'
                : 'تم حفظ الحساب وتطبيق صلاحيات الدور تلقائياً.';
        }

        /* ---------- Quick lock / unlock ---------- */
        if ($do === 'user_lock') {
            $uid = (int)($_POST['id'] ?? 0);
            $lock = (int)(!empty($_POST['lock']));
            $upd = db()->prepare("UPDATE users SET access_locked=? WHERE id=? AND username<>'admin' AND role<>'general_manager'");
            $upd->execute([$lock, $uid]);
            audit($lock ? 'lock_account' : 'unlock_account', 'users', $uid);
            $msg = $lock ? 'تم إغلاق الحساب فوراً.' : 'تم فتح الحساب.';
        }

        /* ---------- Reset a resident's password (back to birth-year first login) ---------- */
        if ($do === 'resident_reset') {
            $nid = normalize_digits(pv('national_id'));
            if (!preg_match('/^\d{5,20}$/', $nid)) {
                throw new RuntimeException('اكتب رقم هوية صحيحاً.');
            }
            $del = db()->prepare('DELETE FROM resident_accounts WHERE national_id=?');
            $del->execute([$nid]);
            audit('resident_pw_reset', 'users', null, $nid);
            $msg = $del->rowCount() ? 'تمت إعادة التعيين: سيدخل النازح بسنة ميلاده مرة أخرى ثم يختار كلمة جديدة.' : 'هذا النازح لم يغيّر كلمة مروره بعد (يدخل أصلاً بسنة الميلاد).';
        }

        /* ---------- Save / toggle institution ---------- */
        if ($do === 'inst_save') {
            $name = pv('name');
            if ($name === '') {
                throw new RuntimeException('اسم المؤسسة مطلوب.');
            }
            db()->prepare('INSERT INTO institutions(name,org_type,contact_person,phone,email) VALUES(?,?,?,?,?)')
                ->execute([$name, pv('org_type') ?: null, pv('contact_person') ?: null, pv('phone') ?: null, pv('email') ?: null]);
            audit('create', 'institutions', (int)db()->lastInsertId(), $name);
            $msg = 'تمت إضافة المؤسسة. أنشئ لها حساب دخول من النموذج أعلاه.';
        }
        if ($do === 'inst_toggle') {
            db()->prepare('UPDATE institutions SET is_active=1-is_active WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'تم تحديث حالة المؤسسة.';
        }
    } catch (Throwable $ex) {
        $err = $ex->getMessage();
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $q = db()->prepare('SELECT * FROM users WHERE id=?');
    $q->execute([(int)$_GET['edit']]);
    $edit = $q->fetch() ?: null;
}
$editSecs = $edit ? array_filter(explode(',', (string)$edit['assigned_sectors'])) : [];
$insts = db()->query('SELECT * FROM institutions ORDER BY name')->fetchAll();
$users = db()->query('SELECT u.*,i.name inst_name FROM users u LEFT JOIN institutions i ON i.id=u.institution_id ORDER BY u.id')->fetchAll();
echo flash_box($msg) . flash_box($err, 'err');
?>
<section class="panel">
  <div class="panel-head"><h2><?= $edit ? 'تعديل حساب: ' . e($edit['username']) : 'إنشاء حساب جديد' ?></h2><?= $edit ? '<a class="secondary" href="?page=accounts">حساب جديد</a>' : '' ?></div>
  <form method="post" id="acc-form">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="user_save"><input type="hidden" name="user_id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="form-grid">
      <label class="field"><span>اسم المستخدم (حرفان - رقم الهوية)</span><input name="username" dir="ltr" placeholder="AB-123456789" value="<?= e($edit['username'] ?? '') ?>" <?= $edit ? 'readonly' : 'required' ?>></label>
      <label class="field"><span>الاسم الكامل</span><input name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" required></label>
      <label class="field"><span>الدور</span><select name="role" id="role-sel">
        <?php foreach (roles() as $k => $l): ?><option value="<?= e($k) ?>" <?= (($edit['role'] ?? 'staff') === $k) ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></label>
      <label class="field"><span><?= $edit ? 'كلمة مرور جديدة (اتركها فارغة للإبقاء)' : 'كلمة مرور مؤقتة (4 خانات فأكثر، ويمكن أن تكون بسيطة)' ?></span><input type="password" name="password" minlength="4" <?= $edit ? '' : 'required' ?> autocomplete="new-password"></label>
    </div>
    <div class="filter-block role-box" data-for="sector_manager"><b>قطاعات المسؤول</b>
      <div class="sector-checks"><?php foreach (['A', 'B', 'C', 'D', 'E', 'S'] as $s): ?><label><input type="checkbox" name="sectors[]" value="<?= $s ?>" <?= in_array($s, $editSecs, true) ? 'checked' : '' ?>> <?= $s ?></label><?php endforeach; ?></div>
      <p class="hint">يرى المسؤول بيانات قطاعه وترشيحاته وأرصدته ورسائل أسره فقط (ويشمل القطاعات الفرعية AS/BS/CS/DS/S1 تلقائياً).</p>
    </div>
    <div class="filter-block role-box" data-for="institution"><b>المؤسسة المرتبطة</b>
      <select name="institution_id"><option value="0">— اختر —</option><?php foreach ($insts as $i): ?><option value="<?= (int)$i['id'] ?>" <?= (int)($edit['institution_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?></select>
    </div>
    <label class="check-line"><input type="checkbox" name="is_active" value="1" <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>> الحساب مفعّل</label>
    <div class="filter-block" id="access-box"><b>متى يُفتح الحساب ومتى يُغلق</b>
      <div class="form-grid">
        <label class="field"><span>يُفتح في (اتركه فارغاً = مفتوح الآن)</span><input type="datetime-local" name="access_from" value="<?= e($edit && $edit['access_from'] ? date('Y-m-d\TH:i', strtotime((string)$edit['access_from'])) : '') ?>"></label>
        <label class="field"><span>يُغلق في (اتركه فارغاً = بدون نهاية)</span><input type="datetime-local" name="access_until" value="<?= e($edit && $edit['access_until'] ? date('Y-m-d\TH:i', strtotime((string)$edit['access_until'])) : '') ?>"></label>
      </div>
      <label class="check-line"><input type="checkbox" name="access_locked" value="1" <?= ($edit && $edit['access_locked']) ? 'checked' : '' ?>> إغلاق يدوي فوري (يطرد المستخدم من النظام في أول طلب له)</label>
      <p class="hint">التوقيت: فلسطين. لا ينطبق على المدير العام. الإغلاق يسري على جلسة المستخدم المفتوحة فعلاً وليس عند تسجيل الدخول فقط.</p>
    </div>
    <div class="filter-block" id="hide-box"><b>ما الذي يُخفى عن هذا الحساب</b>
      <p class="hint">علّم ما تريد <u>إخفاءه</u>. الإخفاء يُطبَّق على الخادم (القوائم والصفحات والتصدير)، وليس مجرد إخفاء شكلي.</p>
      <div class="sector-checks">
      <?php $hid = $edit ? parse_hidden($edit['hidden_features'] ?? '') : []; foreach (hideable_features() as $hk => $hl): ?>
        <label><input type="checkbox" name="hide[]" value="<?= e($hk) ?>" <?= in_array($hk, $hid, true) ? 'checked' : '' ?>> <?= e($hl) ?></label>
      <?php endforeach; ?>
      </div>
    </div>
    <p class="hint">اسم الدخول: حرفان إنجليزيان (مثل أول حرفين من اسم المتطوع) ثم شرطة ثم رقم الهوية، مثال <b dir="ltr">MN-123456789</b>. كلمة المرور تُخزَّن مشفّرة بخوارزمية قوية (Argon2id) مهما كانت بسيطة.</p>
    <p class="hint">عند اختيار دور جاهز تُطبَّق صلاحياته تلقائياً. دور «متطوع» يترك الصلاحيات للتخصيص اليدوي من صفحة المستخدمين والصلاحيات.</p>
    <button class="primary">حفظ الحساب</button>
  </form>
</section>

<section class="panel">
  <div class="panel-head"><h2>الحسابات</h2><span class="tag"><?= count($users) ?> حساب</span></div>
  <div class="table-wrap"><table><thead><tr><th>المستخدم</th><th>الاسم</th><th>الدور</th><th>القطاعات / المؤسسة</th><th>الحالة</th><th>موعد الفتح / الإغلاق</th><th>آخر دخول</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): $r = $u['username'] === 'admin' ? 'general_manager' : (string)$u['role']; $why = access_block_reason($u); ?>
    <tr><td><?= e($u['username']) ?></td><td><?= e($u['full_name']) ?></td><td><?= badge(role_label($r), $r === 'general_manager' ? 'gold' : 'blue') ?></td>
      <td><?= e($r === 'institution' ? (string)$u['inst_name'] : (string)$u['assigned_sectors']) ?></td>
      <td><?= $why === null ? badge('مفتوح', 'green') : badge($u['access_locked'] ? 'مغلق يدوياً' : (!$u['is_active'] ? 'موقوف' : 'خارج الموعد'), 'red') ?></td>
      <td><small><?= $u['access_from'] ? 'من ' . e(substr((string)$u['access_from'], 0, 16)) : '' ?> <?= $u['access_until'] ? 'إلى ' . e(substr((string)$u['access_until'], 0, 16)) : '' ?><?= (!$u['access_from'] && !$u['access_until']) ? '—' : '' ?></small></td>
      <td><?= e((string)$u['last_login_at']) ?></td>
      <td class="btn-row"><a href="?page=accounts&edit=<?= (int)$u['id'] ?>">تعديل</a>
        <?php if ($r !== 'general_manager'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="user_lock"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="lock" value="<?= $u['access_locked'] ? 0 : 1 ?>"><button class="text-button"><?= $u['access_locked'] ? 'فتح' : 'إغلاق فوري' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="panel">
  <div class="panel-head"><h2>إعادة تعيين كلمة مرور نازح</h2></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="resident_reset">
    <label class="field"><span>رقم هوية النازح</span><input name="national_id" inputmode="numeric" required></label>
    <div><button class="primary">إعادة التعيين</button></div>
  </form>
  <p class="hint">بعد الإعادة يدخل النازح بسنة ميلاده كأول مرة، ثم يُطلب منه اختيار كلمة مرور جديدة.</p>
</section>

<section class="panel">
  <div class="panel-head"><h2>المؤسسات الشريكة</h2></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="inst_save">
    <label class="field"><span>اسم المؤسسة</span><input name="name" required></label>
    <label class="field"><span>النوع (أممية / محلية / حكومية…)</span><input name="org_type"></label>
    <label class="field"><span>المسؤول</span><input name="contact_person"></label>
    <label class="field"><span>الهاتف</span><input name="phone"></label>
    <label class="field"><span>البريد</span><input type="email" name="email"></label>
    <div><button class="primary">إضافة مؤسسة</button></div>
  </form>
  <div class="table-wrap"><table><thead><tr><th>المؤسسة</th><th>النوع</th><th>المسؤول</th><th>الهاتف</th><th>الحالة</th><th></th></tr></thead><tbody>
  <?php foreach ($insts as $i): ?>
    <tr><td><?= e($i['name']) ?></td><td><?= e($i['org_type']) ?></td><td><?= e($i['contact_person']) ?></td><td><?= e($i['phone']) ?></td><td><?= $i['is_active'] ? badge('نشطة', 'green') : badge('موقوفة', 'red') ?></td>
      <td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="inst_toggle"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="text-button"><?= $i['is_active'] ? 'إيقاف' : 'تفعيل' ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$insts): ?><tr><td colspan="6">لا توجد مؤسسات.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<script>
(function () {
  var sel = document.getElementById('role-sel');
  function sync() { document.querySelectorAll('.role-box').forEach(function (b) { b.style.display = b.dataset.for === sel.value ? '' : 'none'; }); }
  sel.addEventListener('change', sync); sync();
})();
</script>
