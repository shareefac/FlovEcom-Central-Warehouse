<?php
// Lines to place's item box (docs/decisions.md LQ4-LQ6, AV7): the order page's dropdown, drawn in a row of Lines to place and in the open
// record of Approvals › Waiting for you. Without the script it is the text box for the item's CW code (the form `form` sends `item`); app.js
// (input[data-lq-pick]) looks the typed words up at data-options and shows the choices in the box's own list, its parent's ul[data-lq-list].
// $id: what makes the box's ids unique on the page; $value: the code typed; $picked: the chip of a refused answer's item; $addHref,
// $addWords: "+ Add new item" (items.create), else $askWords: whom to ask.
?>
          <input type="text" name="item" form="<?= $e($form) ?>" id="lq-item-<?= $e($id) ?>" value="<?= $e($value) ?>" maxlength="16" required autocomplete="off" inputmode="text"
            aria-label="<?= $word('LQ', 'col_item') ?>" placeholder="<?= $word('LQ', 'item_code') ?>" data-lq-pick data-options="<?= $e($itemsHref) ?>" data-placeholder="<?= $word('LQ', 'item_find') ?>"
            data-picked="<?= $e($picked) ?>" data-add="<?= $e($addWords) ?>" data-add-href="<?= $e($addHref) ?>" data-ask="<?= $e($askWords) ?>"
            data-loading="<?= $word('LQ', 'loading') ?>" data-failed="<?= $word('LQ', 'failed') ?>" data-choose="<?= $word('LQ', 'choose') ?>">
          <ul class="sm-list" id="lq-list-<?= $e($id) ?>" role="listbox" aria-label="<?= $word('LQ', 'choices') ?>" data-lq-list hidden></ul>
          <p class="lq-picked" data-lq-chip hidden><span data-lq-chip-name></span> <button type="button" class="btn ghost sm" data-lq-change><?= $word('LQ', 'change') ?></button></p>
          <p class="po-says" data-lq-note hidden></p>
<?php if ($addHref !== ''): ?>
          <a class="add-item" href="<?= $u($addHref) ?>" data-lq-add-link><?= $e($addWords) ?></a>
<?php endif; ?>
