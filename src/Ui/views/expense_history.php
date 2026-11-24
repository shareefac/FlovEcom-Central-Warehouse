<?php
// The history of an expense, a regular cost or an expense type (CW-1150; docs/decisions.md EX8): newest first, a row per version: when, who,
// what it did and what changed (old → new, in words and pounds), and the reason when there is one. On a phone each row is a card.
$X = \CW\Ui\Words::EXPENSES;
$skip = ['channel_id', 'supplier_id', 'type_id', 'replaces_id', 'deleted_reason', 'deleted_at', 'deleted_actor', 'deleted_by', 'kind', 'sort_order'];
$moneyFields = ['net_pence', 'vat_pence', 'total_pence', 'amount_pence'];
$days = ['invoice_date', 'from_day', 'until_day'];
$show = static function (string $field, mixed $v) use ($moneyFields, $days, $X): string {
    if ($v === null || $v === '') {
        return $field === 'until_day' ? $X['no_end'] : $X['empty'];
    }
    if (in_array($field, $moneyFields, true)) {
        return \CW\Expenses\ExpenseMoney::gbp((int) $v);
    }
    if (in_array($field, $days, true)) {
        return \CW\Ui\Html::day((string) $v);
    }
    return match ($field) {
        'is_active' => (int) $v === 1 ? $X['in_use'] : $X['off_chip'],
        'file_id' => $X['a_file'],
        'every' => $X['every_' . (string) $v] ?? (string) $v,
        // A regular cost's VAT choice (EX38): "Includes 20% VAT" or "No VAT".
        'vat_rate_bp' => (int) $v > 0 ? \CW\Ui\Words::say('REGULAR', 'vat_inc', \CW\Expenses\RegularCostMath::percent((int) $v)) : \CW\Ui\Words::REGULAR['vat_none'],
        default => (string) $v,
    };
};
?>
<div class="table-wrap">
<table class="stack rp-table expense-history">
  <thead>
    <tr>
      <th scope="col"><?= $word('EXPENSES', 'h_when') ?></th>
      <th scope="col"><?= $word('EXPENSES', 'h_who') ?></th>
      <th scope="col"><?= $word('EXPENSES', 'h_what') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($history as $h): ?>
<?php
    $parts = [];
    if ($h['action'] === 'add') {
        foreach (['name', 'supplier'] as $f) {
            if (isset($h['after'][$f]) && is_string($h['after'][$f])) {
                $parts[] = $h['after'][$f];
            }
        }
        if (isset($h['after']['total_pence'])) {
            $parts[] = $show('total_pence', $h['after']['total_pence']);
        } elseif (isset($h['after']['amount_pence'])) {
            $parts[] = $show('amount_pence', $h['after']['amount_pence']);
        }
    } else {
        foreach (\CW\Expenses\ExpenseHistory::diff($h['before'], $h['after']) as [$f, $old, $new]) {
            if (in_array($f, $skip, true) || $f === 'file_id' && $new === null) {
                continue;
            }
            $label = $X['f_' . $f] ?? $f;
            $parts[] = $f === 'file_id' ? $X['new_file'] : $label . ': ' . $show($f, $old) . ' → ' . $show($f, $new);
        }
    }
?>
    <tr>
      <td data-label="<?= $word('EXPENSES', 'h_when') ?>"><?= $when($h['at']) ?></td>
      <td data-label="<?= $word('EXPENSES', 'h_who') ?>"><?= $e($h['who'] ?? $h['actor']) ?></td>
      <td data-label="<?= $word('EXPENSES', 'h_what') ?>"><strong><?= $e($X['h_' . $h['action']] ?? $h['action']) ?></strong><?php if ($parts !== []): ?>: <?= $e(implode('; ', $parts)) ?><?php endif; ?><?php if ($h['reason'] !== null): ?> <span class="muted">(<?= $e($h['reason']) ?>)</span><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
