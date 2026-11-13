<?php
declare(strict_types=1);
require_once __DIR__ . '/app/session_db.php';
ob_start();
require_once __DIR__ . '/app/functions.php';
require __DIR__ . '/app/portal_layout.php';

/* ---------- Session guard ---------- */
$res = $_SESSION['resident'] ?? null;
if (!$res || (time() - (int)($res['at'] ?? 0)) > 1800) {
    unset($_SESSION['resident']);
    go('login.php');
}
$_SESSION['resident']['at'] = time();

$pq = db()->prepare("SELECT * FROM profiles WHERE id=? AND archived_at IS NULL AND sector<>'R'");
$pq->execute([$res['profile_id']]);
$me = $pq->fetch();
if (!$me) {
    unset($_SESSION['resident']);
    go('login.php');
}

/* National IDs under which this family's aid is recorded: own ID + head of household ID. */
$hq = db()->prepare("SELECT national_id FROM profiles WHERE hh_id=? AND is_household_head=1 AND archived_at IS NULL AND sector<>'R' LIMIT 1");
$hq->execute([$me['hh_id']]);
$headId = (string)($hq->fetchColumn() ?: '');
$ids = array_values(array_unique(array_filter([(string)$me['national_id'], $headId])));

$page = $_GET['page'] ?? 'home';
if (!in_array($page, ['home', 'new', 'tickets', 'ticket', 'setpass'], true)) {
    $page = 'home';
}
$error = '';

/* ---------- First login: the birth year is only temporary, force a personal password ---------- */
$mustSet = !empty($_SESSION['resident']['must_set_password']);
if ($mustSet && $page !== 'setpass') {
    go('resident.php?page=setpass');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'setpass') {
    try {
        check_csrf();
        $p1 = normalize_digits((string)($_POST['password'] ?? ''));
        $p2 = normalize_digits((string)($_POST['password2'] ?? ''));
        check_password_rules($p1);
        if ($p1 !== $p2) {
            throw new RuntimeException('كلمتا المرور غير متطابقتين.');
        }
        if (preg_match('/^(19|20)\\d{2}$/', $p1)) {
            throw new RuntimeException('لا تستخدم سنة الميلاد ككلمة مرور؛ اختر كلمة مرور أخرى.');
        }
        db()->prepare('REPLACE INTO resident_accounts(national_id,password_hash,last_login_at) VALUES(?,?,NOW())')
            ->execute([$me['national_id'], hash_password($p1)]);
        $_SESSION['resident']['must_set_password'] = 0;
        go('resident.php?pw=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $page = 'setpass';
    }
}

/* ---------- Submit ticket ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ticket') {
    try {
        check_csrf();
        $type = pv('ticket_type');
        $subject = pv('subject');
        $body = pv('body');
        $phone = normalize_digits(pv('phone'));
        if (!isset(ticket_types()[$type])) {
            throw new RuntimeException('اختر نوع الرسالة.');
        }
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200) {
            throw new RuntimeException('اكتب عنواناً واضحاً (3 أحرف على الأقل).');
        }
        if (mb_strlen($body) < 10 || mb_strlen($body) > 2000) {
            throw new RuntimeException('اشرح الموضوع بين 10 و2000 حرف.');
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $phone)) {
            throw new RuntimeException('رقم الهاتف غير صحيح.');
        }
        $cq = db()->prepare('SELECT COUNT(*) FROM tickets WHERE national_id=? AND DATE(created_at)=CURDATE()');
        $cq->execute([$me['national_id']]);
        if ((int)$cq->fetchColumn() >= 5) {
            throw new RuntimeException('وصلت للحد الأقصى اليومي (5 رسائل). حاول غداً.');
        }
        $ins = db()->prepare('INSERT INTO tickets(profile_id,national_id,hh_id,full_name,sector,tent,phone,ticket_type,subject,body,priority) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
            $me['id'], $me['national_id'], $me['hh_id'], $me['full_name'], $me['sector'], $me['tent'],
            $phone !== '' ? $phone : ($me['phone'] ?: null), $type, $subject, $body,
            $type === 'aid_issue' ? 'high' : 'normal',
        ]);
        go('resident.php?page=tickets&sent=' . (int)db()->lastInsertId());
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $page = 'new';
    }
}

/* ---------- Data for the home page ---------- */
$todayRows = [];
$lastByCat = [];
$history = [];
$family = [];
$news = [];
if ($page === 'home') {
    $q = db()->prepare("SELECT category,package,delivery_date,donor,distribution_type FROM beneficiaries WHERE delivery_date=CURDATE() AND (distribution_type='FULL' OR national_id IN (" . ph($ids) . ')) ORDER BY id DESC');
    $q->execute($ids);
    $todayRows = $q->fetchAll();

    $lq = db()->prepare("SELECT delivery_date,package FROM beneficiaries WHERE national_id IN (" . ph($ids) . ") AND category=? AND distribution_type='NORMAL' ORDER BY delivery_date DESC,id DESC LIMIT 1");
    foreach (['Food' => 'الطرود الغذائية', 'Financial' => 'المساعدات النقدية', 'Logistics' => 'المساعدات اللوجستية'] as $cat => $label) {
        $lq->execute(array_merge($ids, [$cat]));
        $lastByCat[$label] = $lq->fetch() ?: null;
    }

    $hq2 = db()->prepare("SELECT delivery_date,category,package,donor FROM beneficiaries WHERE national_id IN (" . ph($ids) . ") AND distribution_type='NORMAL' ORDER BY delivery_date DESC,id DESC LIMIT 10");
    $hq2->execute($ids);
    $history = $hq2->fetchAll();

    $fq = db()->prepare('SELECT full_name,family_member,birth_date,is_household_head FROM profiles WHERE hh_id=? AND archived_at IS NULL AND sector<>\'R\' AND ' . ACTIVE_SQL . ' ORDER BY is_household_head DESC,id');
    $fq->execute([$me['hh_id']]);
    $family = $fq->fetchAll();

    $news = db()->query("SELECT title,body,created_at FROM announcements WHERE is_active=1 AND audience IN ('residents','all') ORDER BY id DESC LIMIT 3")->fetchAll();
}

$relMap = ['Husband' => 'الزوج', 'Wife' => 'الزوجة', 'Son' => 'ابن', 'Daughter' => 'ابنة'];
$catMap = ['Food' => 'غذائية', 'Financial' => 'نقدية', 'Logistics' => 'لوجستية'];
$first = explode(' ', trim((string)$me['full_name']))[0] ?? '';

portal_head('بوابة النازح', (string)$me['full_name'], 'بوابة الأسر', $mustSet ? ['setpass' => 'كلمة المرور'] : ['home' => 'حالتي', 'new' => 'رسالة جديدة', 'tickets' => 'رسائلي', 'setpass' => 'كلمة المرور'], $page === 'ticket' ? 'tickets' : $page);

/* =========================== HOME =========================== */
if ($page === 'home'):
    $received = count($todayRows) > 0;
    ?>
<?php if (isset($_GET['pw'])): ?><div class="success">تم حفظ كلمة المرور. استخدمها في دخولك القادم.</div><?php endif; ?>
<section class="hero-lux">
  <div>
    <span class="eyebrow">أهلاً وسهلاً</span>
    <h1>مرحباً <?= e($first) ?></h1>
    <p>القطاع <b><?= e($me['sector']) ?></b> · الخيمة <b><?= e($me['tent'] ?: '—') ?></b> · رقم الأسرة <b><?= e($me['hh_id']) ?></b></p>
  </div>
  <div class="hero-date"><?= e(date('Y-m-d')) ?></div>
</section>

<?php foreach ($news as $n): ?>
<div class="notice-card"><b><?= e($n['title']) ?></b><p><?= nl2br(e($n['body'])) ?></p></div>
<?php endforeach; ?>

<section class="status-card <?= $received ? 'yes' : 'no' ?>">
  <div class="status-ico"><?= $received ? '✓' : '○' ?></div>
  <div>
    <h2><?= $received ? 'تم تسجيل استلامك اليوم' : 'لا يوجد استلام مسجّل لك اليوم' ?></h2>
    <?php if ($received): ?>
      <ul class="plain">
      <?php foreach ($todayRows as $t): ?>
        <li><?= $t['distribution_type'] === 'FULL' ? 'توزيع عام للمخيم' : 'مساعدة ' . e($catMap[$t['category']] ?? $t['category']) ?> — <b><?= e($t['package']) ?></b><?= $t['donor'] ? ' · ' . e($t['donor']) : '' ?></li>
      <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="muted">إن استلمت اليوم ولم يظهر هنا، أبلغنا من خلال <a href="?page=new&type=aid_issue">مشكلة في الاستلام</a>.</p>
    <?php endif; ?>
  </div>
</section>

<div class="grid-3">
  <?php foreach ($lastByCat as $label => $row): ?>
  <article class="kpi">
    <span>آخر استلام · <?= e($label) ?></span>
    <strong class="sm"><?= $row ? e($row['delivery_date']) : 'لم يُسجّل بعد' ?></strong>
    <small><?= $row ? e($row['package']) : '—' ?></small>
  </article>
  <?php endforeach; ?>
</div>

<section class="panel">
  <div class="panel-head"><h2>آخر الاستلامات</h2></div>
  <?php if ($history): ?>
  <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>النوع</th><th>الطرد / المساعدة</th><th>الجهة المانحة</th></tr></thead><tbody>
    <?php foreach ($history as $h): ?>
    <tr><td><?= e($h['delivery_date']) ?></td><td><?= e($catMap[$h['category']] ?? $h['category']) ?></td><td><?= e($h['package']) ?></td><td><?= e($h['donor']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="empty">لا توجد استلامات مسجّلة بعد.</p><?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>أفراد أسرتي</h2><span class="tag"><?= count($family) ?> أفراد</span></div>
  <div class="table-wrap"><table><thead><tr><th>الاسم</th><th>الصلة</th><th>العمر</th></tr></thead><tbody>
    <?php foreach ($family as $f): ?>
    <tr><td><?= e($f['full_name']) ?><?= (int)$f['is_household_head'] === 1 ? ' ' . badge('ربّ الأسرة', 'gold') : '' ?></td><td><?= e($relMap[$f['family_member']] ?? ($f['family_member'] ?: '—')) ?></td><td><?= e(age_years($f['birth_date'])) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <p class="hint">إذا وجدت خطأً في بيانات الأسرة أرسل لنا <a href="?page=new&type=inquiry">استفساراً</a>.</p>
</section>

<?php /* =========================== SET PASSWORD =========================== */
elseif ($page === 'setpass'): ?>
<section class="panel narrow">
  <div class="panel-head"><h2><?= $mustSet ? 'اختر كلمة مرور خاصة بك' : 'تغيير كلمة المرور' ?></h2></div>
  <?php if ($mustSet): ?><p class="muted">دخلت بسنة الميلاد وهي مؤقتة. اختر كلمة مرور (4 خانات فأكثر) لتدخل بها في المرات القادمة مع رقم هويتك.</p><?php endif; ?>
  <?= flash_box($error, 'err') ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="setpass">
    <label class="field"><span>كلمة المرور الجديدة</span><input type="password" name="password" minlength="4" required autocomplete="new-password"></label>
    <label class="field"><span>أعد كتابتها</span><input type="password" name="password2" minlength="4" required autocomplete="new-password"></label>
    <button class="primary gold-btn">حفظ والمتابعة</button>
  </form>
</section>

<?php /* =========================== NEW TICKET =========================== */
elseif ($page === 'new'):
    $pre = $_POST['ticket_type'] ?? ($_GET['type'] ?? 'complaint'); ?>
<section class="panel narrow">
  <div class="panel-head"><h2>رسالة جديدة للإدارة</h2></div>
  <?= flash_box($error, 'err') ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input type="hidden" name="do" value="ticket">
    <div class="choice-row">
      <?php foreach (ticket_types() as $k => $lbl): ?>
      <label class="choice"><input type="radio" name="ticket_type" value="<?= e($k) ?>" <?= $pre === $k ? 'checked' : '' ?> required><span><?= e($lbl) ?></span></label>
      <?php endforeach; ?>
    </div>
    <label class="field"><span>العنوان</span><input name="subject" maxlength="200" required value="<?= e($_POST['subject'] ?? '') ?>"></label>
    <label class="field"><span>التفاصيل</span><textarea name="body" rows="6" maxlength="2000" required><?= e($_POST['body'] ?? '') ?></textarea></label>
    <label class="field"><span>رقم للتواصل (اختياري)</span><input name="phone" inputmode="tel" value="<?= e($_POST['phone'] ?? '') ?>"></label>
    <button class="primary gold-btn">إرسال</button>
  </form>
</section>

<?php /* =========================== TICKETS LIST =========================== */
elseif ($page === 'tickets'):
    $tq = db()->prepare('SELECT * FROM tickets WHERE national_id=? ORDER BY id DESC LIMIT 100');
    $tq->execute([$me['national_id']]);
    $mine = $tq->fetchAll(); ?>
<section class="panel">
  <div class="panel-head"><h2>رسائلي</h2><a class="primary" href="?page=new">رسالة جديدة</a></div>
  <?php if (isset($_GET['sent'])): ?><div class="success">تم الإرسال بنجاح. رقم متابعتك: <b><?= e(ticket_code((int)$_GET['sent'])) ?></b></div><?php endif; ?>
  <?php if ($mine): ?>
  <div class="table-wrap"><table><thead><tr><th>الرقم</th><th>النوع</th><th>العنوان</th><th>التاريخ</th><th>الحالة</th><th></th></tr></thead><tbody>
    <?php foreach ($mine as $t): ?>
    <tr><td><?= e(ticket_code((int)$t['id'])) ?></td><td><?= e(ticket_types()[$t['ticket_type']] ?? '') ?></td><td><?= e($t['subject']) ?></td><td><?= e(substr((string)$t['created_at'], 0, 10)) ?></td><td><?= status_badge($t['status']) ?></td><td><a href="?page=ticket&id=<?= (int)$t['id'] ?>">عرض</a></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="empty">لم ترسل أي رسالة بعد.</p><?php endif; ?>
</section>

<?php /* =========================== TICKET DETAIL =========================== */
elseif ($page === 'ticket'):
    $dq = db()->prepare('SELECT * FROM tickets WHERE id=? AND national_id=?');
    $dq->execute([(int)($_GET['id'] ?? 0), $me['national_id']]);
    $t = $dq->fetch(); ?>
<section class="panel narrow">
<?php if (!$t): ?>
  <p class="empty">الرسالة غير موجودة.</p>
<?php else: ?>
  <div class="panel-head"><h2><?= e($t['subject']) ?></h2><?= status_badge($t['status']) ?></div>
  <p class="muted"><?= e(ticket_code((int)$t['id'])) ?> · <?= e(ticket_types()[$t['ticket_type']] ?? '') ?> · <?= e((string)$t['created_at']) ?></p>
  <div class="bubble mine"><?= nl2br(e($t['body'])) ?></div>
  <?php if ($t['response']): ?>
    <div class="bubble admin"><b>رد الإدارة</b><br><?= nl2br(e($t['response'])) ?><small><?= e((string)$t['responded_at']) ?></small></div>
  <?php else: ?>
    <p class="hint">لم يصل رد بعد. سنتابع طلبك في أقرب وقت.</p>
  <?php endif; ?>
  <a class="secondary" href="?page=tickets">رجوع</a>
<?php endif; ?>
</section>
<?php endif;

portal_foot();
