<?php
// A line's item's current stock (the owner, 6 Nov 2026: "in purchase order and purchase invoice need to show current item stock";
// docs/decisions.md KG34), ERPNext's "available qty" on an item row: on hand in the sellable warehouses ($st: DocGrid::stock's, null for a
// line without an item), with what is on its way under it ("+12 coming"); 0 as 0, below 0 as it is. Read only; app.js fills a new line's.
$st = $st ?? null;
?>
          <td class="num kg-stock" data-label="<?= $word('DOC_GRID', 'stock') ?>" data-kg-stock><?php if ($st !== null): ?><?= $n($st['on_hand']) ?><?php if ($st['coming'] > 0): ?><span class="o-sub kg-coming"><?= $say('DOC_GRID', 'coming', \CW\Ui\Html::int($st['coming'])) ?></span><?php endif; ?><?php endif; ?></td>
