<h1><?= $word('HOME', 'title') ?></h1>
<p class="lede"><?= $e($hello) ?> <?= $word('UI', 'you_work_as') ?> <strong><?= $jobs($myRoles) ?></strong>.</p>

<section class="home-tasks" aria-labelledby="needs-h">
  <h2 id="needs-h"><?= $word('HOME', 'needs') ?></h2>
<?php if ($tasks === []): ?>
  <div class="empty">
    <p class="empty-title"><?= $word('HOME', 'nothing') ?></p>
    <p><?= $word('HOME', 'nothing_text') ?></p>
  </div>
<?php else: ?>
<?php if ($summary !== null): ?>
  <p class="muted"><?= $e($summary) ?></p>
<?php endif; ?>
  <?= $cards($tasks) ?>
<?php endif; ?>
</section>
<?php if ($notes !== []): ?>

<section class="home-notes" aria-labelledby="notes-h">
  <h2 id="notes-h"><?= $word('HOME', 'notes') ?></h2>
  <?= $cards($notes) ?>
</section>
<?php endif; ?>

<details class="about fold" id="about"<?php if ($aboutOpen): ?> open<?php endif; ?>>
  <summary><?= $word('HOME', 'about') ?></summary>
  <div class="about-body">
<?php foreach ($about as $line): ?>
    <p><?= $e($line) ?></p>
<?php endforeach; ?>
  </div>
</details>
<?php if ($progress !== null): ?>

<?= $partial('matching_progress', $progress) ?>
<?php endif; ?>
<?php if ($uses !== []): ?>

<section class="home-uses" aria-labelledby="uses-h">
  <h2 id="uses-h"><?= $word('HOME', 'uses') ?></h2>
  <ul class="uses">
<?php foreach ($uses as $item): ?>
    <li><a href="<?= $u($item['path'], $item['query']) ?>"><?= $e($item['label']) ?></a><?php if ($item['help'] !== ''): ?> <span class="hint"><?= $e($item['help']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($later !== ''): ?>
<p class="muted later"><?= $word('UI', 'coming_later') ?> <?= $e($later) ?>.</p>
<?php endif; ?>
