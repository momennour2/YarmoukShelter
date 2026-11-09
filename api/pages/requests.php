<?php
declare(strict_types=1);
/* Included from index.php – staff handling of institution data requests. */

if (!can('requests', 'view')) {
    echo '<section class="panel">لا تملك صلاحية عرض طلبات المؤسسات.</section>';
    return;
}

$msg = '';
$err = '';
$uploadDir = __DIR__ . '/../uploads/requests';

/* ---------- Download attachment ---------- */
if (isset($_GET['download'])) {
    $q = db()->prepare('SELECT response_file,response_file_name FROM data_requests WHERE id=? AND response_file IS NOT NULL');
    $q->execute([(int)$_GET['download']]);
    $f = $q->fetch();
    $path = $f ? $uploadDir . '/' . basename((string)$f['response_file']) : '';
    if ($f && is_file($path)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode((string)$f['response_file_name']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
    $err = 'الملف غير موجود.';
}

/* ---------- Handle request ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'request_handle') {
    try {
        check_csrf();
        if (!can('requests', 'edit')) {
            throw new RuntimeException('لا تملك صلاحية معالجة الطلبات.');
        }
        $rid = (int)($_POST['id'] ?? 0);
        $q = db()->prepare('SELECT * FROM data_requests WHERE id=?');
        $q->execute([$rid]);
        $r = $q->fetch();
        if (!$r) {
            throw new RuntimeException('الطلب غير موجود.');
        }
        $decision = pv('decision');
        if (!in_array($decision, ['fulfilled', 'rejected', 'pending'], true)) {
            throw new RuntimeException('قرار غير صالح.');
        }
        $note = pv('response_note');
        $data = $r['response_data'];
        if (!empty($_POST['attach_snapshot'])) {
            $secs = array_values(array_intersect(['A', 'B', 'C', 'D', 'E', 'S'], (array)($_POST['sectors'] ?? [])));
            $from = pv('period_from') ?: null;
            $to = pv('period_to') ?: null;
            $data = json_encode(build_snapshot($secs, $from, $to), JSON_UNESCAPED_UNICODE);
        }
        $fileName = $r['response_file'];
        $origName = $r['response_file_name'];
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string)$_FILES['attachment']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'xlsx', 'xls', 'csv', 'docx', 'doc', 'png', 'jpg'], true)) {
                throw new RuntimeException('نوع الملف غير مسموح.');
            }
            if ($_FILES['attachment']['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('الحد الأقصى لحجم الملف 5 ميغابايت.');
            }
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0750, true);
            }
            $fileName = bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . '/' . $fileName)) {
                throw new RuntimeException('تعذر حفظ الملف.');
            }
            $origName = substr(basename((string)$_FILES['attachment']['name']), 0, 190);
        }
        db()->prepare('UPDATE data_requests SET status=?,response_note=?,response_data=?,response_file=?,response_file_name=?,handled_by=?,handled_at=NOW() WHERE id=?')
            ->execute([$decision, $note !== '' ? $note : null, $data, $fileName, $origName, user()['id'], $rid]);
        audit('handle', 'requests', $rid, $decision);
        $msg = 'تم حفظ القرار وإرساله للمؤسسة.';
    } catch (Throwable $ex) {
        $err = $ex->getMessage();
    }
}

echo flash_box($msg) . flash_box($err, 'err');

/* =========================== DETAIL =========================== */
if (isset($_GET['id'])):
    $q = db()->prepare('SELECT r.*,i.name inst_name,i.contact_person,i.phone inst_phone FROM data_requests r JOIN institutions i ON i.id=r.institution_id WHERE r.id=?');
    $q->execute([(int)$_GET['id']]);
    $r = $q->fetch();
    if (!$r): ?>
<section class="panel"><p class="empty">الطلب غير موجود.</p><a class="secondary" href="?page=requests">رجوع</a></section>
<?php else:
        $preSecs = array_filter(explode(',', (string)$r['sectors'])); ?>
<section class="panel">
  <div class="panel-head"><h2>#<?= (int)$r['id'] ?> · <?= e($r['title']) ?></h2><?= status_badge($r['status']) ?></div>
  <div class="info-grid">
    <div><span>المؤسسة</span><b><?= e($r['inst_name']) ?></b></div>
    <div><span>المسؤول</span><b><?= e($r['contact_person'] ?: '—') ?> · <?= e($r['inst_phone'] ?: '') ?></b></div>
    <div><span>النوع</span><b><?= e(request_types()[$r['request_type']] ?? '') ?></b></div>
    <div><span>مطلوب قبل</span><b><?= e($r['needed_by'] ?: '—') ?></b></div>
    <div><span>القطاعات</span><b><?= e($r['sectors'] ?: 'الكل') ?></b></div>
    <div><span>الفترة</span><b><?= e(($r['period_from'] ?: '…') . ' → ' . ($r['period_to'] ?: '…')) ?></b></div>
  </div>
  <div class="bubble mine"><?= nl2br(e((string)$r['details'])) ?></div>

  <?php if (can('requests', 'edit')): ?>
  <form method="post" enctype="multipart/form-data" class="record-form">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="request_handle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <label class="field"><span>رد الإدارة (يظهر للمؤسسة)</span><textarea name="response_note" rows="3"><?= e((string)$r['response_note']) ?></textarea></label>
    <div class="filter-block">
      <label class="check-line"><input type="checkbox" name="attach_snapshot" value="1" <?= $r['response_data'] ? 'checked' : '' ?>> إرفاق لقطة إحصائية مجمّعة (دون أسماء أو هويات)</label>
      <div class="sector-checks"><?php foreach (['A', 'B', 'C', 'D', 'E', 'S'] as $s): ?><label><input type="checkbox" name="sectors[]" value="<?= $s ?>" <?= in_array($s, $preSecs, true) ? 'checked' : '' ?>> <?= $s ?></label><?php endforeach; ?></div>
      <div class="form-grid">
        <label class="field"><span>من تاريخ (للتوزيعات)</span><input type="date" name="period_from" value="<?= e((string)$r['period_from']) ?>"></label>
        <label class="field"><span>إلى تاريخ</span><input type="date" name="period_to" value="<?= e((string)$r['period_to']) ?>"></label>
      </div>
      <p class="hint">اترك كل القطاعات بدون تحديد لتشمل اللقطة جميعها. الحد الأدنى للعرض للمؤسسة: الأعداد أقل من 5 تُخفى تلقائياً.</p>
    </div>
    <label class="field"><span>ملف مرفق (اختياري · PDF/Excel/Word/CSV · حتى 5MB)</span><input type="file" name="attachment"></label>
    <?php if ($r['response_file']): ?><p class="hint">مرفق حالي: <a href="?page=requests&download=<?= (int)$r['id'] ?>"><?= e($r['response_file_name']) ?></a></p><?php endif; ?>
    <div class="btn-row">
      <button class="primary" name="decision" value="fulfilled">تنفيذ وإرسال</button>
      <button class="secondary" name="decision" value="rejected" onclick="return confirm('رفض الطلب؟')">رفض</button>
      <button class="secondary" name="decision" value="pending">حفظ كمسودة</button>
      <a class="secondary" href="?page=requests">رجوع</a>
    </div>
  </form>
  <?php endif; ?>

  <?php if ($r['response_data']): $snap = json_decode((string)$r['response_data'], true);
      if (is_array($snap)) { echo '<h3>اللقطة المرفقة (كما يراها المتطوع · بدون إخفاء)</h3>' . render_snapshot($snap, false); } endif; ?>
</section>
<?php endif;

/* =========================== LIST =========================== */
else:
    $fs = (string)($_GET['status'] ?? '');
    $where = in_array($fs, ['pending', 'fulfilled', 'rejected'], true) ? ' WHERE r.status=' . db()->quote($fs) : '';
    $rows = db()->query("SELECT r.id,r.title,r.request_type,r.status,r.created_at,r.needed_by,i.name inst FROM data_requests r JOIN institutions i ON i.id=r.institution_id$where ORDER BY FIELD(r.status,'pending','fulfilled','rejected'),r.id DESC LIMIT 300")->fetchAll(); ?>
<section class="panel">
  <div class="panel-head"><h2>طلبات المؤسسات</h2><span class="tag"><?= count($rows) ?> طلب</span></div>
  <form method="get" class="filter-toolbar"><input type="hidden" name="page" value="requests">
    <select name="status" onchange="this.form.submit()"><option value="">كل الحالات</option><?php foreach (['pending' => 'قيد الدراسة', 'fulfilled' => 'تم التنفيذ', 'rejected' => 'مرفوض'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $fs === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  </form>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>المؤسسة</th><th>الطلب</th><th>النوع</th><th>مطلوب قبل</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><a href="?page=requests&id=<?= (int)$r['id'] ?>"><?= (int)$r['id'] ?></a></td><td><?= e($r['inst']) ?></td><td><?= e($r['title']) ?></td><td><?= e(request_types()[$r['request_type']] ?? '') ?></td><td><?= e($r['needed_by'] ?: '—') ?></td><td><?= status_badge($r['status']) ?></td><td><?= e(substr((string)$r['created_at'], 0, 10)) ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7">لا توجد طلبات.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
