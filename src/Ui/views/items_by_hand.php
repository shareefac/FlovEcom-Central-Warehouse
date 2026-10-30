<?php
// Items › Made by hand, a simple list (the approved redesign of 30 Oct 2026, artboard Items: its Show choice Made by hand; docs/decisions.md
// RD-A3; W11.3 before): the title, More (only those waiting for a check, or all of them) and the ONE main button "+ New item"; Items' ONE
// Show choice; then the items grouped by their check (waiting, checked, no check asked), newest first in each. A row opens the item; another
// person's check (a note, the button) is in its row. Whether a second person checks is the "?" beside the heading.
?>
<?= $head(\CW\Ui\Words::INV_LIST['items'], intro: 'items_by_hand', more: $checkOn ? \CW\Ui\Words::ITEM_NEW['check_on'] : \CW\Ui\Words::ITEM_NEW['check_off'], menu: $bar) ?>
<div class="toolbar">
  <?= $showMine($views) ?>
</div>
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
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $n(count($g['rows'])) ?></span></h3>
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
        <td class="c-status doc-st <?= $e($g['tone']) ?>"><span class="chip"><?= $word('BY_HAND_STATE', $r['state']) ?></span></td>
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
