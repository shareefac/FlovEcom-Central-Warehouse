<?php
// Home's "Waiting for you" (HM_Home; docs/decisions.md HV1), since the owner's checklist of 31 Oct 2026 (Main.dc.html; docs/decisions.md RD-H4):
// one row per job, the whole row ONE link to its page: a box (a look, aria-hidden: nobody ticks it, a job leaves the list by itself when done;
// red for an urgent job, with "Urgent" after its name), its name, ONE short line, its count as a pill (a screen reader hears it with its unit;
// none when the job has no number) and its action words at the right. No coloured number square, no job number, no button (the page's main
// button is + New purchase order, RD-C15). The notes ($notes: no job, after the jobs) keep their quieter rows, as before.
$notes = $notes ?? false;
?>
<?php if ($notes): ?>
<ul class="hm-list hm-notes" aria-labelledby="notes-h">
<?php foreach ($list as $c): ?>
  <li class="hm-row <?= $e($c['tone'] ?? 'info') ?>" data-card="<?= $e($c['key']) ?>">
    <span class="hm-n" aria-hidden="true"><?= $e($c['big']) ?></span>
    <div class="hm-text">
      <h3 class="hm-t"><?= $e($c['title']) ?></h3>
      <p class="hm-d"><span class="visually-hidden"><span class="hm-said"><?= $e($c['said']) ?></span>. </span><?= $e($c['text'] ?? '') ?></p>
<?php if (isset($c['lines'])): ?>
      <ul class="hm-lines">
<?php foreach ($c['lines'] as $line): ?>
        <li><?= $e($line) ?></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </div>
<?php if (isset($c['href'], $c['button'])): ?>
    <a class="hm-go" href="<?= $e($c['href']) ?>"><?= $e($c['button']) ?><span aria-hidden="true"> ›</span></a>
<?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<ul class="hm-check" aria-label="<?= $word('RD_HOME', 'jobs_list') ?>">
<?php foreach ($list as $c): ?>
  <li data-card="<?= $e($c['key']) ?>">
    <a class="hm-job" href="<?= $e($c['href']) ?>">
      <span class="hm-box<?php if ($c['urgent'] ?? false): ?> urgent<?php endif; ?>" aria-hidden="true"></span>
      <span class="hm-job-text"><span class="hm-job-t"><?= $e($c['title']) ?><?php if ($c['urgent'] ?? false): ?> <span class="hm-urgent"><?= $word('RD_HOME', 'urgent') ?></span><?php endif; ?></span> <span class="hm-job-l"><?= $e($c['line'] ?? '') ?></span></span>
<?php if (isset($c['count'])): ?>
      <span class="hm-job-n" aria-hidden="true"><?= $e($c['big']) ?></span> <span class="visually-hidden"><?= $e($c['said']) ?></span>
<?php endif; ?>
      <span class="hm-job-go"><?= $e($c['button'] ?? '') ?><span aria-hidden="true"> ›</span></span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
