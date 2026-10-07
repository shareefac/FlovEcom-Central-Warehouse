<p class="crumbs"><a href="/ui/items/cards"><?= $word('CARD_IMPORT', 'back') ?></a></p>
<h1><?= $word('PAGE_TITLE', 'card_import') ?></h1>
<?= $intro('card_import') ?>
<p class="hint"><?= $word('CARD_IMPORT', 'how') ?></p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($report !== null): ?>
<section class="card import-report<?php if ($report['errors_total'] > 0): ?> blocked<?php endif; ?>" aria-labelledby="report-h">
  <h2 id="report-h"><?php if ($report['applied']): ?><?= $say('CARD_IMPORT', 'imported', (string) $report['file']) ?><?php elseif ($report['apply']): ?><?= $say('CARD_IMPORT', 'not_imported', (string) $report['file']) ?><?php else: ?><?= $say('CARD_IMPORT', 'checked', (string) $report['file']) ?><?php endif; ?></h2>
  <ul class="plain">
    <li><?= $say('CARD_IMPORT', 'counts', $report['rows'], $report['changed'], $report['applied'] ? \CW\Ui\Words::CARD_IMPORT['changed'] : \CW\Ui\Words::CARD_IMPORT['would_change'], $report['unchanged'], $report['errors_total']) ?></li>
<?php if ($report['ignored_columns'] !== []): ?>
    <li class="muted"><?= $say('CARD_IMPORT', 'ignored', implode(', ', $report['ignored_columns'])) ?></li>
<?php endif; ?>
    <li class="muted"><?= $say('CARD_IMPORT', 'run', (string) $report['run_id']) ?></li>
  </ul>
<?php if ($report['errors'] !== []): ?>
  <p><strong><?php if ($report['errors_total'] > count($report['errors'])): ?><?= $say('CARD_IMPORT', 'fix_first', count($report['errors']), $report['errors_total']) ?><?php else: ?><?= $word('CARD_IMPORT', 'fix') ?><?php endif; ?></strong></p>
  <ul class="plain import-errors">
<?php foreach ($report['errors'] as $er): ?>
    <li><?php if ($er['code'] !== null && $er['column'] !== null): ?><?= $say('CARD_IMPORT', 'row_column', (string) $er['row'], (string) $er['code'], (string) $er['column'], (string) $er['message']) ?><?php elseif ($er['code'] !== null): ?><?= $say('CARD_IMPORT', 'row_code', (string) $er['row'], (string) $er['code'], (string) $er['message']) ?><?php else: ?><?= $say('CARD_IMPORT', 'row', (string) $er['row'], (string) $er['message']) ?><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($report['changes'] !== []): ?>
  <details>
    <summary><?php if ($report['applied']): ?><?= $say('CARD_IMPORT', 'what_changed', count($report['changes'])) ?><?php else: ?><?= $say('CARD_IMPORT', 'what_would', count($report['changes'])) ?><?php endif; ?></summary>
    <ul class="plain">
<?php foreach ($report['changes'] as $c): ?>
      <li><?= $say('CARD_IMPORT', 'change_line', (string) $c['row'], (string) $c['code'], implode(', ', array_map(static fn (string $f): string => $fieldWords[$f] ?? $f, $c['fields']))) ?><?php if ($c['unconfirmed']): ?> <span class="tag warn"><?= $word('CARD_IMPORT', 'unconfirmed') ?></span><?php endif; ?></li>
<?php endforeach; ?>
    </ul>
  </details>
<?php endif; ?>
</section>
<?php endif; ?>
<form class="record import" method="post" action="/ui/items/cards/import" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
  <fieldset>
    <legend><?= $word('CARD_IMPORT', 'file') ?></legend>
    <label for="f-file"><?= $word('CARD_IMPORT', 'file_label') ?> <span class="hint"><?= $say('CARD_IMPORT', 'file_hint', $maxMiB, $maxChanges) ?></span></label>
    <input id="f-file" type="file" name="file" accept=".csv,text/csv">
    <label class="choice"><input type="radio" name="mode" value="check" checked> <?= $word('CARD_IMPORT', 'check') ?></label>
    <label class="choice"><input type="radio" name="mode" value="apply"> <?= $word('CARD_IMPORT', 'apply') ?></label>
  </fieldset>
  <p class="actions"><button type="submit" class="primary"><?= $word('CARD_IMPORT', 'button') ?></button></p>
</form>
<section aria-labelledby="cols-h">
  <h2 id="cols-h"><?= $word('CARD_IMPORT', 'columns') ?></h2>
  <ul class="plain columns">
    <li><code><?= $e($columns[0]) ?></code>: <?= $word('CARD_IMPORT', 'col_code') ?></li>
    <li><code><?= $e($columns[1]) ?></code>: <?= $word('CARD_IMPORT', 'col_version') ?></li>
    <li><?php foreach (array_slice($columns, 2) as $col): ?><code><?= $e($col) ?></code> (<?= $e($fieldWords[$col] ?? $col) ?>) <?php endforeach; ?></li>
    <li><?= $say('CARD_IMPORT', 'col_kind', implode(', ', $typeCodes)) ?></li>
    <li><?= $word('CARD_IMPORT', 'col_numbers') ?></li>
    <li><?= $word('CARD_IMPORT', 'col_rest') ?></li>
  </ul>
</section>
