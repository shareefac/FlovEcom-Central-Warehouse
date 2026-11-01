<?php
// "Confirm all ready": the done page of a press (ConfirmAllDone.dc.html; docs/decisions.md CA8), read from the press's audit row. The green
// status line ("N confirmed. K left for you."), the "Left for you" card (an amber dot, its count; each row the item, why in amber, and Open › to
// it in Approvals), where the press is saved, and Back to Approvals. A press that stopped before its end (CA15) says so under the status line:
// what it confirmed is counted, the rest is left; a night's run still going (NC16) says that instead. Plain links: no script needed.
?>
<?= $head(\CW\Ui\Words::CONFIRM_ALL['done_title'], eyebrow: \CW\Ui\Words::CONFIRM_ALL['eyebrow'], side: '') ?>
<?= $showNav() ?>
<div class="ca-page">
<p class="ca-status" role="status"><span class="ico ico-check" aria-hidden="true"></span><?= $say('CONFIRM_ALL', 'done_status', $confirmed, count($left)) ?></p>
<?php if ($stopped || $running): ?>
<p class="ca-stopped" role="note"><?= $word('CONFIRM_ALL', $running ? 'running' : 'stopped') ?></p>
<?php endif; ?>
<?php if ($left !== []): ?>
<section class="card ca-left" aria-labelledby="ca-left-h">
  <h2 id="ca-left-h"><span class="ca-dot needs" aria-hidden="true"></span><?= $word('CONFIRM_ALL', 'left_title') ?> <span class="ca-count"><?= $n(count($left)) ?></span></h2>
  <ul class="ca-rows">
<?php foreach ($left as $r): ?>
    <li><span class="ca-what"><span class="ca-t"><?= $e($r['title']) ?></span><span class="ca-why"><?= $e($r['line']) ?></span></span><a class="ca-open" href="<?= $e($r['href']) ?>" aria-label="<?= $say('CONFIRM_ALL', 'open_named', $r['title']) ?>"><?= $word('CONFIRM_ALL', 'open') ?></a></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<p class="ca-saved"><?= $e($saved) ?></p>
<p class="ca-actions"><a class="btn" href="<?= $u($back) ?>"><?= $word('CONFIRM_ALL', 'back_list') ?></a></p>
</div>
