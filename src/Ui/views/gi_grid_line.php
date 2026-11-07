<?php
// A line added on a delivery's page and not saved yet (docs/decisions.md GW4, GW6; the order's po_grid_line as the model): drawn again after a
// refusal (DocGrid::rows: its item's name and CW code, its pack and their code, its packs as typed), and, with $r null, the row the script
// fills in place (<template data-kg-row>). Its item is in its attributes (data-si, else data-sku and data-u: what the one field sends); its
// Got box has no name (the script sends the line in `new_lines`); it has no check yet ("Not saved") and an x that takes it out. $bad: the
// refusal's words when it is about this line.
$r = $r ?? null;
$bad = $bad ?? null;
$tone = \CW\Ui\Words::tone('GI_CHECK', 'todo');
?>
        <tr class="gi-row kg-new <?= $e($tone) ?><?php if ($bad !== null): ?> kg-bad<?php endif; ?>" data-kg-row data-kg-new data-si="<?= $e($r['si'] ?? '') ?>" data-sku="<?= $e($r['sku'] ?? '') ?>" data-u="<?= $e($r['u'] ?? '') ?>" data-name="<?= $e($r['name'] ?? '') ?>" data-code="<?= $e($r['code'] ?? '') ?>" data-pack="<?= $e($r['pack'] ?? '') ?>" data-their="<?= $e($r['their'] ?? '') ?>" data-kg-upp="<?= $e($r['upp'] ?? 1) ?>" data-gi-p="<?= $e($r['p'] ?? '') ?>">
          <td class="c-no" data-label="<?= $word('DOC_GRID', 'no') ?>" data-kg-no><?php if ($no > 0): ?><?= $e($no) ?><?php endif; ?></td>
          <th scope="row" class="c-head c-item"><span class="o-name kg-name" data-kg-name><?= $e($r['name'] ?? '') ?></span> <span class="kg-code" data-kg-code><?= $e($r['code'] ?? '') ?></span> <span class="o-sub" data-kg-pack><?= $e($r['pack'] ?? '') ?><?php if (($r['their'] ?? '') !== ''): ?> · <?= $say('RECEIPT', 'their_code', (string) $r['their']) ?><?php endif; ?></span><?php if ($bad !== null): ?> <span class="o-sub kg-error"><?= $e($bad) ?></span><?php endif; ?></th>
          <td class="num" data-label="<?= $word('GI', 'col_expected') ?>">&mdash;</td>
          <td class="num gi-items" data-label="<?= $word('GI', 'col_got') ?>"><input type="number" min="0" max="1000000" inputmode="numeric" value="<?= $e($r['q'] ?? '1') ?>" class="short" aria-label="<?= $word('GI', 'col_packs') ?>" data-kg-cell="qty" aria-keyshortcuts="ArrowUp ArrowDown Control+Delete"></td>
          <td class="c-status doc-st <?= $e($tone) ?> gi-check" data-label="<?= $word('GI', 'col_check') ?>"><span class="chip"><?= $word('DOC_GRID', 'not_saved') ?></span></td>
          <td class="c-next gi-acts"><button type="button" class="btn ghost sm po-x" data-kg-x aria-label="<?= $word('GI', 'remove_new') ?>">&times;</button></td>
        </tr>
