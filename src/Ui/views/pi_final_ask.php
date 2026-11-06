<?php
// "Make it final now?" (docs/decisions.md KG36): asked in the page, never a browser dialog, before the supplier invoice is made final at
// once. Drawn inside the editor's form, shut (`hidden`): the script opens it when the main button is pressed with both posting boxes empty;
// the server draws it open ($open) when a "make final" came without its answer (422 confirm_final_now), so it is asked without the script
// too. Only its "Make final now" sends post_now_confirmed=1 (the "..." of a timed draft sends the same); never a main (magenta) button, the
// head's main button is the page's one. Cancel: the script shuts it and puts the cursor in the posting date; without the script a link to it.
$open = $open ?? false;
?>
  <div class="kg-confirm kg-final-ask" role="group" aria-labelledby="kg-final-ask-q" data-kg-ask-final<?php if (!$open): ?> hidden<?php endif; ?>>
    <p id="kg-final-ask-q" role="alert"><?= $word('DOC_GRID', 'final_ask') ?></p>
    <button type="submit" name="<?= $e(\CW\Ui\Controller\InvoiceOnePage::CONFIRM) ?>" value="1" class="btn risky" data-kg-confirm-now><?= $word('DOC_GRID', 'final_now') ?></button>
    <a class="btn ghost" href="#kg-posting-date" data-kg-ask-cancel><?= $word('DOC_GRID', 'final_cancel') ?></a>
  </div>
