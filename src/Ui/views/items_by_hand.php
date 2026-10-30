<?php
// Products › Add Item › Items made by hand, a board of its own (the approved design of 29 Oct 2026, GI_List; docs/decisions.md W11.3): the
// head's "+ Add Item"; one Show row (all of them, or only those waiting for a check), Clear at the right; then the items grouped by their
// check (waiting, checked, no check asked), newest first in each, each group a coloured heading with its count over a card with its colour on
// the left edge, the status cell filled with it. A row opens the item; another person's check (a note, the button) is in its row, above the
// row's link; "+ Add an item" at the end of the first group opens the form. Whether a second person checks is the head's muted line.
?>
<?= $head(\CW\Ui\Words::ITEM_NEW['list_title'], intro: 'items_by_hand', more: $checkOn ? \CW\Ui\Words::ITEM_NEW['check_on'] : \CW\Ui\Words::ITEM_NEW['check_off']) ?>
<form class="toolbar" method="get" action="/ui/items/new" aria-label="<?= $word('UI', 'filter') ?>" data-leaves="1">
  <div class="tb-state">
    <label for="tb-show"><?= $word('ITEM_NEW', 'show') ?></label>
    <select id="tb-show" name="show">
      <option value="all"<?php if ($show === 'all'): ?> selected<?php endif; ?>><?= $word('ITEM_NEW', 'show_all') ?></option>
      <option value="waiting"<?php if ($show === 'waiting'): ?> selected<?php endif; ?>><?= $word('ITEM_NEW', 'show_waiting') ?></option>
    </select>
    <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <div class="tb-end">
<?php if ($show === 'waiting'): ?>
    <a class="btn ghost sm" href="<?= $u('/ui/items/new', ['show' => 'all']) ?>"><?= $word('ITEM_NEW', 'clear') ?></a>
<?php endif; ?>
  </div>
</form>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($rows === [] && $show === 'waiting'): ?>
<?= $empty(\CW\Ui\Words::ITEM_NEW['waiting_none'], \CW\Ui\Words::ITEM_NEW['waiting_none_text'], \CW\Ui\Html::url('/ui/items/new', ['show' => 'all']), \CW\Ui\Words::ITEM_NEW['show_all']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::ITEM_NEW['made_none'], \CW\Ui\Words::ITEM_NEW['made_none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $i => $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?php if (count($g['rows']) === 1): ?><?= $word('ITEM_NEW', 'group_one') ?><?php else: ?><?= $say('ITEM_NEW', 'group_many', count($g['rows'])) ?><?php endif; ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list by-hand">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('ITEM_NEW', 'item') ?></th>
        <th scope="col" class="c-status"><?= $word('ITEM_NEW', 'check') ?></th>
        <th scope="col"><?= $word('ITEM_NEW', 'who') ?></th>
        <th scope="col"><?= $word('ITEM_NEW', 'when') ?></th>
        <th scope="col" class="c-next"><span class="visually-hidden"><?= $word('ITEM_NEW', 'check_button') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/items/' . $r['id']) ?>"><?= $e($r['code']) ?> <?= $e($r['name']) ?></a><?php if ($r['brand'] !== null): ?><span class="o-sub"><?= $e($r['brand']) ?></span><?php endif; ?></th>
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><?= $word('BY_HAND_STATE', $r['state']) ?></td>
        <td data-label="<?= $word('ITEM_NEW', 'who') ?>"><?= $e($r['who']) ?></td>
        <td data-label="<?= $word('ITEM_NEW', 'when') ?>"><?= $when($r['at']) ?></td>
        <td class="c-next">
<?php if ($r['checked'] !== null): ?>
          <span class="o-sub"><?= $e($r['checked']) ?><?php if ($r['note'] !== null): ?> · <?= $e($r['note']) ?><?php endif; ?></span>
<?php elseif ($r['canCheck']): ?>
          <form class="inline check-item" method="post" action="<?= $u('/ui/items/new/' . $r['id'] . '/check') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <label class="visually-hidden" for="cn-<?= $e($r['id']) ?>"><?= $word('ITEM_NEW', 'check_note') ?></label>
            <input id="cn-<?= $e($r['id']) ?>" type="text" name="note" maxlength="500" placeholder="<?= $word('ITEM_NEW', 'check_note') ?>">
            <button type="submit" class="btn sm"><?= $word('ITEM_NEW', 'check_button') ?></button>
          </form>
<?php elseif ($r['own']): ?>
          <span class="o-sub"><?= $word('ITEM_NEW', 'check_own') ?></span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
<?php if ($i === 0): ?>
      <tr class="grp-add"><td colspan="5" data-label=""><a class="add-item" href="/ui/items/new#new"><?= $word('ITEM_NEW', 'add_row') ?></a></td></tr>
<?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('ITEM_NEW', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('ITEM_NEW', 'prev') ?></a><?php endif; ?>
  <span><?= $say('ITEM_NEW', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('ITEM_NEW', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
