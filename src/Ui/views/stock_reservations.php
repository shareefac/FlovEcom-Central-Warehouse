<?php
// Inventory › Reserved stock (docs/decisions.md RS1-RS12), a simple list (the approved redesign of 30 Oct 2026, the Adjustments artboard's
// look; docs/decisions.md RD-A7; W8 before): read only. The title and More (the store, each with its reservations held now, DC33; Clear; the
// download); one search and ONE Show choice (the state); then the reservations grouped by state, newest first, five columns: the order (it
// opens it; when it started under it), the store, its status label, the units, until when it is kept and what became of it. No form here
// posts anything.
?>
<?= $head(\CW\Ui\Words::MENU['reservations'], intro: 'reservations', aside: $explain('reservations', \CW\Ui\Words::MENU['reservations']), side: $partial('rda_bar', $bar)) ?>
<form class="toolbar" method="get" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-search"><span class="ico ico-search" aria-hidden="true"></span><label class="visually-hidden" for="tb-q"><?= $word('RESV', 'find') ?></label><input id="tb-q" type="search" name="q" value="<?= $e($f['q']) ?>" maxlength="100" placeholder="<?= $word('RESV', 'find') ?>" title="<?= $word('RESV', 'find') ?>"></div>
<?php if ($f['state'] !== null): ?>
  <input type="hidden" name="state" value="<?= $e($f['state']) ?>">
<?php endif; ?>
<?php if ($f['channel'] !== null): ?>
  <input type="hidden" name="channel" value="<?= $e($f['channel']) ?>">
<?php endif; ?>
  <?= $partial('rda_show', ['choices' => $show, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</form>

<?php if ($productSearch): ?>
<p class="muted"><?= $word('RESV', 'product_search') ?></p>
<?php endif; ?>
<?php if ($capped !== null): ?>
<p class="note"><?= $say('RESV', 'capped', $capped) ?></p>
<?php endif; ?>

<?php if ($groups === []): ?>
<?php if ($narrowed): ?>
<?= $empty(\CW\Ui\Words::RESV['none_filtered'], \CW\Ui\Words::RESV['none_filtered_text'], $clear, \CW\Ui\Words::RESV['clear']) ?>
<?php elseif ($f['channel'] !== null): ?>
<?= $empty(\CW\Ui\Words::RESV['none_store'], \CW\Ui\Words::RESV['none_text']) ?>
<?php else: ?>
<?= $empty(\CW\Ui\Words::RESV['none'], \CW\Ui\Words::RESV['none_text']) ?>
<?php endif; ?>
<?php else: ?>
<?php foreach ($groups as $g => $grp): ?>
<div class="grp-block doc-group <?= $e($grp['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g) ?>"><?= $e($grp['name']) ?></button> <span class="grp-count"><?= $n(count($grp['rows'])) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g) ?>">
  <table class="stack list board doc-list reservations">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('RESV', 'order') ?></th>
        <th scope="col"><?= $word('RESV', 'store') ?></th>
        <th scope="col" class="c-status"><?= $word('RESV', 'status') ?></th>
        <th scope="col" class="num"><?= $word('RESV', 'units') ?></th>
        <th scope="col"><?= $word('RESV', 'outcome') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($grp['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['ref']) ?></a><span class="o-sub"><?= $when($r['started']) ?></span><?php if ($r['try'] !== null): ?><span class="o-sub"><?= $e($r['try']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('RESV', 'store') ?>"><?= $e($r['store']) ?></td>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $e($r['chipWord']) ?></span></td>
        <td class="num" data-label="<?= $word('RESV', 'units') ?>"><?= $n($r['units']) ?> <span class="o-sub"><?= $say('INV_LIST', 'products_n', $r['products']) ?></span></td>
        <td class="c-wide" data-label="<?= $word('RESV', 'outcome') ?>"><?php if ($r['expires'] !== null): ?><?= $say('INV_LIST', 'kept_until', \CW\Ui\Html::when($r['expires'])) ?><?php if ($r['overdue']): ?><span class="o-sub"><?= $word('RESV', 'overdue') ?></span><?php endif; ?><?php if ($r['outcome'] !== ''): ?><span class="o-sub"><?= $e($r['outcome']) ?></span><?php endif; ?><?php else: ?><?= $e($r['outcome']) ?><?php endif; ?><?php if ($r['outcome_at'] !== null): ?><span class="o-sub"><?= $e($r['outcome_at']) ?></span><?php endif; ?><?php if ($r['unlinked'] !== null): ?><span class="o-sub"><?= $e($r['unlinked']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($older_link !== null || $newest_link !== null): ?>
<p class="pager"><?php if ($newest_link !== null): ?><a href="<?= $e($newest_link) ?>"><?= $word('RESV', 'newest') ?></a><?php endif; ?><?php if ($older_link !== null): ?><a href="<?= $e($older_link) ?>"><?= $word('RESV', 'older') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
