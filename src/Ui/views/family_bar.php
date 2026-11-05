<?php
// A family's actions (docs/decisions.md W11.5; the approved record page, PO_View): in the head, at its right, for whoever keeps the families.
// In use, the main one is "Add items" (its form opens under the bar, open at first while the family is empty or after a refused list); under
// More, rename it and switch it off. Switched off, switching it on again is the main one and renaming is under More. Every form posts where it
// did before. A refused rename opens More and Rename again, a refused list Add items, with what was typed, guarded as typed (W11.13).
// "Add items" is the picker (the owner, 5 Nov 2026; docs/decisions.md FP1-FP11): a search, its items each with a tick, the ticked ones and
// "Put N in this family", ONE form (/pick) that app.js shows and drives (without the script it stays hidden), its ticks ONE field (`items`,
// the ids in the order ticked: a field per tick could pass PHP's max_input_vars, FP11); the pasted list is the fold
// under it, open without the script (app.js folds it when it shows the picker, unless it holds a refused list). A refused picker opens Add
// items with its ticked items drawn again.
$renamed = $typed['name'] !== $f['name'];
$listed = $typed['list'] !== '';
$ticked = $pick === null ? [] : $pick['ticked'];
$pasted = $listed || $errorCode === 'empty_list';
?>
<?php if ($canManage): ?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <details class="more"<?php if ($renamed): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
      <details class="doc-act family-rename"<?php if ($renamed): ?> open<?php endif; ?>>
        <summary class="btn"><?= $word('FAMILY', 'rename') ?></summary>
        <div class="doc-act-panel">
          <form class="record" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/rename') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($renamed): ?> data-dirty="1"<?php endif; ?>>
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
            <label for="fr-name"><?= $word('FAMILY', 'rename_label') ?></label>
            <input id="fr-name" type="text" name="name" maxlength="255" required value="<?= $e($typed['name']) ?>">
            <p class="actions"><button type="submit" class="btn"><?= $word('FAMILY', 'rename_button') ?></button></p>
          </form>
        </div>
      </details>
<?php if ($f['active']): ?>
      <form class="inline family-switch" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/switch') ?>" data-leaves="1">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
        <input type="hidden" name="on" value="0">
        <p class="muted"><?= $word('FAMILY', 'switch_off_does') ?></p>
        <button type="submit" class="btn risky"><?= $word('FAMILY', 'switch_off') ?></button>
      </form>
<?php endif; ?>
    </div>
  </details>
<?php if ($f['active']): ?>
  <details class="doc-act family-add"<?php if ($f['items'] === 0 || $errorCode === 'empty_list' || $errorCode === 'too_many_rows' || $errorCode === 'nothing_ticked' || $listed || $ticked !== []): ?> open<?php endif; ?>>
    <summary class="btn primary"><?= $word('FAMILY', 'bulk') ?></summary>
    <div class="doc-act-panel fp-panel">
<?php if ($pick !== null): ?>
      <form class="fp" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/pick') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($ticked !== []): ?> data-dirty="1"<?php endif; ?> hidden data-fp data-options="<?= $e($pick['find']) ?>" data-max="<?= $e($pick['max']) ?>" data-ticked-text="<?= $word('FAMILY_PICK', 'ticked') ?>" data-go-text="<?= $word('FAMILY_PICK', 'button') ?>" data-too-many="<?= $say('FAMILY_PICK', 'too_many', $pick['max']) ?>" data-short="<?= $word('FAMILY_PICK', 'short') ?>" data-loading="<?= $word('FAMILY_PICK', 'loading') ?>" data-failed="<?= $word('FAMILY_PICK', 'failed') ?>" data-untick-text="<?= $word('FAMILY_PICK', 'untick') ?>" data-untick-label="<?= $word('FAMILY_PICK', 'untick_label') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="form_key" value="<?= $e($pick['key']) ?>">
        <input type="hidden" name="items" value="<?= $e($pick['items']) ?>" data-fp-items>
        <label for="fp-q"><?= $word('FAMILY_PICK', 'find') ?></label>
        <input id="fp-q" type="search" maxlength="100" autocomplete="off" spellcheck="false" enterkeyhint="search" placeholder="<?= $word('FAMILY_PICK', 'find_hint') ?>" aria-describedby="fp-says" data-fp-q>
        <p class="fp-says muted" id="fp-says" aria-live="polite" data-fp-says></p>
        <div class="fp-tools">
          <button type="button" class="btn sm" data-fp-all><?= $word('FAMILY_PICK', 'tick_all') ?></button>
          <button type="button" class="btn sm ghost" data-fp-none><?= $word('FAMILY_PICK', 'untick_all') ?></button>
          <span class="fp-count" aria-live="polite" data-fp-count><?= $say('FAMILY_PICK', 'ticked', count($ticked)) ?></span>
        </div>
        <p class="fp-warn" role="alert" data-fp-warn hidden></p>
        <div class="fp-results" role="group" aria-label="<?= $word('FAMILY_PICK', 'results') ?>" data-fp-results></div>
        <p class="doc-label fp-h"><?= $word('FAMILY_PICK', 'ticked_list') ?></p>
        <ul class="plain fp-ticked" data-fp-ticked>
<?php foreach ($ticked as $t): ?>
          <li data-sku="<?= $e($t['id']) ?>"><span class="fp-t-name"><?= $e($t['name']) ?></span> <span class="fp-t-code"><?= $e($t['code']) ?></span> <button type="button" class="btn sm ghost" aria-label="<?= $say('FAMILY_PICK', 'untick_label', $t['code']) ?>" data-fp-untick><?= $word('FAMILY_PICK', 'untick') ?></button></li>
<?php endforeach; ?>
        </ul>
        <p class="fp-none muted" data-fp-empty<?php if ($ticked !== []): ?> hidden<?php endif; ?>><?= $word('FAMILY_PICK', 'ticked_none') ?></p>
        <p class="actions"><button type="submit" class="btn primary" data-fp-go<?php if ($ticked === []): ?> disabled<?php endif; ?>><?= $say('FAMILY_PICK', 'button', count($ticked)) ?></button></p>
      </form>
<?php endif; ?>
      <details class="fp-paste" open<?php if (!$pasted): ?> data-fold="1"<?php endif; ?>>
        <summary><?= $word('FAMILY_PICK', 'paste') ?></summary>
        <form class="record" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/add') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($listed): ?> data-dirty="1"<?php endif; ?>>
          <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="form_key" value="<?= $e($addKey) ?>">
          <label for="fa-list"><?= $word('FAMILY', 'bulk_label') ?> <span class="hint"><?= $word('FAMILY', 'bulk_hint') ?></span></label>
          <textarea id="fa-list" name="list" rows="6" maxlength="20000" required><?= $e($typed['list']) ?></textarea>
          <p class="actions"><button type="submit" class="btn primary"><?= $word('FAMILY', 'bulk_button') ?></button></p>
        </form>
      </details>
    </div>
  </details>
<?php else: ?>
  <form class="inline family-switch" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/switch') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($f['version']) ?>">
    <input type="hidden" name="on" value="1">
    <button type="submit" class="btn primary"><?= $word('FAMILY', 'switch_on') ?></button>
  </form>
<?php endif; ?>
</div>
<?php endif; ?>
