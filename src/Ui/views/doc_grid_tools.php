<?php
// Under a document's lines grid (ERPNext's: docs/decisions.md KG23, KG26, KG31): on the left the small grey buttons "Add row", "Add multiple",
// "+ New item" ($newItem: the id of that form, for who may add an item; none: no button) and "Delete" (shown while lines are ticked);
// on the right "Download" ($download: the lines file) and "Upload" ($upload: where the import is). The buttons need the script: drawn
// hidden, app.js shows them (data-kg-js). Then "Add several": one search of the supplier's items, a tick each, "Add N lines".
?>
    <div class="kg-tools">
      <span class="kg-tools-left">
        <button type="button" class="btn sm" data-kg-add-row data-kg-js hidden aria-keyshortcuts="Control+Enter Alt+N Insert"><?= $word('DOC_GRID', 'add_row') ?></button>
        <button type="button" class="btn sm" data-kg-several-open data-kg-js hidden aria-controls="kg-several"><?= $word('DOC_GRID', 'add_several') ?></button>
<?php if ($newItem !== null): ?>
        <button type="button" class="btn sm" data-kg-new-item data-kg-js hidden aria-controls="<?= $e($newItem) ?>" aria-keyshortcuts="Alt+C"><?= $word('DOC_GRID', 'new_item') ?></button>
<?php endif; ?>
        <button type="button" class="btn sm risky" data-kg-delete hidden></button>
      </span>
      <span class="kg-tools-right">
<?php if ($download !== null): ?>
        <a class="btn sm" href="<?= $u($download) ?>" download><?= $word('DOC_GRID', 'download') ?></a>
<?php endif; ?>
<?php if ($upload !== null): ?>
        <a class="btn sm" href="<?= $e($upload) ?>"><?= $word('DOC_GRID', 'upload') ?></a>
<?php endif; ?>
      </span>
    </div>
    <div class="kg-several card" id="kg-several" data-kg-several hidden>
      <div class="kg-several-head"><strong><?= $word('DOC_GRID', 'several_title') ?></strong> <button type="button" class="btn ghost sm" data-kg-several-close><?= $word('DOC_GRID', 'several_close') ?></button></div>
      <label class="visually-hidden" for="kg-several-q"><?= $word('DOC_GRID', 'several_search') ?></label>
      <input id="kg-several-q" type="search" maxlength="100" autocomplete="off" placeholder="<?= $word('DOC_GRID', 'several_search') ?>" data-kg-several-q>
      <ul class="plain kg-several-list" data-kg-several-list></ul>
      <button type="button" class="btn sm" data-kg-several-add data-one="<?= $word('DOC_GRID', 'several_add_one') ?>" data-many="<?= $word('DOC_GRID', 'several_add') ?>" disabled><?= $say('DOC_GRID', 'several_add', 0) ?></button>
    </div>
