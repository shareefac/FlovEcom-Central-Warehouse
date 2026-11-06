<?php
// Under a document's lines grid (docs/decisions.md KG3, KG19; the order, the supplier invoice, later the sales invoice): the line that says a
// line was removed, with its Undo (app.js shows it; in a polite live region, so a screen reader says it), and one short line of the main
// keys, on a computer only (app.css hides it on a phone and without the script), each key a kbd chip (a Mac's in data-mac: app.js shows
// them there), then "All keys (?)": the panel of every key (keys_panel, in the layout).
?>
    <div class="kg-status" aria-live="polite"><p class="kg-undo" data-kg-undo-line hidden><span data-kg-undo-text></span> <button type="button" class="btn sm ghost" data-kg-undo aria-keyshortcuts="Alt+U Control+Z"><?= $word('DOC_GRID', 'undo') ?></button></p></div>
    <p class="kg-keys" aria-label="<?= $word('DOC_GRID', 'keys') ?>"><?php foreach (['tab', 'updown', 'new', 'del', 'undo', 'copy', 'save'] as $kgKey): ?><span><kbd<?php if (isset(\CW\Ui\Words::DOC_GRID['kbd_' . $kgKey . '_mac'])): ?> data-mac="<?= $word('DOC_GRID', 'kbd_' . $kgKey . '_mac') ?>"<?php endif; ?>><?= $word('DOC_GRID', 'kbd_' . $kgKey) ?></kbd> <?= $word('DOC_GRID', 'k_' . $kgKey) ?></span> <?php endforeach; ?><button type="button" class="btn sm kg-all" data-kb-open aria-controls="kb-panel" aria-keyshortcuts="Control+/"><?= $word('DOC_GRID', 'all_keys') ?></button></p>
