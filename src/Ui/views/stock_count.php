<?php
// One count record (docs/decisions.md CT1-CT5, CT14). An open sheet, for the person who opened it: the scan box, its lines 50 a page
// (a quantity is saved when Enter is pressed in its box: the page's form goes, and only the lines changed on it are kept; app.js
// marks a box typed in and not saved yet, and writes the moment it was filled in into at_<line>, counted from data-drawn: review M-7),
// the download and the upload with its preview, "Before you make it final", stop it. A final sheet: what was booked for each
// line and its check; cancel it as a whole. The record that puts a count back, or cancels a sheet: its facts and what it is about.
?>
<p class="crumbs"><a href="<?= $e($listHref) ?>"><?= $word('SEGMENT', 'count_sheets') ?></a></p>
<?= $head((string) $title, intro: $editable ? 'count_sheet_open' : 'count_sheet', look: $lookOnly, chips: $stateChip('DOC_STATUS', $doc->status)) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($notOpener): ?>
<p class="note read-only"><?= $word('COUNTS', 'not_opener') ?></p>
<?php endif; ?>

<section aria-labelledby="facts-h">
  <h2 id="facts-h" class="visually-hidden"><?= $word('COUNTS', 'facts') ?></h2>
  <dl class="wide">
<?php foreach ($facts as $fact): ?>
    <dt><?= $e($fact['label']) ?></dt><dd><?= $e($fact['value']) ?></dd>
<?php endforeach; ?>
<?php if ($aboutHref !== null): ?>
    <dt><?= $word('COUNTS', 'about') ?></dt><dd><a href="<?= $u($aboutHref) ?>"><?= $e($aboutWords) ?></a></dd>
<?php endif; ?>
<?php if ($reversedBy !== null): ?>
    <dt><?= $word('COUNTS', 'cancelled_by') ?></dt><dd><a href="<?= $u($reversedBy['href']) ?>"><?php if ($reversedBy['number'] === null): ?><?= $word('COUNTS', 'cancel_waiting') ?><?php else: ?><?= $e($reversedBy['number']) ?><?php endif; ?></a></dd>
<?php endif; ?>
  </dl>
<?php if ($open_n > 0): ?>
  <p class="note"><?php if ($open_n === 1): ?><?= $word('COUNTS', 'open_one') ?><?php else: ?><?= $say('COUNTS', 'open_many', $open_n) ?><?php endif; ?> <a href="/ui/stock/counts/differences"><?= $word('SEGMENT', 'count_differences') ?></a></p>
<?php endif; ?>
</section>

<?php if ($editable): ?>
<section class="card count-scan" aria-labelledby="scan-h">
  <h2 id="scan-h"><?= $word('COUNTS', 'scan_title') ?></h2>
  <form class="inline" method="post" data-leaves="1" action="<?= $u($base . '/scan') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('COUNTS', 'scan_label') ?> <input type="search" name="code" maxlength="80" autocomplete="off" inputmode="text"<?php if ($scan === null && $report === null && !$hasMarked): ?> autofocus<?php endif; ?> required></label>
    <button type="submit"><?= $word('COUNTS', 'scan_button') ?></button>
  </form>
<?php if ($scanUnits !== null): ?>
  <p class="note"><?= $say('COUNTS', 'scan_case', $scanUnits) ?></p>
<?php endif; ?>
<?php if ($scan !== null && $scan['status'] === 'not_on_sheet'): ?>
  <p class="note" role="status"><?= $say('COUNTS', 'scan_not_on_sheet', (string) $scan['name'], (string) $scan['code']) ?></p>
  <form class="inline" method="post" data-leaves="1" action="<?= $u($base . '/add') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="sku_id" value="<?= $e($scan['sku_id']) ?>">
    <button type="submit" class="primary" autofocus><?= $word('COUNTS', 'scan_add') ?></button>
  </form>
<?php elseif ($scan !== null): ?>
  <p class="error" role="alert" data-code="<?= $e($scan['status']) ?>"><?= $say('COUNTS', 'scan_' . $scan['status'], $scan['typed']) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($kind === 'sheet'): ?>
<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('COUNTS', 'lines_title') ?></h2>
<?php if ($editable): ?>
  <p class="seg-links"><?php foreach ($showLinks as $s): ?><?php if ($s['current']): ?><strong><?= $word('COUNT_SHOW', $s['key']) ?></strong><?php else: ?><a href="<?= $e($s['href']) ?>"><?= $word('COUNT_SHOW', $s['key']) ?></a><?php endif; ?> <?php endforeach; ?><span class="muted"><?= $say('COUNTS', 'lines_counted', $counted_n, $lines_n) ?></span></p>
<?php endif; ?>
<?php if ($lines === []): ?>
  <p class="muted"><?php if ($lines_n === 0): ?><?= $word('COUNTS', 'no_lines') ?><?php else: ?><?= $word('COUNTS', 'no_lines_shown') ?><?php endif; ?></p>
<?php elseif ($editable): ?>
  <form class="record count-lines" method="post" action="<?= $u($base . '/lines') ?>" data-drawn="<?= $e($drawnMs) ?>" data-mark="q_" data-mark-text="<?= $word('COUNTS', 'unsaved') ?>" data-unsaved="1" data-unsaved-text="<?= $word('COUNTS', 'unsaved_leave') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="page" value="<?= $e($page_no) ?>">
    <input type="hidden" name="show" value="<?= $e($show) ?>">
    <div class="table-wrap">
    <table class="stack lines count-sheet">
      <thead>
        <tr>
          <th scope="col"><?= $word('COUNTS', 'item') ?></th>
          <th scope="col"><?= $word('COUNTS', 'mode') ?></th>
          <th scope="col" class="num"><?= $word('COUNTS', 'stock_now') ?></th>
          <th scope="col" class="num"><?= $word('COUNTS', 'counted') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $l): ?>
        <tr id="line-<?= $e($l['line_no']) ?>"<?php if ($l['marked']): ?> class="marked"<?php endif; ?>>
          <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $say('COUNTS', 'line_no', $l['line_no']) ?> · <?= $e($l['code']) ?><?php if ($l['brand'] !== null): ?> · <?= $e($l['brand']) ?><?php endif; ?></span><?php if ($l['barcodes'] !== []): ?><span class="o-sub"><?= $say('COUNTS', 'barcodes', implode(', ', $l['barcodes'])) ?></span><?php endif; ?></th>
          <td data-label="<?= $word('COUNTS', 'mode') ?>"><?php if ($l['mode'] === null): ?><span class="muted"><?= $word('COUNTS', 'no_mode') ?></span><?php else: ?><?= $e($l['mode']) ?><?php if ($l['mode_meaning'] !== null): ?><span class="o-sub"><?= $e($l['mode_meaning']) ?></span><?php endif; ?><?php endif; ?></td>
          <td class="num" data-label="<?= $word('COUNTS', 'stock_now') ?>"><?php if ($l['shows_stock']): ?><?= $n($l['on_hand']) ?><?php if ($l['reserved'] > 0): ?><span class="o-sub"><?= $say('COUNTS', 'reserved', $l['reserved']) ?></span><?php endif; ?><?php else: ?><span class="muted"><?= $word('COUNTS', 'blind') ?></span><?php endif; ?></td>
          <td class="num" data-label="<?= $word('COUNTS', 'counted') ?>"><input type="hidden" name="was_<?= $e($l['line_no']) ?>" value="<?= $e($l['counted_qty']) ?>"><input type="hidden" name="at_<?= $e($l['line_no']) ?>" value="<?= $e($l['typedAt']) ?>"><input type="text" name="q_<?= $e($l['line_no']) ?>" value="<?= $e($l['typed'] ?? $l['counted_qty']) ?>" inputmode="numeric" maxlength="20" size="6" autocomplete="off" aria-label="<?= $say('COUNTS', 'counted_of_item', $l['name']) ?>"<?php if ($l['marked']): ?> autofocus<?php endif; ?>><span class="o-sub save-state" aria-live="polite"><?php if ($l['counted_at'] !== null): ?><?= $say('COUNTS', 'counted_when', \CW\Ui\Html::when($l['counted_at'])) ?><?php endif; ?></span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="actions"><button type="submit"><?= $word('COUNTS', 'save_page') ?></button> <span class="muted"><?= $word('COUNTS', 'save_hint') ?></span></p>
  </form>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack lines count-sheet">
    <thead>
      <tr>
        <th scope="col"><?= $word('COUNTS', 'item') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'held') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'counted') ?></th>
        <th scope="col" class="num"><?= $word('COUNTS', 'difference') ?></th>
        <th scope="col"><?= $word('COUNTS', 'what_happened') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr id="line-<?= $e($l['line_no']) ?>">
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $say('COUNTS', 'line_no', $l['line_no']) ?> · <?= $e($l['code']) ?></span></th>
        <td class="num" data-label="<?= $word('COUNTS', 'held') ?>"><?php if ($l['system_qty'] !== null): ?><?= $n($l['system_qty']) ?><?php elseif ($l['shows_stock']): ?><?= $n($l['expected_qty']) ?><?php else: ?><span class="muted"><?= $word('COUNTS', 'blind') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'counted') ?>"><?php if ($l['counted_qty'] === null): ?><span class="muted"><?= $word('COUNTS', 'not_counted') ?></span><?php else: ?><?= $n($l['counted_qty']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('COUNTS', 'difference') ?>"><?php if ($l['booked_delta'] !== null && $l['booked_delta'] !== 0): ?><?= $say('COUNTS', $l['booked_delta'] > 0 ? 'plus' : 'minus', abs($l['booked_delta'])) ?><?php elseif ($l['booked_delta'] === 0): ?><?= $word('COUNTS', 'no_difference') ?><?php endif; ?></td>
        <td class="c-wide" data-label="<?= $word('COUNTS', 'what_happened') ?>"><?php if ($l['state'] !== null): ?><?= $word('COUNT_LINE', $l['state']) ?><?php if ($l['reversal_href'] !== null): ?> <a href="<?= $u($l['reversal_href']) ?>"><?= $e($l['reversal_number']) ?></a><?php endif; ?><?php endif; ?><?php if ($l['review'] !== 'none'): ?><span class="o-sub"><?= $word('COUNT_CHECK', $l['review']) ?><?php if ($l['reviewed_by_name'] !== null): ?> · <?= $e($l['reviewed_by_name']) ?><?php endif; ?><?php if ($l['review_note'] !== null): ?> · <?= $e($l['review_note']) ?><?php endif; ?></span><?php endif; ?><?php if ($l['counted_by_name'] !== null): ?><span class="o-sub"><?= $say('COUNTS', 'counted_by_when', (string) $l['counted_by_name'], \CW\Ui\Html::when($l['counted_at'])) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php if ($prev_link !== null || $next_link !== null): ?>
  <p class="pager"><span><?= $say('COUNTS', 'page', $page_no, $pages) ?></span><?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>"><?= $word('COUNTS', 'prev') ?></a><?php endif; ?><?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('COUNTS', 'next_page') ?></a><?php endif; ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($editable): ?>
<section class="card final-box" aria-labelledby="final-h">
  <h2 id="final-h"><?= $word('COUNTS', 'final_title') ?></h2>
  <p><?= $word('COUNTS', 'final_text') ?></p>
  <p class="actions"><a class="btn primary" href="<?= $u($base . '/differences') ?>"><?= $word('COUNTS', 'final_link') ?></a></p>
</section>

<details class="fold"<?php if ($report !== null || in_array($errorCode, ['bad_file', 'no_file', 'too_large', 'upload_failed', 'bad_counted_at', 'too_many_rows'], true)): ?> open<?php endif; ?> id="paper">
  <summary><?= $word('COUNTS', 'paper_title') ?></summary>
  <p class="muted"><?= $word('COUNTS', 'paper_text') ?></p>
  <p><a href="<?= $u($base . '/sheet.csv') ?>"><?= $word('COUNTS', 'download') ?></a></p>
<?php if ($report !== null): ?>
  <section class="card import-report" aria-labelledby="report-h">
    <h3 id="report-h"><?= $say('COUNTS', 'report_title', (string) $report['file']) ?></h3>
    <ul class="plain">
      <li><?= $say('COUNTS', 'report_counts', $report['rows'], count($report['fill']), $report['same'], $report['blank'], count($report['not_on_sheet']), count($report['bad'])) ?></li>
    </ul>
<?php if ($report['fill'] !== []): ?>
    <p><strong><?= $word('COUNTS', 'report_fill') ?></strong></p>
    <ul class="plain">
<?php foreach (array_slice($report['fill'], 0, $shown) as $x): ?>
      <li><?php if ($x['from'] === null): ?><?= $say('COUNTS', 'report_fill_new', $x['row'], $x['code'], $x['name'], $x['to']) ?><?php else: ?><?= $say('COUNTS', 'report_fill_change', $x['row'], $x['code'], $x['name'], $x['from'], $x['to']) ?><?php endif; ?></li>
<?php endforeach; ?>
<?php if (count($report['fill']) > $shown): ?>
      <li class="muted"><?= $say('COUNTS', 'report_more', count($report['fill']) - $shown) ?></li>
<?php endif; ?>
    </ul>
<?php endif; ?>
<?php if ($report['not_on_sheet'] !== []): ?>
    <p><strong><?= $word('COUNTS', 'report_not_on_sheet') ?></strong></p>
    <ul class="plain">
<?php foreach (array_slice($report['not_on_sheet'], 0, $shown) as $x): ?>
      <li><?= $say('COUNTS', 'report_row', $x['row'], $x['code']) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($report['bad'] !== []): ?>
    <p><strong><?= $word('COUNTS', 'report_bad') ?></strong></p>
    <ul class="plain import-errors">
<?php foreach (array_slice($report['bad'], 0, $shown) as $x): ?>
      <li><?= $say('COUNTS', 'report_bad_row', $x['row'], $x['code'], \CW\Ui\Words::of('COUNT_BAD', $x['why']), $x['value']) ?></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
    <p class="muted"><?= $word('COUNTS', 'report_next') ?></p>
  </section>
<?php endif; ?>
  <form class="record import" method="post" enctype="multipart/form-data" data-leaves="1" action="<?= $u($base . '/upload') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label for="f-file"><?= $word('COUNTS', 'upload_file') ?> <span class="hint"><?= $say('COUNTS', 'upload_hint', $maxMb) ?></span></label>
    <input id="f-file" type="file" name="file" accept=".csv,text/csv" required>
    <label><?= $word('COUNTS', 'upload_when') ?> <span class="hint"><?= $say('COUNTS', 'upload_when_hint', $days) ?></span><input type="datetime-local" name="counted_at" value="<?= $e($typedWhen) ?>"></label>
    <label class="choice"><input type="radio" name="mode" value="check" checked> <?= $word('COUNTS', 'upload_check') ?></label>
    <label class="choice"><input type="radio" name="mode" value="apply"> <?= $word('COUNTS', 'upload_apply') ?></label>
    <p class="actions"><button type="submit"><?= $word('COUNTS', 'upload_button') ?></button></p>
  </form>
</details>

<details class="fold"<?php if ($typedStop !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('COUNTS', 'stop_title') ?></summary>
  <p class="muted"><?= $word('COUNTS', 'stop_text') ?></p>
  <form class="record" method="post" data-leaves="1" action="<?= $u($base . '/cancel') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($version) ?>">
    <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('COUNTS', 'stop_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?php if ($reverse !== null): ?>
<details class="fold"<?php if ($typedCancel['code'] !== ''): ?> open<?php endif; ?>>
  <summary><?= $word('COUNTS', 'cancel_title') ?></summary>
  <p class="muted"><?= $word('COUNTS', 'cancel_text') ?></p>
  <form class="record" method="post" action="<?= $u($base . '/reverse') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('STOCK_OPS', 'cancel_why') ?>
      <select name="reason_code" required>
        <option value=""><?= $word('STOCK_OPS', 'choose') ?></option>
<?php foreach ($reverse['reasons'] as $r): ?>
        <option value="<?= $e($r['code']) ?>"<?php if ($typedCancel['code'] === $r['code']): ?> selected<?php endif; ?>><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('STOCK_OPS', 'cancel_note') ?> <input type="text" name="note" value="<?= $e($typedCancel['note']) ?>" maxlength="1000"></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('COUNTS', 'cancel_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
