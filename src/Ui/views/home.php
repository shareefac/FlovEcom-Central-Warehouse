<?php
// Home (the approved Main artboard of 30 Oct 2026; docs/decisions.md RD-C15; before it HM_Home, HV1): today's date and the greeting, with Receive a
// delivery and + New purchase order beside them (each for whoever may do it; the new order the page's main button); four tinted figure tiles
// (figures CW already has: the stock value at cost, the units on the shelf, what waits for this person's OK, the listings to match; fewer
// permissions, the figures Home showed before); "Waiting for you" with how many jobs and Open Approvals, one row per task (its number in its
// colour, its name, one plain line, its action at the right), then the notes; at the right the Stores card (each store's mode in CW and when it
// was last heard from) and the Today card (orders taken today and their units, the deliveries being checked, the oversold records).
$figures = $figures ?? [];
$bar = $partial('home_actions', ['receive' => $receive, 'newOrder' => $newOrder]);
$jobCount = count($tasks);
?>
<?= $head($greeting, eyebrow: \CW\Ui\Html::today(), side: $receive === null && $newOrder === null ? null : $bar) ?>
<?php if ($figures !== []): ?>
<section class="hm-tiles hm-figs" aria-label="<?= $word('HOME', 'figures') ?>">
<?php foreach ($figures as $f): ?>
  <a class="hm-tile hm-<?= $e($f['tone']) ?>" href="<?= $e($f['href']) ?>" data-tile="<?= $e($f['key']) ?>"><span class="hm-tile-ico" aria-hidden="true"><span class="ico ico-<?= $e($f['icon']) ?>"></span></span><span class="hm-fig-t"><?= $e($f['title']) ?></span><span class="hm-fig-n"><?= $e($f['shown']) ?></span><?php if ($f['sub'] !== ''): ?><span class="hm-tile-sub"><?= $e($f['sub']) ?></span><?php endif; ?></a>
<?php endforeach; ?>
</section>
<?php endif; ?>

<div class="hm-grid">
<section class="card hm-jobs" aria-labelledby="needs-h">
  <div class="hm-jobs-head">
    <h2 id="needs-h" class="hm-h"><?= $word('HOME', 'needs') ?><?php if ($jobCount > 0): ?> <span class="hm-count">· <?php if ($jobCount === 1): ?><?= $word('RD_HOME', 'jobs_one') ?><?php else: ?><?= $say('RD_HOME', 'jobs_many', $jobCount) ?><?php endif; ?></span><?php endif; ?></h2>
<?php if ($inbox !== null): ?>
    <a class="hm-open" href="<?= $e($inbox) ?>"><?= $word('RD_HOME', 'open_approvals') ?></a>
<?php endif; ?>
  </div>
<?php if ($tasks === []): ?>
  <div class="empty">
    <p class="empty-title"><?= $word('HOME', 'nothing') ?></p>
    <p><?= $word('HOME', 'nothing_text') ?></p>
  </div>
<?php else: ?>
  <?= $cards($tasks) ?>
<?php endif; ?>
<?php if ($notes !== []): ?>
  <h3 class="hm-sub" id="notes-h"><?= $word('HOME', 'notes') ?></h3>
  <?= $cards($notes, true) ?>
<?php endif; ?>
</section>
<?php if ($stores !== [] || $today !== []): ?>
<div class="hm-side">
<?php if ($stores !== []): ?>
<section class="card hm-stores" aria-labelledby="stores-h">
  <h2 id="stores-h" class="hm-h"><a href="/ui/system/sites"><?= $word('RD_HOME', 'stores') ?></a></h2>
<?php foreach ($stores as $s): ?>
  <div class="hm-store">
    <span class="hm-store-name"><span class="hm-store-t"><?= $e($s['name']) ?></span><span class="hm-store-l"><?= $e($s['line']) ?></span></span>
    <span class="tag <?= $e($s['tone']) ?>"><?= $e($s['mode']) ?></span>
  </div>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($today !== []): ?>
<section class="card hm-today" aria-labelledby="today-h">
  <h2 id="today-h" class="hm-h"><?= $word('RD_HOME', 'today') ?></h2>
<?php foreach ($today as $d): ?>
  <a class="hm-today-row" href="<?= $e($d['href']) ?>"><span><?= $e($d['label']) ?></span><span class="hm-today-n"><?= $e($d['value']) ?></span></a>
<?php endforeach; ?>
</section>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php if ($later !== ''): ?>
<p class="muted later"><?= $word('UI', 'coming_later') ?> <?= $e($later) ?>.</p>
<?php endif; ?>
