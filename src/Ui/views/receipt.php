<p class="crumbs"><a href="/ui/receiving"><?= $word('MENU', 'receiving') ?></a><?php if ($canBench): ?> <a href="<?= $u('/ui/receiving/' . $doc->id . '/bench') ?>"><?= $word('RECEIPT', 'to_bench') ?></a><?php endif; ?></p>
<h1><?= $e($title) ?></h1>
<p class="eyebrow"><?= $stateChip('RECEIPT_STATE', $doc->status) ?><?php if ($benchState !== null): ?> <?= $stateChip('BENCH_STATE', $benchState) ?><?php endif; ?><?php if ($doc->reviewState !== null && $doc->reviewState !== 'not_required'): ?> <?= $stateChip('REVIEW_STATE', $doc->reviewState) ?><?php endif; ?></p>
<?php if ($doc->status === 'draft'): ?>
<?= $intro('receipt_other', $canPost ? null : \CW\Ui\Words::whoCan('doc.GRN.post')) ?>
<?php else: ?>
<?= $intro('receipt') ?>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($problems !== []): ?>
<ul class="error problems">
<?php foreach ($problems as $p): ?>
  <li><?= $e($p) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($reversal !== null): ?>
<p class="note"><?= $say('RECEIPT', 'reversed_by', (string) $reversal->number) ?> <a href="<?= $u('/ui/documents/' . $reversal->id) ?>"><?= $word('RECEIPT', 'open_reversal') ?></a></p>
<?php endif; ?>
<?php if ($doc->reviewState === 'rejected'): ?>
<p class="error"><?= $word('RECEIPT', 'rejected') ?></p>
<?php endif; ?>
<?php if ($doc->status === 'draft'): ?>
<p class="note read-only"><?php if ($canPost): ?><?= $say('RECEIPT', 'only_keyer', (string) $people['created']) ?><?php else: ?><?= $say('RECEIPT', 'only_keyer_look', (string) $people['created']) ?><?php endif; ?></p>
<?php endif; ?>

<?php foreach ($decide as $d): ?>
<section class="card decide-box<?php if ($d['refusal'] === null): ?> waiting<?php endif; ?>" aria-labelledby="decide-<?= $e($d['task']['id']) ?>-h">
  <div class="head-help">
    <h2 id="decide-<?= $e($d['task']['id']) ?>-h"><?= $e($d['title']) ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $e($d['text']) ?><?php if ($d['task']['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::RECEIPT['late']) ?><?php endif; ?></p>
<?php if ($d['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($d['refusal']) ?></p>
<?php else: ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done">
        <dt><?= $word('RECEIPT', 'ok') ?></dt>
        <dd><?= $word('RECEIPT', 'ok_does') ?></dd>
      </div>
<?php if ($d['poClosed'] === null): ?>
      <div class="answer blocked">
        <dt><?php if ($d['reversal']): ?><?= $word('RECEIPT', 'not_ok_reversal') ?><?php else: ?><?= $word('RECEIPT', 'not_ok') ?><?php endif; ?></dt>
        <dd><?php if ($d['reversal']): ?><?= $word('RECEIPT', 'not_ok_reversal_does') ?><?php else: ?><?= $word('RECEIPT', 'not_ok_does') ?><?php endif; ?></dd>
      </div>
<?php endif; ?>
    </dl>
  </div>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECEIPT', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary"><?= $word('RECEIPT', 'ok') ?></button>
  </form>
<?php if ($d['poClosed'] !== null): ?>
  <p class="note"><?= $say('RECEIPT', 'po_closed', (string) $d['poClosed']) ?></p>
<?php else: ?>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECEIPT', 'why_not_ok') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit" class="danger"><?php if ($d['reversal']): ?><?= $word('RECEIPT', 'not_ok_reversal') ?><?php else: ?><?= $word('RECEIPT', 'not_ok') ?><?php endif; ?></button>
  </form>
<?php endif; ?>
<?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($plan !== null): ?>
<section class="card box checklist" aria-labelledby="ready-h">
  <div class="head-help">
    <h2 id="ready-h"><?= $word('RECEIPT', 'ready_title') ?></h2>
    <?= $explain('posting', \CW\Ui\Words::RECEIVING['posting_label']) ?>
  </div>
<?php if ($plan['problems'] === []): ?>
  <p class="notice"><?= $word('RECEIPT', 'ready') ?></p>
<?php else: ?>
  <ul class="problems">
<?php foreach ($plan['problems'] as $p): ?>
    <li><?= $e($p['message']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($plan['warnings'] !== []): ?>
  <ul class="warnings">
<?php foreach ($plan['warnings'] as $w): ?>
    <li><?= $e($w) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($canSetInvoice): ?>
  <form class="record" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/invoice') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <fieldset>
      <legend><?= $word('RECEIPT', 'set_invoice') ?></legend>
      <label><?= $word('RECEIPT', 'invoice_number') ?> <input type="text" name="invoice_number" maxlength="64" autocomplete="off" value="<?= $e($doc->externalRef) ?>"></label>
      <label><?= $word('RECEIPT', 'invoice_date') ?> <input type="date" name="invoice_date" value="<?= $e($gr['invoice_date'] ?? '') ?>"></label>
      <label><?= $word('RECEIPT', 'delivery_note_no') ?> <input type="text" name="delivery_note" maxlength="64" value="<?= $e($gr['delivery_note'] ?? '') ?>"></label>
      <p class="actions"><button type="submit"><?= $word('RECEIPT', 'set_invoice_button') ?></button></p>
    </fieldset>
  </form>
  <p class="hint"><?= $word('RECEIPT', 'set_invoice_hint') ?></p>
<?php endif; ?>
<?php if ($canPostDraft): ?>
  <form class="quick" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/post') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit" class="primary btn big"><span class="btn-title"><?= $word('RECEIPT', 'book_in') ?></span> <span class="sub"><?= $word('RECEIPT', 'book_in_does') ?></span></button>
  </form>
  <p class="hint"><?= $word('RECEIPT', 'book_in_rule') ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="card about-receipt" aria-labelledby="about-h">
  <h2 id="about-h"><?= $word('RECEIPT', 'about') ?></h2>
  <dl>
    <dt><?= $word('RECEIPT', 'invoice') ?></dt><dd><?php if ($doc->externalRef === null): ?><?= $word('RECEIPT', 'no_invoice') ?><?php elseif (($gr['invoice_date'] ?? null) !== null): ?><?= $say('RECEIPT', 'invoice_dated', (string) $doc->externalRef, \CW\Ui\Html::day((string) $gr['invoice_date'])) ?><?php else: ?><?= $e($doc->externalRef) ?><?php endif; ?></dd>
<?php if (($gr['delivery_note'] ?? null) !== null): ?>
    <dt><?= $word('RECEIPT', 'delivery_note') ?></dt><dd><?= $e($gr['delivery_note']) ?></dd>
<?php endif; ?>
    <dt><?= $word('RECEIPT', 'po') ?></dt><dd><?php if ($po !== null): ?><a href="<?= $u('/ui/purchasing/orders/' . $po['id']) ?>"><?= $e($po['number']) ?></a> <?= $stateChip('PO_STATE', (string) $po['state']) ?><?php else: ?><?= $word('RECEIPT', 'no_po') ?><?php endif; ?></dd>
    <dt><?= $word('RECEIPT', 'arrived') ?></dt><dd><?= $when($gr['received_at'] ?? null) ?><?php if ((int) ($gr['paper_sheet'] ?? 0) === 1): ?> <?= $chip('info', \CW\Ui\Words::RECEIPT['paper']) ?><?php endif; ?><?php if (($gr['backdate_reason'] ?? null) !== null): ?> <span class="o-sub"><?= $e($gr['backdate_reason']) ?></span><?php endif; ?></dd>
    <dt><?= $word('RECEIPT', 'bench') ?></dt><dd><?php if (($gr['checked_at'] ?? null) === null): ?><?= $word('RECEIPT', 'bench_not_yet') ?><?php else: ?><?php if ((int) $gr['paperwork_ok'] === 1): ?><?= $word('RECEIPT', 'bench_ok') ?><?php else: ?><strong><?= $word('RECEIPT', 'bench_not_ok') ?></strong><?php endif; ?> <span class="o-sub"><?= $say('RECEIPT', 'bench_when', \CW\Ui\Html::when((string) $gr['checked_at']), (string) ($people['checked'] ?? '')) ?></span><?php if (($gr['bench_note'] ?? null) !== null): ?> <span class="o-sub"><?= $e($gr['bench_note']) ?></span><?php endif; ?><?php endif; ?></dd>
    <dt><?= $word('RECEIPT', 'keyed_by') ?></dt><dd><?= $e($people['created']) ?></dd>
<?php if ($doc->postedAt !== null): ?>
    <dt><?= $word('RECEIPT', 'booked') ?></dt><dd><?= $say('RECEIPT', 'booked_when', \CW\Ui\Html::when($doc->postedAt), (string) $people['posted']) ?></dd>
    <dt><?= $word('RECEIPT', 'check') ?></dt><dd><?= $stateChip('REVIEW_STATE', (string) $doc->reviewState) ?></dd>
<?php endif; ?>
<?php if ($doc->status === 'cancelled'): ?>
    <dt><?= $word('RECEIPT', 'cancelled') ?></dt><dd><?= $say('RECEIPT', 'cancelled_when', \CW\Ui\Html::when($doc->cancelledAt), (string) $people['cancelled'], (string) $doc->cancelReason) ?></dd>
<?php endif; ?>
<?php if ($doc->note !== null): ?>
    <dt><?= $word('RECEIPT', 'note') ?></dt><dd><?= $e($doc->note) ?></dd>
<?php endif; ?>
  </dl>
</section>

<section class="where-units" aria-labelledby="where-h">
  <div class="head-help">
    <h2 id="where-h"><?php if ($doc->status === 'draft'): ?><?= $word('RECEIPT', 'where_draft') ?><?php else: ?><?= $word('RECEIPT', 'where') ?><?php endif; ?></h2>
    <?= $explain('where_units_go', \CW\Ui\Words::RECEIPT['where_label']) ?>
  </div>
  <dl class="stats">
    <div class="stat"><dt><?= $word('RECEIPT', 'size') ?></dt><dd><?= $e($totals['size']) ?></dd></div>
    <div class="stat"><dt><?= $word('RECEIPT', 'into_stock') ?></dt><dd><?= $n($totals['accepted']) ?></dd></div>
    <div class="stat"><dt><?= $word('RECEIPT', 'set_aside') ?></dt><dd><?= $n($totals['verify']) ?></dd></div>
    <div class="stat"><dt><?= $word('RECEIPT', 'quarantine') ?></dt><dd><?= $n($totals['quarantine']) ?></dd></div>
    <div class="stat"><dt><?= $word('RECEIPT', 'refused') ?></dt><dd><?= $n($totals['refused']) ?></dd></div>
    <div class="stat"><dt><?= $word('RECEIPT', 'short') ?></dt><dd><?= $n($totals['short']) ?></dd></div>
    <div class="stat"><dt><?= $word('RECEIPT', 'value') ?></dt><dd><?= $e($totals['net']) ?></dd></div>
  </dl>
</section>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('RECEIPT', 'lines') ?></h2>
  <div class="table-wrap">
  <table class="stack list grn-lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('RECEIPT', 'product') ?></th>
        <th scope="col"><?= $word('RECEIPT', 'pack') ?></th>
        <th scope="col" class="num"><?= $word('RECEIPT', 'packs') ?></th>
        <th scope="col" class="num"><?= $word('RECEIPT', 'items') ?></th>
        <th scope="col" class="num"><?= $word('RECEIPT', 'per_pack') ?></th>
        <th scope="col"><?= $word('RECEIPT', 'order_line') ?></th>
        <th scope="col"><?php if ($doc->status === 'draft'): ?><?= $word('RECEIPT', 'will_go') ?><?php else: ?><?= $word('RECEIPT', 'went') ?><?php endif; ?></th>
        <th scope="col"><?= $word('RECEIPT', 'duty_stamp') ?></th>
        <th scope="col"><?= $word('RECEIPT', 'selling') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $no => $l): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a> <span class="o-sub"><?= $e($l['sku_code']) ?> · <?= $say('RECEIPT', 'line', $no) ?><?php if ($l['supplier_code'] !== null): ?> · <?= $say('RECEIPT', 'their_code', (string) $l['supplier_code']) ?><?php endif; ?></span><?php if ($l['description'] !== null): ?> <span class="o-sub"><?= $e($l['description']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('RECEIPT', 'pack') ?>"><?= $e($l['pack_label']) ?></td>
        <td class="num" data-label="<?= $word('RECEIPT', 'packs') ?>"><?= $n($l['packs']) ?></td>
        <td class="num" data-label="<?= $word('RECEIPT', 'items') ?>"><?= $n($l['units']) ?></td>
        <td class="num" data-label="<?= $word('RECEIPT', 'per_pack') ?>">£<?= $e($l['price']) ?></td>
        <td data-label="<?= $word('RECEIPT', 'order_line') ?>"><?php if ($l['po_line_no'] !== null): ?><?= $say('RECEIPT', 'order_line_no', (int) $l['po_line_no']) ?><?php endif; ?></td>
        <td data-label="<?php if ($doc->status === 'draft'): ?><?= $word('RECEIPT', 'will_go') ?><?php else: ?><?= $word('RECEIPT', 'went') ?><?php endif; ?>"><?= $e($l['went']) ?><?php if ($l['findings'] !== ''): ?> <span class="o-sub"><?= $e($l['findings']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('RECEIPT', 'duty_stamp') ?>"><?php if ($l['stamp_req'] === false): ?><?= $chip('off', \CW\Ui\Words::RECEIPT['stamp_not_needed']) ?><?php elseif ($l['stamp_on_pack'] === null): ?><?= $chip('needs', \CW\Ui\Words::RECEIPT['stamp_not_checked']) ?><?php elseif ((int) $l['stamp_on_pack'] === 1): ?><?= $chip('done', \CW\Ui\Words::RECEIPT['stamp_on']) ?><?php if ($l['stamp_type'] !== null): ?> <span class="o-sub"><?= $word('STAMP_TYPE', $l['stamp_type']) ?></span><?php endif; ?><?php else: ?><?= $chip('blocked', \CW\Ui\Words::RECEIPT['stamp_off']) ?><?php endif; ?><?php if ($l['stamp_code'] !== null): ?> <code><?= $e($l['stamp_code']) ?></code><?php endif; ?><?php if ($l['duty_text'] !== null): ?> <span class="o-sub"><?= $say('RECEIPT', 'duty_about', (string) $l['duty_text']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('RECEIPT', 'selling') ?>"><?php if ($l['mode_resolved'] !== null): ?><?= $say('RECEIPT', 'mode_with', (string) $l['mode_resolved']['mode'], \CW\Ui\Words::of('MODE_SOURCE', (string) $l['mode_resolved']['source'])) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if ($incidents !== []): ?>
<section aria-labelledby="incidents-h">
  <h2 id="incidents-h"><?= $word('RECEIPT', 'incidents') ?></h2>
  <ul class="plain incident-list">
<?php foreach ($incidents as $i): ?>
    <li><?= $stateChip('INCIDENT_STATE', $i['status']) ?> <?= $say('RECEIPT', 'incident_line', (int) $i['line_no'], (string) ($i['sku_name'] ?? $i['sku_code']), (int) $i['units'], mb_strtolower(\CW\Ui\Words::of('INCIDENT_KIND', (string) $i['kind']))) ?>
      <span class="o-sub"><?= $word('INCIDENT_WHERE', $i['disposition']) ?><?php if ($i['resolution'] !== null): ?> · <?= $e($i['resolution']) ?><?php endif; ?></span></li>
<?php endforeach; ?>
  </ul>
<?php if ($canResolve): ?>
  <p class="see-also"><a href="/ui/receiving/incidents"><?= $word('RECEIPT', 'close_them') ?></a></p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tasks !== []): ?>
<section aria-labelledby="review-h">
  <h2 id="review-h"><?= $word('RECEIPT', 'checks') ?></h2>
  <ul class="plain">
<?php foreach ($tasks as $t): ?>
    <li><?= $stateChip('TASK_STATE', $t['state']) ?> <?= $say('RECEIPT', 'check_line', (string) $t['of'], \CW\Ui\Html::when((string) $t['opened_at']), (string) ($t['opened_by_name'] ?? ''), \CW\Ui\Html::when((string) $t['due_at'])) ?><?php if ($t['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::RECEIPT['late']) ?><?php endif; ?><?php if ($t['decided_by_name'] !== null): ?>
      <span class="o-sub"><?php if ($t['decision_note'] !== null): ?><?= $say('RECEIPT', 'check_decided_note', \CW\Ui\Words::of('TASK_STATE', (string) $t['state']), (string) $t['decided_by_name'], (string) $t['decision_note']) ?><?php else: ?><?= $say('RECEIPT', 'check_decided', \CW\Ui\Words::of('TASK_STATE', (string) $t['state']), (string) $t['decided_by_name']) ?><?php endif; ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($reverseReasons !== []): ?>
<details class="fold action" id="reverse">
  <summary><?= $word('RECEIPT', 'reverse') ?></summary>
  <h2 class="section-title"><?= $word('RECEIPT', 'reverse_title') ?></h2>
  <p class="hint"><?= $word('RECEIPT', 'reverse_does') ?></p>
  <form class="record" method="post" action="<?= $u('/ui/receiving/' . $doc->id . '/reverse') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECEIPT', 'reverse_why') ?>
      <select name="reason_code" required>
        <option value=""><?= $word('RECEIPT', 'choose_reason') ?></option>
<?php foreach ($reverseReasons as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RECEIPT', 'reverse_note') ?> <input type="text" name="note" maxlength="1000"></label>
    <p class="actions"><button type="submit" class="danger"><?= $word('RECEIPT', 'reverse_button') ?></button></p>
  </form>
</details>
<?php endif; ?>

<?= $partial('receipt_files', ['doc' => $doc, 'files' => $files, 'fileRoles' => $fileRoles, 'csrf' => $csrf, 'canAttach' => $canAttach, 'back' => '', 'maxMb' => $maxMb]) ?>
