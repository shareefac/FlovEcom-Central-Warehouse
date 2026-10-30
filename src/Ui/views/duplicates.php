<?php
// Matching › Duplicates as a board (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W12.8): "Start with the first group" the
// head's main action; the open groups, biggest sellers first (the order "Next" follows), as ONE group with its count over a card with the
// coloured edge, each row where it stands its solid cell (waiting for a second OK, partly decided, a reason against joining, to decide), a row
// opening the group (its decision page, W12.8); then the groups decided lately, a board of their own with its pager.
$start = $rows !== [] ? '<a class="btn primary" href="/ui/review/duplicates/' . $e($rows[0]['id']) . '">' . $word('DUPS', 'start') . ' &rarr;</a>' : '';
?>
<?= $head(\CW\Ui\Words::MENU['duplicates'], intro: 'duplicates', look: $lookOnly, side: $start) ?>
<?php if ($look !== null): ?>
<p class="note read-only"><?= $e($look) ?></p>
<?php endif; ?>

<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::DUPS['none'], \CW\Ui\Words::DUPS['none_text']) ?>
<?php else: ?>
<div class="grp-block doc-group needs">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-open"><?= $word('DUPS', 'open_groups') ?></button> <span class="grp-count"><?php if ($total === 1): ?><?= $word('DUPS', 'total_one') ?><?php else: ?><?= $say('DUPS', 'total_many', $total) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-open">
  <table class="stack list board doc-list dups">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DUPS', 'keeper') ?></th>
        <th scope="col" class="c-status"><?= $word('DUPS', 'state') ?></th>
        <th scope="col" class="num"><?= $word('DUPS', 'pages') ?></th>
        <th scope="col" class="num"><?= $word('DUPS', 'sold_365') ?></th>
        <th scope="col" class="num"><?= $word('DUPS', 'sold_30') ?></th>
        <th scope="col"><?= $word('DUPS', 'rules') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="/ui/review/duplicates/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::say('DUPS', 'group', $r['id'])) ?></a>
<?php if ($r['sku_code'] !== null): ?> <span class="o-sub"><?= $e($r['sku_code']) ?></span><?php endif; ?>
        </th>
        <td class="c-status doc-st <?= $e($r['tone']) ?>"><span class="chip"><?= $e($r['state']) ?></span></td>
        <td class="num" data-label="<?= $word('DUPS', 'pages') ?>"><?= $n($r['size']) ?></td>
        <td class="num" data-label="<?= $word('DUPS', 'sold_365') ?>"><?= $n($r['units_365d']) ?></td>
        <td class="num" data-label="<?= $word('DUPS', 'sold_30') ?>"><?= $n($r['units_30d']) ?></td>
        <td data-label="<?= $word('DUPS', 'rules') ?>"><?php if ($r['against'] !== []): ?><?= $say('DUPS', 'maybe', implode(', ', $r['against'])) ?><?php elseif ($r['checked']): ?><span class="muted"><?= $word('DUPS', 'no_reason') ?></span><?php else: ?><span class="muted"><?= $word('DUPS', 'not_checked') ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($recent !== []): ?>
<div class="grp-block doc-group done" id="recent">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-recent"><?= $word('DUPS', 'recent') ?></button> <span class="grp-count"><?php if ($recent_total === 1): ?><?= $word('DUPS', 'recent_one') ?><?php else: ?><?= $say('DUPS', 'recent_many', $recent_total) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-recent">
  <table class="stack list board doc-list dups-recent">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('DUPS', 'keeper') ?></th>
        <th scope="col" class="c-status"><?= $word('DUPS', 'state') ?></th>
        <th scope="col"><?= $word('DUPS', 'pages') ?></th>
        <th scope="col"><?= $word('DUPS', 'decided_on') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($recent as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="/ui/review/duplicates/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? \CW\Ui\Words::say('DUPS', 'group', $r['id'])) ?></a></th>
        <td class="c-status doc-st done"><span class="chip"><?= $word('DUPS', 'recent_state') ?></span></td>
        <td data-label="<?= $word('DUPS', 'pages') ?>"><?= $say('DUPS', 'recent_line', $r['size'], $r['items'] === 1 ? \CW\Ui\Words::DUPS['recent_items_one'] : \CW\Ui\Words::say('DUPS', 'recent_items_many', $r['items'])) ?></td>
        <td data-label="<?= $word('DUPS', 'decided_on') ?>"><?php if ($r['at'] !== null): ?><?= $when($r['at']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php if ($recent_prev !== null || $recent_next !== null): ?>
<nav class="pager" aria-label="<?= $say('DUPS', 'recent_page', $recent_page, $recent_pages) ?>">
<?php if ($recent_prev !== null): ?><a href="<?= $e($recent_prev) ?>" rel="prev">&larr; <?= $word('DUPS', 'newer') ?></a><?php endif; ?>
  <span><?= $say('DUPS', 'recent_page', $recent_page, $recent_pages) ?></span>
<?php if ($recent_next !== null): ?><a href="<?= $e($recent_next) ?>" rel="next"><?= $word('DUPS', 'older') ?> &rarr;</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
