<?php
// Matching › To review as a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W12.5): ONE Show row (the store: the store
// tabs before); each store a group (its name, what waits, its products) over a card with the coloured edge, its rows the match strengths
// (each its colour's strip), what waits, the share matched and the button to its list; then how much of what each store sells is matched.
?>
<?= $head(\CW\Ui\Words::MENU['review'], intro: 'mapping_overview', look: $lookOnly) ?>
<form class="toolbar" method="get" action="/ui/review" aria-label="<?= $word('UI', 'filter') ?>">
  <div class="tb-state">
    <label for="tb-show"><?= $word('QUEUE', 'show') ?></label>
    <select id="tb-show" name="channel" aria-label="<?= $word('BULK', 'stores') ?>">
<?php foreach ($stores as $it): ?>
      <option value="<?= $e($it['query']['channel'] ?? '') ?>"<?php if ($it['current']): ?> selected<?php endif; ?>><?= $e($it['label']) ?> (<?= $n($it['count']) ?>)</option>
<?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
</form>
<?php if ($groups === []): ?>
<?= $empty(\CW\Ui\Words::BY_STORE['none'], \CW\Ui\Words::BY_STORE['none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<section class="grp-block doc-group t-primary by-store" aria-labelledby="st-<?= $e($g['code']) ?>">
  <h3 class="grp-title" id="st-<?= $e($g['code']) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['code']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $say('BY_STORE', 'waiting', $g['total']['waiting']) ?> · <a href="<?= $e($g['products']) ?>"><?= $word('BY_STORE', 'all_products') ?></a></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['code']) ?>">
  <table class="stack list board row-strips by-store-table">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('BY_STORE', 'strength') ?></th>
        <th scope="col" class="num"><?= $word('BY_STORE', 'col_waiting') ?></th>
        <th scope="col"><?= $word('BY_STORE', 'col_linked') ?></th>
        <th scope="col"><?= $word('BY_STORE', 'col_units') ?></th>
        <th scope="col"><span class="visually-hidden"><?= $e($button) ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr class="<?= $e($r['tone']) ?>">
        <th scope="row" class="c-head c-item"><?= $word('BAND', $r['band']) ?></th>
        <td class="num" data-label="<?= $word('BY_STORE', 'col_waiting') ?>"><?= $n($r['waiting']) ?></td>
        <td class="share" data-label="<?= $word('BY_STORE', 'col_linked') ?>"><?php if ($r['listings'] > 0): ?><span class="share-val"><meter aria-hidden="true" min="0" max="100" value="<?= $e(round(100 * $r['linked'] / $r['listings'])) ?>"></meter> <?= $pct($r['linked'], $r['listings']) ?></span> <span class="muted small"><?= $say('BY_STORE', 'of_products', $r['linked'], $r['listings']) ?></span><?php else: ?><span class="muted"><?= $word('BY_STORE', 'no_products') ?></span><?php endif; ?></td>
        <td class="share" data-label="<?= $word('BY_STORE', 'col_units') ?>"><?php if ($r['u30'] > 0): ?><span class="share-val"><meter aria-hidden="true" min="0" max="100" value="<?= $e(round(100 * $r['l30'] / $r['u30'])) ?>"></meter> <?= $pct($r['l30'], $r['u30']) ?></span><?php else: ?><span class="muted"><?= $word('HOME', 'no_sales') ?></span><?php endif; ?></td>
        <td class="c-next"><?php if ($r['href'] !== null): ?><a class="btn sm" href="<?= $e($r['href']) ?>"><?= $word('BY_STORE', 'review') ?></a><?php else: ?><span class="muted small"><?= $word('BY_STORE', 'nothing') ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="sub">
        <th scope="row" class="c-head"><?= $word('HOME', 'not_checked') ?> <span class="hint"><?= $word('BY_STORE', 'unchecked_help') ?></span></th>
        <td class="num" data-label="<?= $word('BY_STORE', 'col_waiting') ?>"><?= $n($g['unchecked']['waiting']) ?></td>
        <td class="share" data-label="<?= $word('BY_STORE', 'col_linked') ?>"><?php if ($g['unchecked']['listings'] > 0): ?><?= $pct($g['unchecked']['linked'], $g['unchecked']['listings']) ?> <span class="muted small"><?= $say('BY_STORE', 'of_products', $g['unchecked']['linked'], $g['unchecked']['listings']) ?></span><?php else: ?><span class="muted"><?= $word('BY_STORE', 'no_products') ?></span><?php endif; ?></td>
        <td class="share" data-label="<?= $word('BY_STORE', 'col_units') ?>"><?php if ($g['unchecked']['u30'] > 0): ?><?= $pct($g['unchecked']['l30'], $g['unchecked']['u30']) ?><?php else: ?><span class="muted"><?= $word('HOME', 'no_sales') ?></span><?php endif; ?></td>
        <td class="c-next"><span class="muted small"><?= $word('BY_STORE', 'computer_next') ?></span></td>
      </tr>
      <tr class="grp-foot">
        <th scope="row" class="c-head"><?= $word('BY_STORE', 'total') ?></th>
        <td class="num" data-label="<?= $word('BY_STORE', 'col_waiting') ?>"><?= $n($g['total']['waiting']) ?></td>
        <td class="share" data-label="<?= $word('BY_STORE', 'col_linked') ?>"><?php if ($g['total']['listings'] > 0): ?><span class="share-val"><meter aria-hidden="true" min="0" max="100" value="<?= $e(round(100 * $g['total']['linked'] / $g['total']['listings'])) ?>"></meter> <?= $pct($g['total']['linked'], $g['total']['listings']) ?></span> <span class="muted small"><?= $say('BY_STORE', 'of_products', $g['total']['linked'], $g['total']['listings']) ?></span><?php else: ?><span class="muted"><?= $word('BY_STORE', 'no_products') ?></span><?php endif; ?></td>
        <td class="share" data-label="<?= $word('BY_STORE', 'col_units') ?>"><?php if ($g['total']['u30'] > 0): ?><span class="share-val"><meter aria-hidden="true" min="0" max="100" value="<?= $e(round(100 * $g['total']['l30'] / $g['total']['u30'])) ?>"></meter> <?= $pct($g['total']['l30'], $g['total']['u30']) ?></span><?php else: ?><span class="muted"><?= $word('HOME', 'no_sales') ?></span><?php endif; ?></td>
        <td class="c-next"><a class="btn sm" href="<?= $e($g['products']) ?>"><?= $word('BY_STORE', 'all_products') ?></a></td>
      </tr>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($coverage !== []): ?>
<?= $partial('matching_coverage', ['coverage' => $coverage]) ?>
<?php endif; ?>
