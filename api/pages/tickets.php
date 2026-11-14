<?php
declare(strict_types=1);
/* Included from index.php – staff view of resident complaints & suggestions. */

if (!can('tickets', 'view')) {
    echo '<section class="panel">لا تملك صلاحية عرض الشكاوى والاقتراحات.</section>';
    return;
}

$msg = '';
$err = '';
$canSee = function (array $t): bool {
    if (is_admin()) {
        return true;
    }
    return $t['sector'] !== null && $t['sector'] !== '' && can('tickets', 'view', (string)$t['sector']);
};

/* ---------- Update a ticket ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ticket_update') {
    try {
        check_csrf();
        $tid = (int)($_POST['id'] ?? 0);
        $q = db()->prepare('SELECT * FROM tickets WHERE id=?');
        $q->execute([$tid]);
        $old = $q->fetch();
        if (!$old || !$canSee($old)) {
            throw new RuntimeException('الرسالة غير موجودة.');
        }
        if (!is_admin() && !can('tickets', 'edit', (string)$old['sector'])) {
            throw new RuntimeException('لا تملك صلاحية الرد على رسائل هذا القطاع.');
        }
        $status = pv('status');
        $prio = pv('priority');
        $resp = pv('response');
        if (!isset(ticket_statuses()[$status]) || !in_array($prio, ['normal', 'high', 'urgent'], true)) {
            throw new RuntimeException('قيم غير صالحة.');
        }
        if (mb_strlen($resp) > 3000) {
            throw new RuntimeException('الرد طويل جداً.');
        }
        $changed = $resp !== (string)$old['response'];
        $u = db()->prepare('UPDATE tickets SET status=?,priority=?,internal_note=?,response=?,responded_by=?,responded_at=? WHERE id=?');
        $u->execute([
            $status, $prio, pv('internal_note') ?: null, $resp !== '' ? $resp : null,
            $changed && $resp !== '' ? user()['id'] : $old['responded_by'],
            $changed && $resp !== '' ? date('Y-m-d H:i:s') : $old['responded_at'],
            $tid,
        ]);
        audit('update', 'tickets', $tid, $status);
        $msg = 'تم حفظ التحديث.';
    } catch (Throwable $e) {
        $err = safe_error($e);
    }
}

echo flash_box($msg) . flash_box($err, 'err');

/* =========================== DETAIL =========================== */
if (isset($_GET['id'])):
    $q = db()->prepare('SELECT * FROM tickets WHERE id=?');
    $q->execute([(int)$_GET['id']]);
    $t = $q->fetch();
    if (!$t || !$canSee($t)): ?>
<section class="panel"><p class="empty">الرسالة غير موجودة أو خارج صلاحياتك.</p><a class="secondary" href="?page=tickets">رجوع</a></section>
<?php else:
        $mayEdit = is_admin() || can('tickets', 'edit', (string)$t['sector']);
        $showId = is_admin() || can('profiles', 'view_sensitive'); ?>
<section class="panel">
  <div class="panel-head"><h2><?= e(ticket_code((int)$t['id'])) ?> · <?= e($t['subject']) ?></h2><?= status_badge($t['status']) ?></div>
  <div class="info-grid">
    <div><span>الاسم</span><b><?= e($t['full_name']) ?></b></div>
    <div><span>الهوية</span><b><?= $showId ? e($t['national_id']) : 'مخفي' ?></b></div>
    <div><span>القطاع / الخيمة</span><b><?= e($t['sector']) ?> / <?= e($t['tent']) ?></b></div>
    <div><span>الهاتف</span><b><?= e($t['phone'] ?: '—') ?></b></div>
    <div><span>النوع</span><b><?= e(ticket_types()[$t['ticket_type']] ?? '') ?></b></div>
    <div><span>التاريخ</span><b><?= e((string)$t['created_at']) ?></b></div>
  </div>
  <div class="bubble mine"><?= nl2br(e($t['body'])) ?></div>

  <?php if ($mayEdit): ?>
  <form method="post" class="record-form">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="do" value="ticket_update"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
    <div class="form-grid">
      <label class="field"><span>الحالة</span><select name="status"><?php foreach (ticket_statuses() as $k => $l): ?><option value="<?= e($k) ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>الأولوية</span><select name="priority"><?php foreach (['normal' => 'عادية', 'high' => 'مرتفعة', 'urgent' => 'عاجلة'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $t['priority'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="field"><span>الرد الظاهر للنازح</span><textarea name="response" rows="4"><?= e((string)$t['response']) ?></textarea></label>
    <label class="field"><span>ملاحظة داخلية (لا تظهر للنازح)</span><textarea name="internal_note" rows="2"><?= e((string)$t['internal_note']) ?></textarea></label>
    <button class="primary">حفظ</button> <a class="secondary" href="?page=tickets">رجوع</a>
  </form>
  <?php else: ?>
    <?php if ($t['response']): ?><div class="bubble admin"><b>الرد</b><br><?= nl2br(e((string)$t['response'])) ?></div><?php endif; ?>
    <a class="secondary" href="?page=tickets">رجوع</a>
  <?php endif; ?>
</section>
<?php endif;

/* =========================== LIST =========================== */
else:
    $where = ' WHERE 1=1';
    $args = [];
    if (!is_admin()) {
        $s = allowed_module_sectors('tickets');
        $where .= $s ? ' AND sector IN (' . ph($s) . ')' : ' AND 1=0';
        $args = $s;
    }
    $fs = (string)($_GET['status'] ?? '');
    $ft = (string)($_GET['type'] ?? '');
    $fq = trim((string)($_GET['q'] ?? ''));
    if (isset(ticket_statuses()[$fs])) {
        $where .= ' AND status=?';
        $args[] = $fs;
    }
    if (isset(ticket_types()[$ft])) {
        $where .= ' AND ticket_type=?';
        $args[] = $ft;
    }
    if ($fq !== '') {
        $where .= ' AND (full_name LIKE ? OR national_id LIKE ? OR subject LIKE ? OR tent LIKE ?)';
        array_push($args, "%$fq%", "%$fq%", "%$fq%", "%$fq%");
    }
    $q = db()->prepare("SELECT * FROM tickets$where ORDER BY FIELD(status,'new','in_review','resolved','rejected'),FIELD(priority,'urgent','high','normal'),id DESC LIMIT 300");
    $q->execute($args);
    $rows = $q->fetchAll(); ?>
<section class="panel">
  <div class="panel-head"><h2>الشكاوى والاقتراحات</h2><span class="tag"><?= count($rows) ?> رسالة</span></div>
  <form method="get" class="filter-toolbar">
    <input type="hidden" name="page" value="tickets">
    <input class="filter-search" name="q" value="<?= e($fq) ?>" placeholder="بحث بالاسم أو الهوية أو العنوان أو الخيمة">
    <select name="status"><option value="">كل الحالات</option><?php foreach (ticket_statuses() as $k => $l): ?><option value="<?= e($k) ?>" <?= $fs === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select name="type"><option value="">كل الأنواع</option><?php foreach (ticket_types() as $k => $l): ?><option value="<?= e($k) ?>" <?= $ft === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="primary">تطبيق</button>
  </form>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>الاسم</th><th>القطاع</th><th>النوع</th><th>العنوان</th><th>الأولوية</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>
  <?php foreach ($rows as $t): ?>
    <tr><td><a href="?page=tickets&id=<?= (int)$t['id'] ?>"><?= e(ticket_code((int)$t['id'])) ?></a></td><td><?= e($t['full_name']) ?></td><td><?= e($t['sector']) ?></td><td><?= e(ticket_types()[$t['ticket_type']] ?? '') ?></td><td><?= e($t['subject']) ?></td>
      <td><?= $t['priority'] === 'urgent' ? badge('عاجلة', 'red') : ($t['priority'] === 'high' ? badge('مرتفعة', 'gold') : badge('عادية')) ?></td><td><?= status_badge($t['status']) ?></td><td><?= e(substr((string)$t['created_at'], 0, 10)) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8">لا توجد رسائل مطابقة.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
