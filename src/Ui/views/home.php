<h1><?= $word('HOME', 'title') ?></h1>
<p class="lede"><?= $e($hello) ?> <?= $word('UI', 'you_work_as') ?> <strong><?= $jobs($myRoles) ?></strong>.</p>
<?php $newActions = $newActions ?? []; $tiles = $tiles ?? []; $canFind = $canFind ?? false; ?>
<?php if ($canFind): ?>
<form class="toolbar" method="get" action="/ui/search" role="search">
  <?= $partial('split', ['actions' => $newActions]) ?>
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('UI', 'search_label') ?></label><input id="tb-q" type="search" name="q" maxlength="100" placeholder="<?= $word('UI', 'search') ?>" autocomplete="off"></div>
</form>
<?php elseif ($newActions !== []): ?>
<div class="toolbar"><?= $partial('split', ['actions' => $newActions]) ?></div>
<?php endif; ?>
<?php if ($tiles !== []): ?>

<section class="home-tiles" aria-labelledby="tiles-h">
  <h2 id="tiles-h" class="visually-hidden"><?= $word('HOME', 'tiles') ?></h2>
  <div class="kpis-wrap"><ul class="kpis">
<?php foreach ($tiles as $t): ?>
    <li class="kpi" data-tile="<?= $e($t['key']) ?>"><p class="kpi-label"><?= $e($t['title']) ?></p><p class="kpi-value"><?= $n($t['value']) ?></p><p class="kpi-sub"><?= $e($t['sub']) ?></p><a class="kpi-link" href="<?= $e($t['href']) ?>"><?= $e($t['link']) ?></a></li>
<?php endforeach; ?>
  </ul></div>
</section>
<?php endif; ?>

<section class="home-tasks" aria-labelledby="needs-h">
  <div class="board-head"><h2 id="needs-h" class="board-title"><?= $word('HOME', 'needs') ?></h2><?php if ($summary !== null): ?><p class="board-note"><?= $e($summary) ?></p><?php endif; ?></div>
<?php if ($tasks === []): ?>
  <div class="empty">
    <p class="empty-title"><?= $word('HOME', 'nothing') ?></p>
    <p><?= $word('HOME', 'nothing_text') ?></p>
  </div>
<?php else: ?>
  <?= $cards($tasks, \CW\Ui\Words::HOME['ready'], 't-primary', 'g-ready') ?>
<?php endif; ?>
<?php if ($notes !== []): ?>
  <?= $cards($notes, \CW\Ui\Words::HOME['notes'], 'waiting', 'g-notes') ?>
<?php endif; ?>
</section>

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
