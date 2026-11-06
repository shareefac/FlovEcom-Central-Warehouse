<?php
// A new line of the supplier invoice's grid (docs/decisions.md KG2, KG4): the row app.js fills in place when an item is chosen in the empty
// line ($r null: the <template> it is copied from), or a new line typed and drawn again after a refusal ($r: DocGrid::rows(), $bad: its
// refusal). Its boxes have no name: app.js sends the new lines in one field (DocGrid::FIELD), so a new line never exists without the script.
$r = $r ?? null;
$bad = $bad ?? null;
$replace = $replace ?? null; // a saved line whose item was changed in place, drawn again after a refusal: the line it takes the place of (KG22, KG32)
?>
        <tr data-kg-row data-kg-new<?php if ($r !== null): ?> data-si="<?= $e($r['si']) ?>" data-sku="<?= $e($r['sku']) ?>" data-u="<?= $e($r['u']) ?>" data-kg-upp="<?= $e($r['upp']) ?>" data-name="<?= $e($r['name']) ?>" data-code="<?= $e($r['code']) ?>" data-pack="<?= $e($r['pack']) ?>" data-their="<?= $e($r['their']) ?>" data-stock="<?= $e($r['stock']['on_hand'] ?? '') ?>" data-coming="<?= $e($r['stock']['coming'] ?? '') ?>"<?php endif; ?><?php if ($replace !== null): ?> data-kg-replace="<?= $e($replace) ?>"<?php endif; ?><?php if ($bad !== null): ?> class="kg-bad"<?php endif; ?>>
          <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>" data-kg-no><?php if ($r !== null): ?><?= $e($no) ?><?php endif; ?></td>
          <th scope="row" class="c-head c-item"><span class="kg-name" data-kg-name><?= $e($r['name'] ?? '') ?></span> <span class="kg-code" data-kg-code><?= $e($r['code'] ?? '') ?></span><span class="o-sub" data-kg-pack><?= $e($r === null ? '' : $r['pack'] . ($r['their'] !== '' ? ' · ' . $r['their'] : '')) ?></span><?php if ($bad !== null): ?><span class="o-sub kg-error"><?= $e($bad) ?></span><?php endif; ?></th>
<?= $partial('doc_stock', ['st' => $r['stock'] ?? null]) ?>
          <td class="num" data-label="<?= $word('PI_ONE', 'qty') ?>"><input type="text" value="<?= $e($r['q'] ?? '1') ?>" class="short" inputmode="numeric" maxlength="7" autocomplete="off" data-kg-cell="qty" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $word('PI_ONE', 'qty') ?>"<?php if ($bad !== null): ?> aria-invalid="true"<?php endif; ?>></td>
          <td class="num" data-label="<?= $word('PI_ONE', 'price') ?>"><input type="text" value="<?= $e($r['p'] ?? '') ?>" class="short" inputmode="decimal" maxlength="16" autocomplete="off" data-kg-cell="price" aria-keyshortcuts="Control+Delete Meta+Backspace" aria-label="<?= $word('PI_ONE', 'price') ?>"></td>
          <td class="num" data-label="<?= $word('PI_ONE', 'amount') ?>" data-kg-amount><?= $e($r['amount'] ?? '') ?></td>
          <td class="c-status"><button type="button" class="btn ghost po-x" data-kg-x tabindex="-1" aria-label="<?= $word('PI_ONE', 'remove_col') ?>">&times;</button></td>
        </tr>
