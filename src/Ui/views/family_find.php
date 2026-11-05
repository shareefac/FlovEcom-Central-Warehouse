<?php
// A family's picker: the items the words typed find (FamiliesController::find, docs/decisions.md FP2), which app.js draws in the picker
// (family_bar). Not a page: no statement, no layout. Its root says what it is (data-fp-fragment: app.js takes nothing else). One <li> per
// item, its tick a checkbox with no name (it sends nothing: app.js copies a tick into the picker's form as its hidden field) carrying the
// item's id, CW number and name; then its name and one line of facts (the CW number, the brand, the stock). An item already in a family
// (this one or another, named) has its tick switched off and says where it is: the list would skip it. The line under the list says how
// many were found, that only the first ones are shown, or that nothing was.
?>
<div class="fp-options" data-fp-fragment>
<ul class="plain fp-list" data-fp-choices>
<?php foreach ($rows as $r): ?>
  <li class="fp-row<?php if (!$r['can']): ?> is-off<?php endif; ?>" data-sku="<?= $e($r['id']) ?>">
    <label class="fp-pick"><input type="checkbox" value="<?= $e($r['id']) ?>" data-cw="<?= $e($r['code']) ?>" data-name="<?= $e($r['name']) ?>" data-fp-box<?php if (!$r['can']): ?> disabled<?php endif; ?>><span class="fp-text"><span class="fp-name"><?= $e($r['name']) ?></span> <span class="fp-meta"><?= $e($r['meta']) ?></span><?php if ($r['where'] !== null || $r['discontinued']): ?> <span class="fp-tags"><?php if ($r['where'] !== null): ?><span class="tag<?php if ($r['here']): ?> done<?php else: ?> needs<?php endif; ?>"><?= $e($r['where']) ?></span><?php endif; ?><?php if ($r['discontinued']): ?><span class="tag"><?= $word('FAMILY_PICK', 'not_sold') ?></span><?php endif; ?></span><?php endif; ?></span></label>
  </li>
<?php endforeach; ?>
</ul>
<p class="fp-says" data-fp-says><?= $e($says) ?></p>
</div>
