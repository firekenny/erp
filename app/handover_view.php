<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
$u = require_any(['storekeeper', 'admin', 'procurement']);
$canEdit = has_role('storekeeper') || has_role('admin');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$h = handover_get($id);
if (!$h) { flash('Not found.', 'err'); redirect('handovers.php'); }

/** Validate and store a signature image */
function save_signature(array $h, string $method): bool {
    $sig = (string)($_POST['signature'] ?? ''); $name = mb_substr(trim((string)($_POST['signed_name'] ?? '')), 0, 160);
    if ($name === '' || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig) || strlen($sig) > 400000) return false;
    q('UPDATE handovers SET signed_at = NOW(), signed_name = ?, signature = ?, sign_method = ? WHERE id = ? AND signed_at IS NULL', [$name, $sig, $method, $h['id']]);
    foreach (handover_lines((int)$h['id']) as $l) asset_event((int)$l['asset_id'], 'signed', "Receipt signed by $name ($method)", 'handover', (int)$h['id']);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && $h['type'] === 'handover' && !$h['signed_at']) {
    $act = $_POST['act'] ?? '';
    if ($act === 'sign') { $ok = save_signature($h, 'screen'); flash($ok ? 'Signed. The handover is complete.' : 'Signature missing: please write the name and sign in the box.', $ok ? 'ok' : 'err'); }
    if ($act === 'send') { $st = handover_send($h); flash($st, str_starts_with($st, 'Email sent') ? 'ok' : 'warn'); }
    redirect("handover_view.php?id=$id");
}
$lines = handover_lines($id);
render_header($h['number']); ?>
<div class="actions noprint" style="margin:-6px 0 12px">
  <?php if ($h['type'] === 'handover'): ?><?= $h['signed_at'] ? '<span class="tag stock">Signed</span>' : '<span class="tag warn">Waiting for signature</span>' ?><?php endif; ?>
  <button class="btn sec" style="margin-left:auto" onclick="window.print()">Print</button><a href="handovers.php">All handovers</a>
</div>
<?php if ($h['type'] === 'handover' && !$h['signed_at'] && $canEdit): ?>
<div class="card noprint">
  <h2>Get the signature</h2>
  <p class="muted" style="margin-top:0">Either the person signs below on this device (tablet or phone works well), or you send them a personal link to sign on their own phone.</p>
  <span class="muted">Personal link · <?= e($h['email_status'] ?? 'not sent') ?></span>
  <div class="copy" id="hlink"><?= e(handover_link($h['token'])) ?></div>
  <div class="actions" style="margin-top:8px">
    <button type="button" class="btn sec small" onclick="navigator.clipboard.writeText(document.getElementById('hlink').innerText);this.innerText='Copied'">Copy link</button>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="send"><button class="btn sec small" <?= $h['emp_email'] ? '' : 'disabled title="No email for this person"' ?>>Email the link<?= $h['emp_email'] ? ' to ' . e($h['emp_email']) : '' ?></button></form>
    <?php if (is_demo()): ?><a class="btn small" href="<?= e(handover_link($h['token'])) ?>" target="_blank">Open as the staff member</a><?php endif; ?>
  </div>
</div>
<?php endif;
$signAction = $canEdit && $h['type'] === 'handover' && !$h['signed_at'] ? 'handover_view.php' : '';
require __DIR__ . '/inc/handover_doc.php';
render_footer();
