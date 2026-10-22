<?php
/* Printable handover document + signature pad. Expects $h, $lines; $signAction (form target) and $signToken optional. */
?>
<div class="card">
  <div class="row" style="align-items:flex-start">
    <div style="flex:2"><div style="font-size:18px;font-weight:700"><?= e(setting('org_name')) ?></div></div>
    <div style="text-align:right"><div style="font-size:18px;font-weight:700"><?= e(strtoupper(HANDOVER_TYPES[$h['type']])) ?></div><div><?= e($h['number']) ?></div><div class="muted"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></div></div>
  </div>
  <div class="row" style="margin-top:10px">
    <?php if ($h['type'] === 'handover'): ?><div><span class="muted">Handed over to</span><br><b><?= e($h['emp_name']) ?></b><?= $h['emp_position'] ? '<br><span class="muted">' . e($h['emp_position']) . '</span>' : '' ?></div><?php endif; ?>
    <div><span class="muted">Location</span><br><?= e($h['loc_name'] ?: 'Unchanged') ?></div>
    <div><span class="muted">Recorded by</span><br><?= e($h['by_name']) ?></div>
  </div>
  <?php if ($h['notes']): ?><p style="margin-bottom:0"><span class="muted">Notes</span><br><?= e($h['notes']) ?></p><?php endif; ?>
</div>
<div class="table-wrap"><table>
  <tr><th>Asset ID</th><th>Description</th><th>Brand / model</th><th>Serial</th><th>Condition</th><th>Previously</th></tr>
  <?php foreach ($lines as $l): ?><tr><td><b><?= e($l['code']) ?></b></td><td><?= e($l['description']) ?></td><td><?= e($l['brand_model']) ?></td><td><?= e($l['serial_no'] ?: '—') ?></td><td><?= e(ASSET_COND[$l['cond']] ?? $l['cond']) ?></td><td class="muted"><?= e($l['from_text']) ?></td></tr><?php endforeach; ?>
</table></div>
<?php if ($h['type'] === 'handover'): ?>
<div class="card" style="margin-top:12px">
  <p style="margin-top:0">I confirm that I received the items above in the stated condition. I will use them for work purposes, take reasonable care of them, report any loss or damage immediately, and return them when asked or when I leave.</p>
  <?php if ($h['signed_at']): ?>
    <div class="row" style="align-items:flex-end"><div><img src="<?= e($h['signature']) ?>" alt="Signature" style="max-width:320px;max-height:120px;border-bottom:1px solid #999"><br>
      <b><?= e($h['signed_name']) ?></b> · <span class="muted">signed <?= e(date('d M Y H:i', strtotime($h['signed_at']))) ?> (<?= $h['sign_method'] === 'link' ? 'via emailed link' : 'on screen' ?>)</span></div></div>
  <?php elseif (!empty($signAction)): ?>
    <form method="post" action="<?= e($signAction) ?>" class="noprint" onsubmit="return sigSubmit(this)">
      <?= csrf_field() ?><?php if (!empty($signToken)): ?><input type="hidden" name="t" value="<?= e($signToken) ?>"><?php else: ?><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><input type="hidden" name="act" value="sign"><?php endif; ?>
      <label>Name of the person signing</label><input type="text" name="signed_name" value="<?= e($h['emp_name']) ?>" required style="max-width:360px">
      <label>Signature</label>
      <canvas id="sig" width="600" height="180" style="width:100%;max-width:600px;height:180px;border:1px dashed #999;border-radius:6px;background:#fff;touch-action:none"></canvas>
      <input type="hidden" name="signature" id="sigdata">
      <div class="actions"><button type="button" class="btn sec small" onclick="sigClear()">Clear</button><button class="btn">Sign and confirm receipt</button></div>
    </form>
    <script>
    (function(){const c=document.getElementById('sig'),x=c.getContext('2d');let d=false,drawn=false;x.lineWidth=2.2;x.lineCap='round';x.strokeStyle='#1d2733';
      const pos=e=>{const r=c.getBoundingClientRect(),p=e.touches?e.touches[0]:e;return[(p.clientX-r.left)*c.width/r.width,(p.clientY-r.top)*c.height/r.height]};
      const start=e=>{d=true;const[a,b]=pos(e);x.beginPath();x.moveTo(a,b);e.preventDefault()};
      const move=e=>{if(!d)return;const[a,b]=pos(e);x.lineTo(a,b);x.stroke();drawn=true;e.preventDefault()};
      const end=()=>d=false;
      ['mousedown','touchstart'].forEach(t=>c.addEventListener(t,start));['mousemove','touchmove'].forEach(t=>c.addEventListener(t,move));['mouseup','mouseleave','touchend'].forEach(t=>c.addEventListener(t,end));
      window.sigClear=()=>{x.clearRect(0,0,c.width,c.height);drawn=false};
      window.sigSubmit=f=>{if(!drawn){alert('Please sign in the box first.');return false}document.getElementById('sigdata').value=c.toDataURL('image/png');return true};})();
    </script>
  <?php else: ?>
    <div class="row"><div>Signature<br><br>________________________<br><span class="muted"><?= e($h['emp_name']) ?></span></div><div>Date<br><br>________________</div></div>
  <?php endif; ?>
</div>
<?php endif; ?>
