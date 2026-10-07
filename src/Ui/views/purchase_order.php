<p class="crumbs"><a href="/ui/purchasing/orders"><?= $word('MENU', 'orders') ?></a></p>
<h1><?= $e($title) ?></h1>
<p class="eyebrow"><?= $stateChip('PO_STATE', $state) ?><?php if ($doc->reviewState !== null && $doc->reviewState !== 'not_required'): ?> <?= $stateChip('REVIEW_STATE', $doc->reviewState) ?><?php endif; ?></p>
<?= $intro('order') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($rejected !== null): ?>
<div class="error" role="status">
  <p><?= $say('ORDER', 'rejected', (string) $rejected['decision_note'], (string) ($rejected['decided_by_name'] ?? ''), \CW\Ui\Html::day((string) $rejected['decided_at'])) ?></p>
  <p><?= $word('ORDER', 'rejected_next') ?></p>
</div>
<?php endif; ?>
<?php if ($warnings !== []): ?>
<ul class="warnings">
<?php foreach ($warnings as $w): ?>
  <li><?= $e($w) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($companyNote !== null): ?>
<p class="note company-note"><?= $e($companyNote['text']) ?> <a href="/ui/reference/company"><?= $e($companyNote['link']) ?></a></p>
<?php endif; ?>
<?php if ($doc->status === 'draft'): ?>
<p class="note read-only"><?php if ($canPost): ?><?= $say('ORDER', 'only_creator', (string) $people['created']) ?><?php else: ?><?= $say('ORDER', 'only_creator_look', (string) $people['created']) ?><?php endif; ?></p>
<?php endif; ?>

<?php foreach ($decide as $d): ?>
<section class="card decide-box<?php if ($d['refusal'] === null): ?> waiting<?php endif; ?>" aria-labelledby="decide-<?= $e($d['task']['id']) ?>-h">
  <div class="head-help">
    <h2 id="decide-<?= $e($d['task']['id']) ?>-h"><?= $e($d['title']) ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $e($d['text']) ?><?php if ($d['task']['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::ORDER['late']) ?><?php endif; ?></p>
<?php if ($d['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($d['refusal']) ?></p>
<?php else: ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done">
        <dt><?= $e($d['ok']) ?></dt>
        <dd><?= $e($d['okDoes']) ?></dd>
      </div>
      <div class="answer blocked<?php if ($d['safer']): ?> safe<?php endif; ?>">
        <dt><?= $word('ORDER', 'not_ok') ?><?php if ($d['safer']): ?> <span class="safe-tag"><?= $word('UI', 'safer') ?></span><?php endif; ?></dt>
        <dd><?= $e($d['notOkDoes']) ?></dd>
      </div>
    </dl>
  </div>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('ORDER', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary"><?= $e($d['ok']) ?></button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $d['task']['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('ORDER', 'why_not_ok') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit"><?= $word('ORDER', 'not_ok') ?></button>
  </form>
<?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($can['approve'] || $can['withdraw'] || $can['send'] || $can['close'] || $can['cancel'] || $can['amend'] || $can['copy']): ?>
<section class="card order-actions" aria-labelledby="actions-h">
  <h2 id="actions-h"><?= $word('ORDER', 'next') ?></h2>
<?php if ($can['approve']): ?>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit" class="primary btn big"><span class="btn-title"><?php if ($overLimit): ?><?= $word('PO', 'confirm_over_limit') ?><?php else: ?><?= $word('ORDER', 'confirm_draft') ?><?php endif; ?></span>
      <span class="sub"><?php if ($overLimit): ?><?= $word('ORDER', 'confirm_over_does') ?><?php else: ?><?= $word('ORDER', 'confirm_does') ?><?php endif; ?></span></button>
  </form>
<?php endif; ?>
<?php if ($can['withdraw']): ?>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
    <button type="submit" class="btn big secondary"><span class="btn-title"><?= $word('ORDER', 'withdraw') ?></span> <span class="sub"><?= $word('ORDER', 'withdraw_does') ?></span></button>
  </form>
<?php endif; ?>
<?php if ($can['send']): ?>
  <details class="action"<?php if ($state === 'approved'): ?> open<?php endif; ?>>
    <summary><?php if ($state === 'sent'): ?><?= $word('ORDER', 'send_again_open') ?><?php else: ?><?= $word('ORDER', 'send') ?><?php endif; ?></summary>
    <h3 class="section-title"><?= $word('ORDER', 'send_title') ?></h3>
    <p class="hint"><?= $word('PO', 'no_email') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/send') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
      <label><?= $word('PO', 'send_how') ?>
        <select name="via">
<?php foreach ($sendVia as $code => $label): ?>
          <option value="<?= $e($code) ?>"><?= $e($label) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('ORDER', 'send_to') ?> <input type="text" name="to" maxlength="191" value="<?= $e($supplier['email'] ?? '') ?>"></label>
<?php if ($sendWarnings !== []): ?>
      <div class="note">
        <p><strong><?= $word('ORDER', 'send_stop') ?></strong></p>
        <p><span class="warnings"><?php foreach ($sendWarnings as $w): ?><?= $e($w) ?> <?php endforeach; ?><?php if ($sendCompany): ?><a href="/ui/reference/company"><?= $word('ORDER', 'company_see') ?></a><?php endif; ?></span></p>
<?php if ($sendCompany): ?>
        <p><?= $word('ORDER', 'send_better') ?></p>
<?php endif; ?>
      </div>
      <label class="choice"><input type="checkbox" name="send_anyway" value="1" required> <?= $word('ORDER', 'send_anyway') ?></label>
<?php endif; ?>
      <p class="actions"><button type="submit" class="primary"><?php if ($state === 'sent'): ?><?= $word('PO', 'send_again') ?><?php else: ?><?= $word('PO', 'sent') ?><?php endif; ?></button></p>
    </form>
  </details>
<?php endif; ?>
<?php if ($can['close']): ?>
  <details class="action">
    <summary><?= $word('ORDER', 'close') ?></summary>
    <h3 class="section-title"><?= $word('ORDER', 'close_title') ?></h3>
    <p class="hint"><?= $word('ORDER', 'close_does') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/close') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
      <label><?= $word('ORDER', 'close_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
      <p class="actions"><button type="submit"><?= $word('ORDER', 'close_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
<?php if ($can['cancel']): ?>
  <details class="action"<?php if ($reasonError === 'cancel'): ?> open<?php endif; ?>>
    <summary><?= $word('ORDER', 'cancel') ?></summary>
    <h3 class="section-title"><?= $word('ORDER', 'cancel_title') ?></h3>
    <p class="hint"><?php if ($doc->status === 'draft'): ?><?= $word('ORDER', 'cancel_draft_does') ?><?php elseif ($doc->status === 'awaiting_approval'): ?><?= $word('ORDER', 'cancel_request_does') ?><?php else: ?><?= $word('ORDER', 'cancel_does') ?><?php endif; ?></p>
    <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/cancel') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($doc->version) ?>">
      <label><?= $word('ORDER', 'cancel_why') ?>
        <select name="reason_code" required<?php if (($reasonError ?? null) === 'cancel'): ?> aria-invalid="true"<?php endif; ?>>
          <option value=""><?= $word('ORDER', 'choose_reason') ?></option>
<?php foreach ($cancelReasons as $r): ?>
          <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('ORDER', 'cancel_note') ?> <input type="text" name="note" maxlength="400"></label>
      <p class="actions"><button type="submit" class="danger"><?= $word('ORDER', 'cancel_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
<?php if ($can['amend']): ?>
  <details class="action"<?php if ($reasonError === 'amend'): ?> open<?php endif; ?>>
    <summary><?= $word('ORDER', 'correct') ?></summary>
    <h3 class="section-title"><?= $word('ORDER', 'correct_title') ?></h3>
    <p class="hint"><?= $word('ORDER', 'correct_does') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/amend') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
      <label><?= $word('ORDER', 'correct_why') ?>
        <select name="reason_code" required<?php if (($reasonError ?? null) === 'amend'): ?> aria-invalid="true"<?php endif; ?>>
          <option value=""><?= $word('ORDER', 'choose_reason') ?></option>
<?php foreach ($amendReasons as $r): ?>
          <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label><?= $word('ORDER', 'cancel_note') ?> <input type="text" name="note" maxlength="400"></label>
      <p class="actions"><button type="submit"><?= $word('ORDER', 'correct_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
<?php if ($can['copy']): ?>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/orders/' . $doc->id . '/copy') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($formKey) ?>">
    <button type="submit" class="btn big secondary"><span class="btn-title"><?= $word('ORDER', 'copy') ?></span> <span class="sub"><?= $word('ORDER', 'copy_does') ?></span></button>
  </form>
<?php endif; ?>
<?php if ($doc->status === 'posted'): ?>
  <ul class="hint rules-short">
    <li><?= $word('ORDER', 'rules') ?></li>
    <li><?= $word('ORDER', 'rules_cancel') ?></li>
    <li><?= $word('ORDER', 'rules_correct') ?></li>
    <li><?= $word('ORDER', 'rules_copy') ?></li>
    <li><?= $word('ORDER', 'rules_close') ?></li>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="order-h">
    <h2 id="order-h"><?= $word('ORDER', 'facts') ?></h2>
    <dl>
      <dt><?= $word('ORDER', 'supplier') ?></dt><dd><a href="<?= $u('/ui/purchasing/suppliers/' . ($supplier['id'] ?? 0)) ?>"><?= $e($supplierName) ?></a></dd>
      <dt><?= $word('ORDER', 'status') ?></dt><dd><?= $word('PO_STATE', $state) ?></dd>
      <dt><?= $word('ORDER', 'check') ?></dt><dd><?php if ($doc->reviewState === null): ?><span class="muted"><?= $word('ORDER', 'not_confirmed') ?></span><?php else: ?><?= $word('CHECK_STATE', $doc->reviewState) ?><?php endif; ?></dd>
<?php if ($doc->docDate !== null): ?>
      <dt><?= $word('ORDER', 'order_date') ?></dt><dd><?= $day($doc->docDate) ?></dd>
<?php endif; ?>
<?php if (($po['expected_date'] ?? null) !== null): ?>
      <dt><?= $word('ORDER', 'expected') ?></dt><dd><?= $day($po['expected_date']) ?></dd>
<?php endif; ?>
<?php if ($doc->externalRef !== null && $doc->externalRef !== ''): ?>
      <dt><?= $word('ORDER', 'ref') ?></dt><dd><?= $e($doc->externalRef) ?></dd>
<?php endif; ?>
<?php if ($doc->note !== null && $doc->note !== ''): ?>
      <dt><?= $word('ORDER', 'note') ?></dt><dd class="pre"><?= $e($doc->note) ?></dd>
<?php endif; ?>
      <dt><?= $word('ORDER', 'source') ?></dt><dd><?= $word('PO_SOURCE', (string) ($po['source'] ?? 'manual')) ?></dd>
<?php if ($amends !== null): ?>
      <dt><?= $word('ORDER', 'corrects') ?></dt><dd><a href="<?= $u('/ui/purchasing/orders/' . $amends['id']) ?>"><?= $e($amends['number']) ?></a></dd>
<?php endif; ?>
<?php foreach ($amendedBy as $a): ?>
      <dt><?= $word('ORDER', 'corrected_by') ?></dt><dd><a href="<?= $u('/ui/purchasing/orders/' . $a['id']) ?>"><?= $e($a['label']) ?></a></dd>
<?php endforeach; ?>
      <dt><?= $word('ORDER', 'started') ?></dt><dd><?= $say('ORDER', 'by', \CW\Ui\Html::when($doc->createdAt), (string) $people['created']) ?></dd>
<?php if ($doc->submittedAt !== null): ?>
      <dt><?= $word('ORDER', 'asked') ?></dt><dd><?= $say('ORDER', 'by', \CW\Ui\Html::when($doc->submittedAt), (string) $people['submitted']) ?></dd>
<?php endif; ?>
<?php if ($doc->postedAt !== null): ?>
      <dt><?= $word('ORDER', 'confirmed') ?></dt><dd><?= $say('ORDER', 'by', \CW\Ui\Html::when($doc->postedAt), (string) $people['posted']) ?></dd>
<?php endif; ?>
<?php if (($po['sent_at'] ?? null) !== null): ?>
      <dt><?= $word('ORDER', 'sent') ?></dt><dd><?= $say('ORDER', 'sent_line', \CW\Ui\Html::when((string) $po['sent_at']), (string) $people['sent'], lcfirst(\CW\Ui\Words::of('SEND_VIA', (string) $po['sent_via']))) ?><?php if ($po['sent_to'] !== null): ?> <?= $say('ORDER', 'sent_to', (string) $po['sent_to']) ?><?php endif; ?></dd>
<?php endif; ?>
<?php if (($po['closed_at'] ?? null) !== null): ?>
      <dt><?= $word('ORDER', 'closed') ?></dt><dd><?= $say('ORDER', 'with_reason', \CW\Ui\Words::say('ORDER', 'by', \CW\Ui\Html::when((string) $po['closed_at']), (string) $people['closed']), (string) $po['close_reason']) ?></dd>
<?php endif; ?>
<?php if ($reversal !== null): ?>
      <dt><?= $word('ORDER', 'cancelled') ?></dt><dd><?php if ($reversal->number !== null): ?><?= $day($reversal->docDate) ?> · <?= $e($reversalReason ?? '') ?><?php if ($reversal->note !== null && $reversal->note !== ''): ?> – <?= $e($reversal->note) ?><?php endif; ?> (<?= $say('ORDER', 'cancel_record', (string) $reversal->number) ?>)<?php else: ?><?= $word('ORDER', 'cancel_waiting') ?><?php endif; ?>
        <a href="<?= $u('/ui/purchasing/orders/' . $reversal->id . '/pdf') ?>"><?= $word('ORDER', 'cancel_pdf') ?></a></dd>
<?php elseif ($doc->cancelledAt !== null): ?>
      <dt><?= $word('ORDER', 'cancelled') ?></dt><dd><?= $say('ORDER', 'with_reason', \CW\Ui\Words::say('ORDER', 'by', \CW\Ui\Html::when($doc->cancelledAt), (string) $people['cancelled']), (string) $cancelReason) ?></dd>
<?php endif; ?>
    </dl>
  </section>
  <section class="card" aria-labelledby="totals-h">
    <h2 id="totals-h"><?= $word('ORDER', 'totals') ?></h2>
    <dl>
      <dt><?= $word('ORDER', 'products') ?></dt><dd><?= $say('ORDER', count($lines) === 1 ? 'products_line_one' : 'products_line', count($lines), $units) ?><?php if ($received > 0): ?>, <?= $say('ORDER', 'delivered_line', $received) ?><?php endif; ?></dd>
      <dt><?= $word('ORDER', 'net') ?></dt><dd><?= $e($totals['net']) ?></dd>
<?php foreach ($totals['by_code'] as $code => $v): ?>
      <dt><?= $say('ORDER', 'vat_line', (string) $code, $vatLabels[$code] ?? $v['rate'] . '%') ?></dt><dd><?= $e($v['vat']) ?></dd>
<?php endforeach; ?>
      <dt><?= $word('ORDER', 'gross') ?></dt><dd><strong><?= $e($totals['gross']) ?></strong></dd>
    </dl>
    <ul class="plain links">
      <li><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/pdf') ?>"><?php if ($doc->status === 'posted' && $companyNote !== null): ?><?= $word('ORDER', 'pdf_hold') ?><?php elseif ($doc->status === 'posted'): ?><?= $word('ORDER', 'pdf') ?><?php else: ?><?= $word('ORDER', 'pdf_draft') ?><?php endif; ?></a></li>
      <li><a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.xlsx') ?>"><?= $word('ORDER', 'xlsx') ?></a> · <a href="<?= $u('/ui/purchasing/orders/' . $doc->id . '/lines.csv') ?>"><?= $word('ORDER', 'csv') ?></a></li>
      <li><a href="<?= $u('/ui/documents/' . $doc->id) ?>"><?= $word('ORDER', 'record') ?></a></li>
    </ul>
  </section>
</div>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('ORDER', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack po-lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('ORDER', 'product') ?></th>
        <th scope="col"><?= $word('ORDER', 'their_code') ?></th>
        <th scope="col"><?= $word('ORDER', 'pack') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'packs') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'items') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'delivered') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'per_pack') ?></th>
        <th scope="col"><?= $word('ORDER', 'vat') ?></th>
        <th scope="col" class="num"><?= $word('ORDER', 'total') ?></th>
        <th scope="col"><?= $word('ORDER', 'line_note') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
<?php if ($l['kind'] === 'charge'): ?>
        <th scope="row" class="c-head"><?= $word('ORDER', 'charge') ?> <span class="o-sub"><?= $say('ORDER', 'line', (int) $l['line_no']) ?></span></th>
        <td data-label="<?= $word('ORDER', 'their_code') ?>"></td>
        <td data-label="<?= $word('ORDER', 'pack') ?>"></td>
        <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"></td>
        <td class="num" data-label="<?= $word('ORDER', 'items') ?>"></td>
        <td class="num" data-label="<?= $word('ORDER', 'delivered') ?>"></td>
<?php else: ?>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_name']) ?></a> <span class="o-sub"><?= $e($l['sku_code']) ?> · <?= $say('ORDER', 'line', (int) $l['line_no']) ?></span></th>
        <td data-label="<?= $word('ORDER', 'their_code') ?>"><?= $e($l['code']) ?></td>
        <td data-label="<?= $word('ORDER', 'pack') ?>"><?= $e($l['pack_label']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'packs') ?>"><?= $n($l['packs']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'items') ?>"><?= $n($l['qty']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'delivered') ?>"><?= $n($l['received_units']) ?></td>
<?php endif; ?>
        <td class="num" data-label="<?= $word('ORDER', 'per_pack') ?>">£<?= $e($l['price']) ?></td>
        <td data-label="<?= $word('ORDER', 'vat') ?>"><?= $e($l['vat_code']) ?></td>
        <td class="num" data-label="<?= $word('ORDER', 'total') ?>"><?= $e($l['net']) ?></td>
        <td data-label="<?= $word('ORDER', 'line_note') ?>"><?= $e($l['description']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="files-h">
  <h2 id="files-h"><?= $word('ORDER', 'files') ?></h2>
<?php if ($files === []): ?>
  <p class="muted"><?php if ($doc->status === 'posted'): ?><?= $word('ORDER', 'no_files') ?><?php else: ?><?= $word('ORDER', 'no_files_draft') ?><?php endif; ?></p>
<?php else: ?>
  <ul class="plain">
<?php foreach ($files as $f): ?>
    <li><?= $e($f['what']) ?> – <a href="<?= $u('/ui/files/' . $f['id']) ?>"><?= $e($f['original_name']) ?></a> <span class="muted">(<?= $say('ORDER', 'file_line', $f['size'], \CW\Ui\Html::day((string) $f['attached_at'])) ?>)</span></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <div class="head-help">
    <h2 id="history-h"><?= $word('ORDER', 'history') ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('ORDER', 'no_history') ?></p>
<?php else: ?>
  <ol class="plain checks-history">
<?php foreach ($history as $h): ?>
    <li>
      <p><?= $e($h['asked']) ?> <?= $e($h['why']) ?><?php if ($h['about'] !== null): ?> <?= $e($h['about']) ?><?php endif; ?></p>
      <p class="muted"><?= $e($h['outcome']) ?><?php if ($h['note'] !== null): ?> "<?= $e($h['note']) ?>"<?php endif; ?></p>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
