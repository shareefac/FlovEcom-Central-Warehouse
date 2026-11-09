<?php
// The quick adjustment screen (docs/decisions.md AG1-AG8; the owner's drawing of 2 Nov 2026, https://claude.ai/artifact/7tqoX6sbiPJH4LKqTvPq6M): the details in
// one row, then the lines in one table that app.js draws from data-rows and the row template. A product is typed and picked in its line (the site's one
// dropdown), Tab moves on and the next line is ready, the arrow keys move between the cells. The form sends every line in one field (`lines`), so it fits
// PHP's max_input_vars however many lines there are; every check stays in the server (StockOpsController::gridCreate, gridSave).
// The site's keys and a save without leaving the page (docs/decisions.md AJ1-AJ8): the form is an editor the one fetch may send (data-kg-editor: adj:new,
// or adj:<id> for the creator's draft, whose address it carries for the address bar), with the words of a save (DOC_GRID's) and the notice of the
// page (the layout's is a toast outside the part put in place); a version conflict is marked (data-kg-conflict) so the page keeps what was typed.
// Under the lines: the Undo line and the hint line (doc_grid_keys).
$rowsJson = (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<noscript><p class="error" role="alert"><?= $word('ADJ_GRID', 'no_script') ?></p></noscript>
<form class="aj-form" method="post" action="<?= $u($action) ?>" data-aj-grid data-options="<?= $e($options) ?>" data-rows="<?= $e($rowsJson) ?>"
  data-loading="<?= $word('ADJ_GRID', 'loading') ?>" data-failed="<?= $word('ADJ_GRID', 'failed') ?>" data-choose="<?= $word('ADJ_GRID', 'choose') ?>"
  data-optional="<?= $word('ADJ_GRID', 'optional') ?>" data-note-hint="<?= $word('ADJ_GRID', 'note_hint') ?>" data-note-needed="<?= $word('ADJ_GRID', 'note_needed') ?>"
  data-unsaved="1" data-unsaved-text="<?= $word('OP_FINAL', 'unsaved') ?>"<?php if ($dirty): ?> data-dirty="1"<?php endif; ?>
  data-kg-editor="<?= $e($editor) ?>"<?php if ($address !== null): ?> data-kg-address="<?= $u($address) ?>"<?php endif; ?><?php if ($conflict): ?> data-kg-conflict="1"<?php endif; ?> data-aj-notice="<?= $e($notice) ?>"
  data-saving="<?= $word('DOC_GRID', 'saving') ?>" data-saved="<?= $word('DOC_GRID', 'saved') ?>" data-save-failed="<?= $word('DOC_GRID', 'save_failed') ?>" data-removed="<?= $word('DOC_GRID', 'removed') ?>">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($formKey !== null): ?>
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
<?php endif; ?>
<?php if ($version !== null): ?>
  <input type="hidden" name="version" value="<?= $e($version) ?>">
<?php endif; ?>
  <input type="hidden" name="lines" value="">
  <section class="card aj-top" aria-label="<?= $word('STOCK_OPS', 'facts') ?>">
    <label><?= $word('STOCK_OPS', 'warehouse') ?>
      <select name="warehouse" required>
<?php foreach ($form['from'] as $w): ?>
        <option value="<?= $e($w['id']) ?>"<?php if ($form['warehouse'] === (string) $w['id']): ?> selected<?php endif; ?>><?= $e($w['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php if ($form['places'] !== []): ?>
    <label><?= $word('STOCK_OPS', 'place') ?>
      <select name="location">
        <option value=""><?= $word('STOCK_OPS', 'no_place') ?></option>
<?php foreach ($form['places'] as $p): ?>
        <option value="<?= $e($p['id']) ?>"<?php if ($form['location'] === (string) $p['id']): ?> selected<?php endif; ?>><?= $e($p['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php endif; ?>
    <label><?= $word('STOCK_OPS', 'date') ?>
      <input type="date" name="doc_date" value="<?= $e($form['doc_date']) ?>">
    </label>
    <label><?= $word('STOCK_OPS', 'ref_short') ?>
      <input type="text" name="external_ref" value="<?= $e($form['external_ref']) ?>" maxlength="100" autocomplete="off" placeholder="<?= $word('ADJ_GRID', 'optional') ?>">
    </label>
    <label><?= $word('ADJ_GRID', 'why_all') ?>
      <select name="why">
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"<?php if ($r['needs_note']): ?> data-note="1"<?php endif; ?><?php if ($top === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php if ($form['hasGiven']): ?>
    <label><?= $word('STOCK_OPS', 'given_to') ?>
      <input type="text" name="given_to" value="<?= $e($form['given_to']) ?>" maxlength="100" autocomplete="off" title="<?= $e((string) $form['givenHint']) ?>">
    </label>
<?php endif; ?>
    <label class="aj-note"><?= $word('STOCK_OPS', 'note_short') ?>
      <input type="text" name="note" value="<?= $e($form['note']) ?>" maxlength="1000" autocomplete="off" placeholder="<?= $word('ADJ_GRID', 'optional') ?>">
    </label>
  </section>

  <section class="card aj-lines" aria-label="<?= $word('STOCK_OPS', 'lines') ?>">
    <table class="aj-table">
      <thead>
        <tr>
          <th scope="col" class="aj-c-no"><?= $word('ADJ_GRID', 'col_no') ?></th>
          <th scope="col"><?= $word('ADJ_GRID', 'col_product') ?></th>
          <th scope="col" class="aj-c-units num"><?= $word('ADJ_GRID', 'col_units') ?></th>
          <th scope="col" class="aj-c-why"><?= $word('ADJ_GRID', 'col_why') ?></th>
          <th scope="col" class="aj-c-fig num"><?= $word('ADJ_GRID', 'col_now') ?></th>
          <th scope="col" class="aj-c-fig num"><?= $word('ADJ_GRID', 'col_after') ?></th>
          <th scope="col" class="aj-c-x"><span class="visually-hidden"><?= $word('ADJ_GRID', 'remove') ?></span></th>
        </tr>
      </thead>
      <tbody data-aj-rows></tbody>
    </table>
    <template data-aj-row>
      <tr class="aj-row">
        <td class="aj-c-no" data-aj-no></td>
        <td class="aj-product">
          <div class="aj-pick">
            <input class="aj-cell" type="text" data-cell="p" maxlength="100" autocomplete="off" placeholder="<?= $word('ADJ_GRID', 'product_hint') ?>" aria-label="<?= $word('ADJ_GRID', 'product_line') ?>">
            <span class="aj-code" data-aj-code></span>
          </div>
          <ul class="sm-list" role="listbox" aria-label="<?= $word('ADJ_GRID', 'list') ?>" hidden></ul>
        </td>
        <td class="aj-c-units num"><input class="aj-cell num" type="text" data-cell="u" maxlength="9" autocomplete="off" aria-label="<?= $word('ADJ_GRID', 'units_line') ?>" aria-keyshortcuts="Control+D"></td>
        <td class="aj-c-why">
          <select class="aj-cell" data-cell="w" tabindex="-1" aria-label="<?= $word('ADJ_GRID', 'why_line') ?>">
            <option value=""><?= $word('ADJ_GRID', 'same') ?></option>
<?php foreach ($reasons as $r): ?>
            <option value="<?= $e($r['code']) ?>"<?php if ($r['needs_note']): ?> data-note="1"<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
          </select>
        </td>
        <td class="aj-c-fig num" data-label="<?= $word('ADJ_GRID', 'col_now') ?>" data-aj-now></td>
        <td class="aj-c-fig num"><input class="aj-cell num aj-after" type="text" data-cell="a" maxlength="11" autocomplete="off" inputmode="numeric" disabled aria-label="<?= $word('ADJ_GRID', 'after_line') ?>" aria-keyshortcuts="Control+D"></td>
        <td class="aj-c-x"><button class="btn ghost sm" type="button" tabindex="-1" data-aj-x aria-label="<?= $word('ADJ_GRID', 'remove') ?>"><span class="ico ico-close" aria-hidden="true"></span></button></td>
      </tr>
    </template>
    <p class="error aj-alarm" role="alert" data-aj-alarm hidden></p>
<?= $partial('doc_grid_keys', []) ?>
    <div class="aj-foot">
      <span class="aj-sum" data-aj-count="products" data-text="<?= $word('ADJ_GRID', 'products') ?>" data-one="<?= $word('ADJ_GRID', 'product_one') ?>"></span>
      <span class="aj-sum aj-up" data-aj-count="found" data-text="<?= $word('ADJ_GRID', 'found') ?>"></span>
      <span class="aj-sum aj-down" data-aj-count="gone" data-text="<?= $word('ADJ_GRID', 'gone') ?>"></span>
<?php if ($ruleWaits): ?>
      <span class="aj-waits muted"><?= $word('STOCK_OPS', 'final_waits') ?></span>
<?php endif; ?>
      <span class="aj-buttons">
        <button class="btn" type="submit" name="action" value="save" aria-keyshortcuts="Control+S"><?= $word('ADJ_GRID', 'save') ?></button>
        <button class="btn primary" type="submit" name="action" value="post"><?= $e($k['final_button']) ?></button>
      </span>
    </div>
  </section>
</form>
