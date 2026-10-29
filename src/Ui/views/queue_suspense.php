<?php
// Approvals › From the stores › Lines to place (goods-in suspense; docs/decisions.md PF6, LQ1-LQ8): a board, monday.com style. Each relayed
// ERPNext line the warehouse records could not match to an item is a row of its group (the shared board of the order and invoice lists:
// .doc-group, .doc-board, td.doc-st; goods in, blue; corrections, amber): what came in, its
// status (the cell filled solid), its units (a correction: the figure it sets), and for the people who may: the item in CW (the order page's
// dropdown, app.js; without the script the CW code typed), the item's units in one unit of the line, a note, and Place (a correction: "Send to
// corrections"). The row's answer is one form: its fields sit in their cells and name it (form="lq-pick-<id>"). Closing a line with a note is in
// the row's menu. A refused answer comes back with what was typed in its row ($r['typed'], OV7), the item it names drawn as picked again.
?>
<?= $head(\CW\Ui\Words::SEGMENT['queue_suspense'], intro: 'queue_suspense', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('queue_filter', ['action' => $action, 'states' => $states, 'state' => $state, 'count' => count($rows), 'more' => $more, 'pageSize' => $pageSize]) ?>
<?php if ($rows === [] && $state === 'open'): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none_open'], \CW\Ui\Words::SUSPENSE['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none'], \CW\Ui\Words::QUEUES['none_text'], $action, \CW\Ui\Words::QUEUES['show_open']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<section class="grp-block doc-group lq-group <?= $e($g['tone']) ?>" aria-labelledby="lq-<?= $e($g['key']) ?>">
  <h2 class="grp-title" id="lq-<?= $e($g['key']) ?>"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="lq-board-<?= $e($g['key']) ?>"><span class="lq-gname"><?= $e($g['name']) ?></span></button> <span class="grp-count"><?= $e($g['count']) ?></span></h2>
  <div class="table-wrap doc-board" id="lq-board-<?= $e($g['key']) ?>">
  <table class="stack board lq-lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('LQ', 'col_what') ?></th>
        <th scope="col"><?= $word('LQ', 'col_status') ?></th>
        <th scope="col" class="num"><?= $e($g['units_col']) ?></th>
        <th scope="col"><?= $word('LQ', 'col_item') ?></th>
        <th scope="col" class="num"><?= $word('LQ', 'col_each') ?></th>
        <th scope="col"><?= $word('LQ', 'col_note') ?></th>
        <th scope="col"><span class="visually-hidden"><?= $word('LQ', 'col_action') ?></span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr id="suspense-<?= $e($r['id']) ?>">
        <th scope="row" class="c-head"><?= $e($r['what']) ?><span class="o-sub"><?= $e($r['from']) ?></span><?php if ($r['names'] !== null): ?><span class="o-sub"><?= $e($r['names']) ?></span><?php endif; ?><span class="o-sub"><?= $e($r['where']) ?></span><span class="o-sub"><?= $e($r['why']) ?></span></th>
        <td class="c-status doc-st <?= $e($r['st_tone']) ?>" data-label="<?= $word('LQ', 'col_status') ?>"><?= $e($r['st_word']) ?></td>
        <td class="num" data-label="<?= $e($g['units_col']) ?>"><?= $n($r['units']) ?></td>
<?php if ($r['answer']): ?>
        <td class="lq-item" data-label="<?= $word('LQ', 'col_item') ?>">
          <input type="text" name="item" form="lq-pick-<?= $e($r['id']) ?>" id="lq-item-<?= $e($r['id']) ?>" value="<?= $e($r['typed_item']) ?>" maxlength="16" required autocomplete="off" inputmode="text"
            aria-label="<?= $word('LQ', 'col_item') ?>" placeholder="<?= $word('LQ', 'item_code') ?>" data-lq-pick data-options="<?= $e($itemsHref) ?>" data-placeholder="<?= $word('LQ', 'item_find') ?>"
            data-picked="<?= $e($r['picked_label']) ?>" data-add="<?= $e($addWords) ?>" data-add-href="<?= $e($r['add_href']) ?>" data-ask="<?= $e($askWords) ?>"
            data-loading="<?= $word('LQ', 'loading') ?>" data-failed="<?= $word('LQ', 'failed') ?>" data-choose="<?= $word('LQ', 'choose') ?>">
          <ul class="sm-list" id="lq-list-<?= $e($r['id']) ?>" role="listbox" aria-label="<?= $word('LQ', 'choices') ?>" data-lq-list hidden></ul>
          <p class="lq-picked" data-lq-chip hidden><span data-lq-chip-name></span> <button type="button" class="btn ghost sm" data-lq-change><?= $word('LQ', 'change') ?></button></p>
          <p class="po-says" data-lq-note hidden></p>
<?php if ($r['add_href'] !== ''): ?>
          <a class="add-item" href="<?= $u($r['add_href']) ?>" data-lq-add-link><?= $e($addWords) ?></a>
<?php endif; ?>
        </td>
        <td class="num" data-label="<?= $word('LQ', 'col_each') ?>"><input type="number" name="units" form="lq-pick-<?= $e($r['id']) ?>" class="short" min="1" max="10000" step="1" value="<?= $e($r['typed_units']) ?>" required aria-label="<?= $word('LQ', 'col_each') ?>"></td>
        <td data-label="<?= $word('LQ', 'col_note') ?>"><input type="text" name="note" form="lq-pick-<?= $e($r['id']) ?>" value="<?= $e($r['typed_note']) ?>" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required placeholder="<?= $word('LQ', 'note_hint') ?>" aria-label="<?= $word('LQ', 'col_note') ?>"></td>
        <td class="c-next" data-label="">
          <form class="lq-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/pick') ?>" id="lq-pick-<?= $e($r['id']) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <button type="submit" class="btn<?php if (!$r['correction']): ?> primary<?php endif; ?>"><?php if ($r['correction']): ?><?= $word('LQ', 'to_corrections') ?><?php else: ?><?= $word('LQ', 'place') ?><?php endif; ?></button>
          </form>
          <details class="lq-menu"<?php if ($r['typed_close'] !== null): ?> open<?php endif; ?>>
            <summary><span class="visually-hidden"><?= $word('LQ', 'menu') ?></span></summary>
            <form class="record" method="post" action="<?= $u($action . '/' . $r['id'] . '/dismiss') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <label><?= $word('LQ', 'close_note') ?> <input type="text" name="note" value="<?= $e($r['typed_close'] ?? '') ?>" minlength="<?= $e($noteMin) ?>" maxlength="<?= $e($noteMax) ?>" required></label>
              <p class="actions"><button type="submit" class="btn risky"><?= $word('LQ', 'close') ?></button></p>
            </form>
          </details>
        </td>
<?php else: ?>
        <td data-label="<?= $word('LQ', 'col_item') ?>"><?php if ($r['picked'] !== null): ?><?= $e($r['picked']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('LQ', 'col_each') ?>"></td>
        <td data-label="<?= $word('LQ', 'col_note') ?>"><?php if ($r['closed'] !== null): ?><?= $e($r['note_shown']) ?><?php endif; ?></td>
        <td data-label=""><?php if ($r['closed'] !== null): ?><span class="o-sub"><?= $e($r['closed']) ?></span><?php endif; ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>
<?php endif; ?>
