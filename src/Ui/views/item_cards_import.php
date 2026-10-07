<p class="crumbs"><a href="/ui/items/cards">Back to the item cards</a></p>
<h1>Import item cards from a CSV file</h1>
<p class="muted">Download the item cards (CSV) from the list, fill in the columns in Excel, save as CSV and import it here. Check it first: nothing is
  saved until you choose "Save the changes", and a file with any problem changes nothing at all.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($report !== null): ?>
<section class="card import-report<?php if ($report['errors_total'] > 0): ?> blocked<?php endif; ?>" aria-labelledby="report-h">
  <h2 id="report-h"><?php if ($report['applied']): ?>Imported<?php elseif ($report['apply']): ?>Nothing was imported<?php else: ?>Checked (nothing was saved)<?php endif; ?>: <?= $e($report['file']) ?></h2>
  <ul class="plain">
    <li><?= $n($report['rows']) ?> rows: <?= $n($report['changed']) ?> <?php if ($report['applied']): ?>cards changed<?php else: ?>cards would change<?php endif; ?>,
      <?= $n($report['unchanged']) ?> unchanged, <?= $n($report['errors_total']) ?> problems.</li>
<?php if ($report['ignored_columns'] !== []): ?>
    <li class="muted">Read past (the export's own columns, never imported): <?= $e(implode(', ', $report['ignored_columns'])) ?>.</li>
<?php endif; ?>
    <li class="muted">Run <?= $e($report['run_id']) ?>.</li>
  </ul>
<?php if ($report['errors'] !== []): ?>
  <p><strong>Correct these and import the whole file again<?php if ($report['errors_total'] > count($report['errors'])): ?> (the first <?= $n(count($report['errors'])) ?> of <?= $n($report['errors_total']) ?> are shown)<?php endif; ?>:</strong></p>
  <ul class="plain import-errors">
<?php foreach ($report['errors'] as $er): ?>
    <li>Row <?= $e($er['row']) ?><?php if ($er['code'] !== null): ?> (<?= $e($er['code']) ?>)<?php endif; ?><?php if ($er['column'] !== null): ?>, <?= $e($er['column']) ?><?php endif; ?>: <?= $e($er['message']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($report['changes'] !== []): ?>
  <details>
    <summary><?php if ($report['applied']): ?>What changed<?php else: ?>What would change<?php endif; ?> (<?= $n(count($report['changes'])) ?>)</summary>
    <ul class="plain">
<?php foreach ($report['changes'] as $c): ?>
      <li>Row <?= $e($c['row']) ?>: <?= $e($c['code']) ?> · <?= $e(implode(', ', $c['fields'])) ?><?php if ($c['unconfirmed']): ?> <span class="tag warn">no longer confirmed</span><?php endif; ?></li>
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
    <legend>The file</legend>
    <label for="f-file">CSV file <span class="muted">(at most <?= $n($maxMiB) ?> MiB, and at most <?= $n($maxChanges) ?> items changed in one import: unchanged
      rows cost nothing, so a whole download is fine; for more changes, import it in parts)</span></label>
    <input id="f-file" type="file" name="file" accept=".csv,text/csv">
    <label class="choice"><input type="radio" name="mode" value="check" checked> Check only: show what would change, save nothing</label>
    <label class="choice"><input type="radio" name="mode" value="apply"> Save the changes</label>
  </fieldset>
  <p class="actions"><button type="submit">Import</button></p>
</form>
<section aria-labelledby="cols-h">
  <h2 id="cols-h">The columns</h2>
  <ul class="plain columns">
    <li><strong>code</strong>: the CW code (CW-000123). Required.</li>
    <li><strong>card_version</strong>: from the export. When it is filled in, a row whose card someone changed since the export is refused, not overwritten.</li>
    <li><strong>product_type</strong>: <?= $e(implode(', ', $types)) ?>.</li>
    <li><strong>liquid_ml</strong> (to 0.1), <strong>nicotine_mg</strong> (mg/ml, or a percentage: 2%), <strong>duty_liable</strong> and <strong>single_use</strong>
      (yes / no), <strong>ecid</strong>, <strong>manufacturer</strong>, <strong>brand</strong>, <strong>flavour</strong>, <strong>discontinued</strong> (yes / no).</li>
    <li>An empty cell changes nothing (clear a value on the item page). A flavour from a file is "proposed" until someone confirms it on the item page. A file
      never confirms a card.</li>
  </ul>
</section>
