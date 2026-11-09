<?php
declare(strict_types=1);
/* Included from index.php – announcements shown on the resident / institution portals (general manager only). */

if (!is_admin()) {
    echo '<section class="panel">هذه الصفحة للمدير العام فقط.</section>';
    return;
}

$msg = '';
$err = '';
$aud = ['residents' => 'النازحون', 'institutions' => 'المؤسسات', 'all' => 'الجميع'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $do = pv('do');
        if ($do === 'ann_add') {
            $title = pv('title');
            $body = pv('body');
            $a = pv('audience');
            if ($title === '' || $body === '' || !isset($aud[$a])) {
                throw new RuntimeException('العنوان والنص والجمهور مطلوبة.');
            }
            if (mb_strlen($title) > 200 || mb_strlen($body) > 2000) {
                throw new RuntimeException('النص أطول من المسموح.');
            }
            db()->prepare('INSERT INTO announcements(title,body,audience,created_by) VALUES(?,?,?,?)')->execute([$title, $body, $a, user()['id']]);
            audit('create', 'announcements', (int)db()->lastInsertId(), $title);
            $msg = 'تم نشر الإعلان.';
        } elseif ($do === 'ann_toggle') {
            db()->prepare('UPDATE announcements SET is_active=1-is_active WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'تم تحديث الإعلان.';
        } elseif ($do === 'ann_delete') {
            db()->prepare('DELETE FROM announcements WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'تم حذف الإعلان.';
        }
    } catch (Throwable $ex) {
        $err = $ex->getMessage();
    }
}

$rows = db()->query('SELECT * FROM announcements ORDER BY id DESC LIMIT 100')->fetchAll();
echo flash_box($msg) . flash_box($err, 'err');
?>
<section class="panel">
  <div class="panel-head"><h2>إعلان جديد</h2></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="ann_add">
    <div class="form-grid">
      <label class="field"><span>العنوان</span><input name="title" maxlength="200" required></label>
      <label class="field"><span>الجمهور</span><select name="audience"><?php foreach ($aud as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="field"><span>النص</span><textarea name="body" rows="3" maxlength="2000" required></textarea></label>
    <button class="primary">نشر</button>
  </form>
</section>
<section class="panel">
  <div class="panel-head"><h2>الإعلانات</h2></div>
  <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>العنوان</th><th>الجمهور</th><th>الحالة</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e(substr((string)$r['created_at'], 0, 10)) ?></td><td><?= e($r['title']) ?></td><td><?= e($aud[$r['audience']] ?? '') ?></td><td><?= $r['is_active'] ? badge('ظاهر', 'green') : badge('مخفي') ?></td>
      <td class="btn-row">
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="ann_toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="text-button"><?= $r['is_active'] ? 'إخفاء' : 'إظهار' ?></button></form>
        <form method="post" onsubmit="return confirm('حذف الإعلان؟')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="ann_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="text-button danger-link">حذف</button></form>
      </td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5">لا توجد إعلانات.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
