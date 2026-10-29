<?php
// Home's "Waiting for you" rows (HM_Home; docs/decisions.md HV1): per task its number in the task's colour (a screen reader hears it with its
// unit, or the task's state where it has none), its name, one plain line (the changes to look at list theirs under it) and ONE button; the
// first job's button is the page's main action. The notes ($notes: no job, after the jobs) are quieter and their buttons plain.
$notes = $notes ?? false;
?>
<?php if ($notes): ?>
<ul class="hm-list hm-notes" aria-labelledby="notes-h">
<?php else: ?>
<ol class="hm-list">
<?php endif; ?>
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
    <a class="btn<?php if (!empty($c['hero'])): ?> primary<?php endif; ?>" href="<?= $e($c['href']) ?>"><?= $e($c['button']) ?></a>
<?php endif; ?>
  </li>
<?php endforeach; ?>
<?php if ($notes): ?>
</ul>
<?php else: ?>
</ol>
<?php endif; ?>
