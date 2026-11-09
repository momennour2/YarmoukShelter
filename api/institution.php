<?php
declare(strict_types=1);
session_start();
ob_start();
require __DIR__ . '/app/functions.php';
require __DIR__ . '/app/portal_layout.php';

/* ---------- Guard: institution accounts only ---------- */
if (!user() || role() !== 'institution') {
    go('login.php?as=staff');
}
$instId = (int)(user()['institution_id'] ?? 0);
$iq = db()->prepare('SELECT * FROM institutions WHERE id=? AND is_active=1');
$iq->execute([$instId]);
$inst = $iq->fetch();
if (!$inst) {
    go('login.php?logout=1');
}

$page = $_GET['page'] ?? 'home';
if (!in_array($page, ['home', 'new', 'request', 'password'], true)) {
    $page = 'home';
}
$error = '';
$notice = '';

/* ---------- Download a response file (own requests only) ---------- */
if (isset($_GET['download'])) {
    $fq = db()->prepare('SELECT response_file,response_file_name FROM data_requests WHERE id=? AND institution_id=? AND response_file IS NOT NULL');
    $fq->execute([(int)$_GET['download'], $instId]);
    $f = $fq->fetch();
    $path = $f ? __DIR__ . '/uploads/requests/' . basename((string)$f['response_file']) : '';
    if (!$f || !is_file($path)) {
        http_response_code(404);
        exit('File not found');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode((string)$f['response_file_name']) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* ---------- POST handlers ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        $do = pv('do');

        if ($do === 'request') {
            $page = 'new';
            $title = pv('title');
            $type = pv('request_type');
            $details = pv('details');
            $from = pv('period_from') ?: null;
            $to = pv('period_to') ?: null;
            $needed = pv('needed_by') ?: null;
            $secs = array_values(array_intersect(['A', 'B', 'C', 'D', 'E', 'S'], (array)($_POST['sectors'] ?? [])));
            if (mb_strlen($title) < 4 || mb_strlen($title) > 220) {
                throw new RuntimeException('اكتب عنواناً واضحاً للطلب.');
            }
            if (!isset(request_types()[$type])) {
                throw new RuntimeException('اختر نوع الطلب.');
            }
            if (mb_strlen($details) < 10 || mb_strlen($details) > 4000) {
                throw new RuntimeException('اشرح المطلوب بين 10 و4000 حرف.');
            }
            foreach ([$from, $to, $needed] as $d) {
                if ($d !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    throw new RuntimeException('صيغة التاريخ غير صحيحة.');
                }
            }
            db()->prepare('INSERT INTO data_requests(institution_id,requested_by,title,request_type,details,sectors,period_from,period_to,needed_by) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$instId, user()['id'], $title, $type, $details, implode(',', $secs), $from, $to, $needed]);
            $newId = (int)db()->lastInsertId();
            audit('create', 'requests', $newId, $inst['name']);
            go('institution.php?page=request&id=' . $newId . '&sent=1');
        }

        if ($do === 'password') {
            $page = 'password';
            $cur = pv('current');
            $new = pv('password');
            $pq = db()->prepare('SELECT password_hash FROM users WHERE id=?');
            $pq->execute([user()['id']]);
            if (!password_verify($cur, (string)$pq->fetchColumn())) {
                throw new RuntimeException('كلمة المرور الحالية غير صحيحة.');
            }
            check_password_rules($new);
            db()->prepare('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?')->execute([hash_password($new), user()['id']]);
            $_SESSION['user']['must_change_password'] = 0;
            $notice = 'تم تحديث كلمة المرور.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!empty(user()['must_change_password']) && $page !== 'password') {
    go('institution.php?page=password');
}

portal_head('بوابة المؤسسات', (string)$inst['name'], 'بوابة المؤسسات الشريكة', ['home' => 'طلباتي', 'new' => 'طلب جديد', 'password' => 'كلمة المرور'], $page === 'request' ? 'home' : $page);
echo flash_box($notice) . flash_box($error, 'err');

/* =========================== HOME =========================== */
if ($page === 'home'):
    $q = db()->prepare('SELECT * FROM data_requests WHERE institution_id=? ORDER BY id DESC LIMIT 200');
    $q->execute([$instId]);
    $list = $q->fetchAll();
    $cnt = ['pending' => 0, 'fulfilled' => 0, 'rejected' => 0];
    foreach ($list as $r) {
        $cnt[$r['status']]++;
    }
    $news = db()->query("SELECT title,body FROM announcements WHERE is_active=1 AND audience IN ('institutions','all') ORDER BY id DESC LIMIT 3")->fetchAll(); ?>
<section class="hero-lux">
  <div><span class="eyebrow">شريك معتمد</span><h1><?= e($inst['name']) ?></h1><p>اطلب البيانات والإحصائيات التي تحتاجها، وسيتم الرد عليك من إدارة المخيم.</p></div>
  <a class="primary gold-btn" href="?page=new">+ طلب جديد</a>
</section>
<?php foreach ($news as $n): ?><div class="notice-card"><b><?= e($n['title']) ?></b><p><?= nl2br(e($n['body'])) ?></p></div><?php endforeach; ?>
<div class="grid-3">
  <?= kpi('قيد الدراسة', $cnt['pending'], '', 'gold') ?>
  <?= kpi('تم التنفيذ', $cnt['fulfilled'], '', 'green') ?>
  <?= kpi('مرفوضة', $cnt['rejected'], '', 'red') ?>
</div>
<section class="panel">
  <div class="panel-head"><h2>طلباتي</h2></div>
  <?php if ($list): ?>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>العنوان</th><th>النوع</th><th>التاريخ</th><th>الحالة</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $r): ?>
    <tr><td><?= (int)$r['id'] ?></td><td><?= e($r['title']) ?></td><td><?= e(request_types()[$r['request_type']] ?? '') ?></td><td><?= e(substr((string)$r['created_at'], 0, 10)) ?></td><td><?= status_badge($r['status']) ?></td><td><a href="?page=request&id=<?= (int)$r['id'] ?>">عرض</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="empty">لا توجد طلبات بعد. ابدأ بطلب جديد.</p><?php endif; ?>
</section>

<?php /* =========================== NEW =========================== */
elseif ($page === 'new'): ?>
<section class="panel narrow">
  <div class="panel-head"><h2>طلب بيانات جديد</h2></div>
  <p class="hint">تُقدَّم البيانات للمؤسسات بشكل مجمّع دون أسماء أو أرقام هوية.</p>
  <form method="post" class="req-form">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="request">
    <div class="req-group">
      <b>بيانات الطلب</b>
      <label class="field"><span>عنوان الطلب</span><input name="title" required maxlength="220" placeholder="مثال: أعداد الأسر في كل قطاع" value="<?= e($_POST['title'] ?? '') ?>"></label>
      <label class="field"><span>نوع الطلب</span><select name="request_type" required>
        <?php foreach (request_types() as $k => $l): ?><option value="<?= e($k) ?>" <?= ($_POST['request_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></label>
      <label class="field"><span>تفاصيل المطلوب <small>(10 – 4000 حرف)</small></span><textarea name="details" rows="6" required maxlength="4000" placeholder="اشرح البيانات التي تحتاجها والغرض منها"><?= e($_POST['details'] ?? '') ?></textarea></label>
    </div>
    <div class="req-group">
      <b>القطاعات المعنية</b>
      <div class="sector-checks"><?php foreach (['A', 'B', 'C', 'D', 'E', 'S'] as $s): ?><label><input type="checkbox" name="sectors[]" value="<?= $s ?>" <?= in_array($s, (array)($_POST['sectors'] ?? []), true) ? 'checked' : '' ?>> <?= $s ?></label><?php endforeach; ?></div>
      <small class="muted">اتركها فارغة لتشمل كل القطاعات.</small>
    </div>
    <div class="req-group">
      <b>الفترة والموعد</b>
      <div class="req-row three">
        <label class="field"><span>من تاريخ</span><input type="date" name="period_from" value="<?= e($_POST['period_from'] ?? '') ?>"></label>
        <label class="field"><span>إلى تاريخ</span><input type="date" name="period_to" value="<?= e($_POST['period_to'] ?? '') ?>"></label>
        <label class="field"><span>مطلوب قبل</span><input type="date" name="needed_by" value="<?= e($_POST['needed_by'] ?? '') ?>"></label>
      </div>
    </div>
    <div class="req-actions"><button class="primary gold-btn">إرسال الطلب</button><a class="secondary" href="?page=home">إلغاء</a></div>
  </form>
</section>

<?php /* =========================== REQUEST DETAIL =========================== */
elseif ($page === 'request'):
    $q = db()->prepare('SELECT * FROM data_requests WHERE id=? AND institution_id=?');
    $q->execute([(int)($_GET['id'] ?? 0), $instId]);
    $r = $q->fetch(); ?>
<section class="panel">
<?php if (!$r): ?><p class="empty">الطلب غير موجود.</p><?php else: ?>
  <?php if (isset($_GET['sent'])): ?><div class="success">تم إرسال طلبك بنجاح.</div><?php endif; ?>
  <div class="panel-head"><h2><?= e($r['title']) ?></h2><?= status_badge($r['status']) ?></div>
  <p class="muted"><?= e(request_types()[$r['request_type']] ?? '') ?> · <?= e((string)$r['created_at']) ?><?= $r['sectors'] ? ' · القطاعات: ' . e($r['sectors']) : '' ?></p>
  <div class="bubble mine"><?= nl2br(e((string)$r['details'])) ?></div>
  <?php if ($r['response_note']): ?><div class="bubble admin"><b>رد الإدارة</b><br><?= nl2br(e($r['response_note'])) ?></div><?php endif; ?>
  <?php if ($r['response_data']): $snap = json_decode((string)$r['response_data'], true);
      if (is_array($snap)) { echo '<h3>البيانات المطلوبة</h3>' . render_snapshot($snap, true); } endif; ?>
  <?php if ($r['response_file']): ?><p><a class="primary" href="?download=<?= (int)$r['id'] ?>">تنزيل الملف المرفق: <?= e($r['response_file_name']) ?></a></p><?php endif; ?>
  <a class="secondary" href="?page=home">رجوع</a>
<?php endif; ?>
</section>

<?php /* =========================== PASSWORD =========================== */
elseif ($page === 'password'): ?>
<section class="panel narrow">
  <div class="panel-head"><h2>تغيير كلمة المرور</h2></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="password">
    <label class="field"><span>كلمة المرور الحالية</span><input type="password" name="current" required></label>
    <label class="field"><span>كلمة المرور الجديدة (4 خانات على الأقل)</span><input type="password" name="password" minlength="4" required></label>
    <button class="primary gold-btn">حفظ</button>
  </form>
</section>
<?php endif;

portal_foot();
