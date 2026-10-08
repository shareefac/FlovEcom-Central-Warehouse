<p class="crumbs"><a href="/ui/reference/settings"><?= $word('MENU', 'settings') ?></a></p>
<div class="head-help">
  <h1><?= $word('MENU', 'integrity') ?></h1>
  <?= $explain('safety_check', \CW\Ui\Words::MENU['integrity']) ?>
</div>
<?= $intro('integrity') ?>
<?php if ($latest === null): ?>
<?= $empty(\CW\Ui\Words::INTEGRITY['none']) ?>
<?php else: ?>
<section class="card latest-check<?php if (!$latest['ok']): ?> waiting<?php endif; ?>" aria-labelledby="last-h">
  <h2 id="last-h"><?= $word('INTEGRITY', 'last') ?> <?php if ($latest['ok']): ?><?= $chip('done', \CW\Ui\Words::INTEGRITY['result_ok']) ?><?php elseif ($latest['problems'] === 1): ?><?= $chip('blocked', \CW\Ui\Words::INTEGRITY['result_one']) ?><?php else: ?><?= $chip('blocked', \CW\Ui\Words::say('INTEGRITY', 'result_bad', $latest['problems'])) ?><?php endif; ?></h2>
  <p><?= $say('INTEGRITY', 'took', \CW\Ui\Html::when($latest['finished_at']), $latest['seconds']) ?></p>
<?php if ($stale): ?>
  <p class="note" role="alert"><?= $say('INTEGRITY', 'stale', \CW\Ui\Html::when($latest['finished_at'])) ?></p>
<?php endif; ?>
<?php if (!$latest['ok']): ?>
  <p class="error" role="alert"><?= $word('INTEGRITY', 'tell') ?></p>
  <details class="fold tech" open>
    <summary><?= $word('INTEGRITY', 'details') ?></summary>
    <ul class="problems">
<?php foreach ($latest['details'] as $line): ?>
      <li><code><?= $e($line) ?></code></li>
<?php endforeach; ?>
    </ul>
<?php if ($latest['cut'] !== null): ?>
    <p class="muted"><?= $say('INTEGRITY', 'more', $latest['cut']) ?></p>
<?php endif; ?>
  </details>
<?php endif; ?>
</section>
<?php if ($earlier !== []): ?>
<h2><?= $word('INTEGRITY', 'earlier') ?></h2>
<div class="table-wrap">
<table class="stack list checks">
  <thead>
    <tr>
      <th scope="col"><?= $word('INTEGRITY', 'when') ?></th>
      <th scope="col"><?= $word('INTEGRITY', 'result') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($earlier as $r): ?>
    <tr class="<?php if ($r['ok']): ?>done<?php else: ?>blocked<?php endif; ?>">
      <th scope="row" class="c-head"><?= $when($r['finished_at']) ?></th>
      <td class="c-status"><?php if ($r['ok']): ?><?= $chip('done', \CW\Ui\Words::INTEGRITY['result_ok']) ?><?php elseif ($r['problems'] === 1): ?><?= $chip('blocked', \CW\Ui\Words::INTEGRITY['result_one']) ?><?php else: ?><?= $chip('blocked', \CW\Ui\Words::say('INTEGRITY', 'result_bad', $r['problems'])) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
