<?php
// A document editor's actions in its head (the owner, 6 Nov 2026: "keep style erpnext"; docs/decisions.md KG26): ERPNext's head bar in place
// of the bar at the bottom (doc_bar): "Leave without saving" (a link that says what it does, so the unsaved guard never asks), the "..."
// menu of the page's other steps ($more: the partial that draws its rows, with $moreData; none: no menu), then the buttons of the editor form ($form: its id; each
// `value` of `action` or its own `name`, `label`, `primary` for the one dark main button, `form` another form, `data` its data- attributes).
// $default: the words of the form's Add, drawn first and unseen: a button outside a form that names it is before the form's own buttons, so
// it would be what Enter presses; Enter stays Add (PX7), as without the head bar.
// $lead: buttons before the "..." (the invoice's "Fill the lines"), each as $buttons' with `jsonly` (shown with the script only: without it
// the form's own button does it). "Keys (?)" (the panel of every key) is the script's too. The order is the demo the owner approved (KG31).
$more = $more ?? '';
$default = $default ?? null;
$lead = $lead ?? [];
$moreData = $moreData ?? [];
?>
<div class="doc-bar kg-head-bar">
<?php if ($default !== null): ?>
  <button type="submit" form="<?= $e($form) ?>" name="action" value="add" class="po-default" tabindex="-1" aria-hidden="true"><?= $e($default) ?></button>
<?php endif; ?>
  <a class="btn ghost" href="<?= $u($back) ?>" data-leave><?= $word('DOC', 'leave') ?></a>
<?php foreach ($lead as $b): ?>
  <button type="submit" form="<?= $e($form) ?>" name="action" value="<?= $e($b['value']) ?>" class="btn"<?php if ($b['jsonly'] ?? false): ?> data-kg-jsonly<?php endif; ?>><?= $e($b['label']) ?></button>
<?php endforeach; ?>
  <button type="button" class="btn" data-kb-open data-kg-jsonly aria-controls="kb-panel" aria-keyshortcuts="Shift+/"><?= $word('DOC_GRID', 'keys_button') ?></button>
<?php if ($more !== ''): ?>
  <details class="more kg-head-more">
    <summary class="btn" aria-label="<?= $word('DOC', 'more') ?>">&hellip;</summary>
    <div class="more-menu"><?= $partial($more, $moreData) ?></div>
  </details>
<?php endif; ?>
<?php foreach ($buttons as $b): ?>
  <button type="submit" form="<?= $e($b['form'] ?? $form) ?>"<?php if (isset($b['value'])): ?> name="<?= $e($b['name'] ?? 'action') ?>" value="<?= $e($b['value']) ?>"<?php endif; ?> class="btn<?php if ($b['primary'] ?? false): ?> primary<?php endif; ?>"<?php foreach (($b['data'] ?? []) as $k => $v): ?> data-<?= $e($k) ?>="<?= $e($v) ?>"<?php endforeach; ?><?php if (($b['value'] ?? '') === 'save'): ?> aria-keyshortcuts="Control+S"<?php endif; ?>><?= $e($b['label']) ?></button>
<?php endforeach; ?>
</div>
