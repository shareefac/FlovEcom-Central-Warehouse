<?php
// Home (the approved HM_Home and HM_Phone of 29 Oct 2026; docs/decisions.md HV1): today's date, the greeting and one Find box; "Waiting for
// you", one row per task with its big number, its name, one plain line and one button (the first one the page's main action); then up to
// four small figures. The menu is the sidebar's; the matching progress is Matching › Mapping's.
$figures = $figures ?? []; $canFind = $canFind ?? false;
?>
<?= $head($greeting, eyebrow: \CW\Ui\Html::today(), side: $canFind ? $partial('home_find') : null) ?>

<section class="card hm-jobs" aria-labelledby="needs-h">
  <h2 id="needs-h" class="hm-h"><?= $word('HOME', 'needs') ?></h2>
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
<?php if ($figures !== []): ?>

<section class="card hm-figs" aria-label="<?= $word('HOME', 'figures') ?>">
<?php foreach ($figures as $f): ?>
  <a class="hm-fig" href="<?= $e($f['href']) ?>" data-tile="<?= $e($f['key']) ?>"><span class="hm-fig-t"><?= $e($f['title']) ?></span><span class="hm-fig-n"><?= $n($f['value']) ?></span></a>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($later !== ''): ?>
<p class="muted later"><?= $word('UI', 'coming_later') ?> <?= $e($later) ?>.</p>
<?php endif; ?>
