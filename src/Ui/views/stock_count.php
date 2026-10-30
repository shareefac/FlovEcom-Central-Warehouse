<?php
// One count record (docs/decisions.md CT1-CT5, CT14; the approved design CT_Sheet and CT_Phone of 29 Oct 2026: CV5-CV8). An open sheet,
// for the person who opened it: the status block in its heading (Counting), where and who on one line (a blind count says so), and
// "N of M counted" with its bar at the head's right; the big scan box; its lines 50 a page as a board (the shared .doc-board): the item,
// what CW holds (never on a blind count, CV8), the big number box and "Saved hh:mm" (the line's own count time) or "Not yet". A
// quantity is saved when Enter is pressed in its box (the form's first button is the hidden Save; only the lines changed on the page are
// kept; app.js marks a box typed in and not saved yet, and writes the moment it was filled in into at_<line>, counted from data-drawn:
// review M-7). The line to count now is marked; on a phone it is the one big card with "Save and next item", the others small cards. The
// bar at the bottom (.doc-bar; its buttons name the quantity form): More (count on paper: the download, the upload and its preview; stop
// the sheet), Save, and "Save and review" (saves, then opens the review: OV6). Which lines it shows (every line, not counted yet, counted) is the
// sheet's Show choice over them (a GET form that asks first while a count is typed and not saved: data-leaves; CT_Sheet draws no tab row,
// docs/decisions.md DC35). A final sheet: what was booked for each line and its check;
// cancel it as a whole. The record that puts a count back, or cancels a sheet: its facts and what it is about.
?>
<div class="doc-head">
<?= $head((string) $title, intro: $editable ? 'count_sheet_open' : 'count_sheet', look: $lookOnly, back: [(string) $listHref, \CW\Ui\Words::SEGMENT['count_sheets']],
    chips: $kind === 'sheet' && $doc->status === 'draft' ? $chip('info', \CW\Ui\Words::COUNTS['counting']) : $stateChip('DOC_STATUS', $doc->status),
    meta: $meta === '' ? '' : $e($meta) . ($blind ? ' <span class="tag info">' . $word('COUNTS', 'blind_tag') . '</span>' : ''),
    side: $editable ? '<div class="count-progress"><p>' . $say('COUNTS', 'lines_counted', $counted_n, $lines_n) . '</p><progress max="' . $e(max(1, $lines_n)) . '" value="'
        . $e($counted_n) . '"></progress></div>' : null) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($notOpener): ?>
<p class="note read-only"><?= $word('COUNTS', 'not_opener') ?></p>
<?php endif; ?>

<?php if (!$editable): ?>
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
<?php endif; ?>

<?php if ($editable): ?>
<section class="card count-scan" aria-labelledby="scan-h">
  <h2 id="scan-h" class="visually-hidden"><?= $word('COUNTS', 'scan_title') ?></h2>
  <form method="post" data-leaves="1" action="<?= $u($base . '/scan') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label for="count-code"><?= $word('COUNTS', 'scan_label') ?></label>
    <div class="count-scan-row"><input id="count-code" type="search" name="code" maxlength="80" autocomplete="off" inputmode="text" placeholder="<?= $word('COUNTS', 'scan_hint') ?>"<?php if ($scan === null && $report === null && !$hasMarked): ?> autofocus<?php endif; ?> required><button class="btn" type="submit"><?= $word('COUNTS', 'scan_button') ?></button></div>
  </form>
<?php if ($scanUnits !== null): ?>
  <p class="note"><?= $say('COUNTS', 'scan_case', $scanUnits) ?></p>
<?php endif; ?>
<?php if ($scan !== null && $scan['status'] === 'not_on_sheet'): ?>
  <p class="note" role="status"><?= $say('COUNTS', 'scan_not_on_sheet', (string) $scan['name'], (string) $scan['code']) ?></p>
  <form class="inline" method="post" data-leaves="1" action="<?= $u($base . '/add') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="sku_id" value="<?= $e($scan['sku_id']) ?>">
    <button type="submit" class="btn soft" autofocus><?= $word('COUNTS', 'scan_add') ?></button>
  </form>
<?php elseif ($scan !== null): ?>
  <p class="error" role="alert" data-code="<?= $e($scan['status']) ?>"><?= $say('COUNTS', 'scan_' . $scan['status'], $scan['typed']) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($kind === 'sheet'): ?>
<section aria-labelledby="lines-h">
  <h2 id="lines-h"<?php if ($editable): ?> class="visually-hidden"<?php endif; ?>><?= $word('COUNTS', 'lines_title') ?></h2>
<?php if ($editable): ?>
  <form class="toolbar" method="get" action="<?= $u($base) ?>" data-leaves="1" aria-label="<?= $word('UI', 'filter') ?>">
    <div class="tb-state">
      <label for="tb-show"><?= $word('DOC_LIST', 'show') ?></label>
      <select id="tb-show" name="show">
<?php foreach ($shows as $s): ?>
        <option value="<?= $e($s['key']) ?>"<?php if ($s['current']): ?> selected<?php endif; ?>><?= $word('COUNT_SHOW', $s['key']) ?></option>
<?php endforeach; ?>
      </select>
      <button type="submit" class="btn sm"><?= $word('DOC_LIST', 'show') ?></button>
    </div>
  </form>
<?php endif; ?>
<?php if ($lines === []): ?>
  <p class="muted"><?php if ($lines_n === 0): ?><?= $word('COUNTS', 'no_lines') ?><?php else: ?><?= $word('COUNTS', 'no_lines_shown') ?><?php endif; ?></p>
<?php elseif ($editable): ?>
  <form class="count-lines" id="count-lines" method="post" action="<?= $u($base . '/lines') ?>" data-drawn="<?= $e($drawnMs) ?>" data-mark="q_" data-mark-text="<?= $word('COUNTS', 'unsaved') ?>" data-unsaved="1" data-unsaved-text="<?= $word('COUNTS', 'unsaved_leave') ?>">
    <button type="submit" class="po-default" tabindex="-1" aria-hidden="true"><?= $word('COUNTS', 'save') ?></button>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="page" value="<?= $e($page_no) ?>">
    <input type="hidden" name="show" value="<?= $e($show) ?>">
    <div class="table-wrap doc-board info">
    <table class="stack list board count-sheet">
      <thead>
        <tr>
          <th scope="col" class="c-item"><?= $word('COUNTS', 'item') ?></th>
<?php if (!$blind): ?>
          <th scope="col" class="num"><?= $word('COUNTS', 'stock_now') ?></th>
<?php endif; ?>
          <th scope="col" class="num"><?= $word('COUNTS', 'counted') ?></th>
          <th scope="col"><?= $word('COUNTS', 'saved') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lines as $l): ?>
        <tr id="line-<?= $e($l['line_no']) ?>"<?php if ($l['row_class'] !== ''): ?> class="<?= $e($l['row_class']) ?>"<?php endif; ?>>
          <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['name']) ?></a><span class="o-sub"><?= $say('COUNTS', 'line_no', $l['line_no']) ?> · <?= $e($l['code']) ?><?php if ($l['brand'] !== null): ?> · <?= $e($l['brand']) ?><?php endif; ?><?php if ($l['mode'] !== null): ?> · <?= $e($l['mode']) ?><?php endif; ?></span><?php if ($l['barcodes'] !== []): ?><span class="o-sub"><?= $say('COUNTS', 'barcodes', implode(', ', $l['barcodes'])) ?></span><?php endif; ?></th>
<?php if (!$blind): ?>
          <td class="num" data-label="<?= $word('COUNTS', 'stock_now') ?>"><?= $n($l['on_hand']) ?><?php if ($l['reserved'] > 0): ?><span class="o-sub"><?= $say('COUNTS', 'reserved', $l['reserved']) ?></span><?php endif; ?></td>
<?php endif; ?>
          <td class="num count-q" data-label="<?php if ($l['now']): ?><?= $word('COUNTS', 'how_many') ?><?php else: ?><?= $word('COUNTS', 'counted') ?><?php endif; ?>"><input type="hidden" name="was_<?= $e($l['line_no']) ?>" value="<?= $e($l['counted_qty']) ?>"><input type="hidden" name="at_<?= $e($l['line_no']) ?>" value="<?= $e($l['typedAt']) ?>"><input type="text" class="count-box" name="q_<?= $e($l['line_no']) ?>" value="<?= $e($l['typed'] ?? $l['counted_qty']) ?>" inputmode="numeric" maxlength="20" autocomplete="off" aria-label="<?= $say('COUNTS', 'counted_of_item', $l['name']) ?>"<?php if ($l['marked']): ?> autofocus<?php endif; ?>><?php if ($l['now']): ?><button type="submit" name="next" value="<?= $e($l['line_no']) ?>" class="btn soft lg save-next"><?= $word('COUNTS', 'save_next') ?></button><?php endif; ?></td>
          <td class="count-saved" data-label=""><span class="save-state<?php if ($l['saved'] !== null): ?> saved<?php endif; ?>" aria-live="polite"><?php if ($l['saved'] !== null): ?><?= $say('COUNTS', 'saved_at', $l['saved']) ?><?php else: ?><?= $word('COUNTS', 'not_yet') ?><?php endif; ?></span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </form>
  <p class="muted count-booked"><?= $word('COUNTS', 'not_booked') ?></p>
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
<?php if ($report !== null): ?>
<section class="card import-report" aria-labelledby="report-h">
  <h2 id="report-h"><?= $say('COUNTS', 'report_title', (string) $report['file']) ?></h2>
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
<div class="doc-bar count-bar">
  <details class="more"<?php if ($paperOpen || $typedStop !== ''): ?> open<?php endif; ?>>
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
      <details class="doc-act" id="paper"<?php if ($paperOpen): ?> open<?php endif; ?>>
        <summary class="btn"><?= $word('COUNTS', 'paper_title') ?></summary>
        <div class="doc-act-panel">
          <p><a href="<?= $u($base . '/sheet.csv') ?>" download><?= $word('COUNTS', 'download') ?></a></p>
          <form class="record import" method="post" enctype="multipart/form-data" data-leaves="1" action="<?= $u($base . '/upload') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <label for="f-file"><?= $word('COUNTS', 'upload_file') ?> <span class="hint"><?= $say('COUNTS', 'upload_hint', $maxMb) ?></span></label>
            <input id="f-file" type="file" name="file" accept=".csv,text/csv" required>
            <label><?= $word('COUNTS', 'upload_when') ?> <span class="hint"><?= $say('COUNTS', 'upload_when_hint', $days) ?></span><input type="datetime-local" name="counted_at" value="<?= $e($typedWhen) ?>"></label>
            <label class="choice"><input type="radio" name="mode" value="check" checked> <?= $word('COUNTS', 'upload_check') ?></label>
            <label class="choice"><input type="radio" name="mode" value="apply"> <?= $word('COUNTS', 'upload_apply') ?></label>
            <p class="actions"><button class="btn" type="submit"><?= $word('COUNTS', 'upload_button') ?></button></p>
          </form>
        </div>
      </details>
      <details class="doc-act" id="stop"<?php if ($typedStop !== ''): ?> open<?php endif; ?>>
        <summary class="btn risky"><?= $word('COUNTS', 'stop_title') ?></summary>
        <div class="doc-act-panel">
          <form class="record" method="post" data-leaves="1" action="<?= $u($base . '/cancel') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="version" value="<?= $e($version) ?>">
            <label><?= $word('STOCK_OPS', 'stop_why') ?> <input type="text" name="reason" value="<?= $e($typedStop) ?>" minlength="3" maxlength="500" required></label>
            <p class="actions"><button type="submit" class="btn risky"><?= $word('COUNTS', 'stop_button') ?></button></p>
          </form>
        </div>
      </details>
    </div>
  </details>
<?php if ($lines !== []): ?>
  <button type="submit" form="count-lines" class="btn"><?= $word('COUNTS', 'save') ?></button>
  <button type="submit" form="count-lines" name="then" value="review" class="btn primary"><?= $word('COUNTS', 'save_review') ?></button>
<?php else: ?>
  <a class="btn primary" href="<?= $u($base . '/differences') ?>"><?= $word('COUNTS', 'to_review') ?></a>
<?php endif; ?>
</div>
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
    <p class="actions"><button type="submit" class="btn risky"><?= $word('COUNTS', 'cancel_button') ?></button></p>
  </form>
</details>
<?php endif; ?>
