<p class="crumbs"><a href="/ui/documents"><?= $word('MENU', 'documents') ?></a></p>
<h1><?= $e($title) ?></h1>
<?= $intro('document') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if (!$handler): ?>
<p class="note"><?= $say('RECORD', 'later', $kindMany) ?></p>
<?php endif; ?>
<?php if ($poHref !== null): ?>
<p class="actions"><a class="btn secondary" href="<?= $u($poHref) ?>"><?= $word('RECORD', 'open_order') ?></a> <span class="muted"><?= $word('RECORD', 'open_order_note') ?></span></p>
<?php endif; ?>

<?php if ($decide !== null): ?>
<section class="card decide-box<?php if ($decide['refusal'] === null): ?> waiting<?php endif; ?>" aria-labelledby="decide-h">
  <div class="head-help">
    <h2 id="decide-h"><?php if ($decide['task']['kind'] === 'approval'): ?><?= $word('RECORD', 'approval_title') ?><?php else: ?><?= $word('RECORD', 'review_title') ?><?php endif; ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $e($decide['text']) ?><?php if ($decide['task']['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::CHECKS['late']) ?><?php endif; ?></p>
<?php if ($decide['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($decide['refusal']) ?></p>
<?php else: ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done">
        <dt><?= $e($decide['ok']) ?></dt>
        <dd><?= $e($decide['okDoes']) ?></dd>
      </div>
      <div class="answer blocked<?php if ($decide['safer']): ?> safe<?php endif; ?>">
        <dt><?= $word('RECORD', 'not_ok') ?><?php if ($decide['safer']): ?> <span class="safe-tag"><?= $word('UI', 'safer') ?></span><?php endif; ?></dt>
        <dd><?= $e($decide['notOkDoes']) ?></dd>
      </div>
    </dl>
  </div>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $decide['task']['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECORD', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary"><?= $e($decide['ok']) ?></button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/documents/reviews/' . $decide['task']['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECORD', 'why_not_ok') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit"><?= $word('RECORD', 'not_ok') ?></button>
  </form>
<?php endif; ?>
</section>
<?php elseif ($waiting): ?>
<p class="muted"><?= $word('RECORD', 'waiting') ?></p>
<?php endif; ?>

<dl class="wide">
  <dt><?= $word('RECORD', 'status') ?></dt><dd><?= $stateChip('DOC_STATUS', $doc->status) ?></dd>
  <dt><?= $word('RECORD', 'check') ?></dt><dd><?php if ($doc->reviewState === null): ?><span class="muted"><?= $word('RECORD', 'not_final') ?></span><?php else: ?><?= $chip(\CW\Ui\Words::tone('REVIEW_STATE', $doc->reviewState), \CW\Ui\Words::of('CHECK_STATE', $doc->reviewState)) ?><?php endif; ?></dd>
<?php if ($supplier !== null): ?>
  <dt><?= $word('RECORD', 'supplier') ?></dt><dd><?= $e($supplier) ?></dd>
<?php endif; ?>
<?php if ($value !== null): ?>
  <dt><?= $word('RECORD', 'value') ?></dt><dd><?= $money($value) ?></dd>
<?php endif; ?>
<?php if ($doc->docDate !== null): ?>
  <dt><?= $word('RECORD', 'date') ?></dt><dd><?= $day($doc->docDate) ?></dd>
<?php endif; ?>
<?php if ($warehouseName !== null): ?>
  <dt><?= $word('RECORD', 'warehouse') ?></dt><dd><?= $e($warehouseName) ?></dd>
<?php endif; ?>
<?php if ($doc->externalRef !== null && $doc->externalRef !== ''): ?>
  <dt><?= $word('RECORD', 'ref') ?></dt><dd><?= $e($doc->externalRef) ?></dd>
<?php endif; ?>
<?php if ($reasonLabel !== null): ?>
  <dt><?= $word('RECORD', 'reason') ?></dt><dd><?= $e($reasonLabel) ?></dd>
<?php endif; ?>
<?php if ($doc->note !== null && $doc->note !== ''): ?>
  <dt><?= $word('RECORD', 'note') ?></dt><dd><?= $e($doc->note) ?></dd>
<?php endif; ?>
  <dt><?= $word('RECORD', 'made') ?></dt><dd><?= $say('RECORD', 'by', \CW\Ui\Html::when($doc->createdAt), (string) $people['created']) ?></dd>
<?php if ($doc->submittedAt !== null): ?>
  <dt><?= $word('RECORD', 'asked') ?></dt><dd><?= $say('RECORD', 'by', \CW\Ui\Html::when($doc->submittedAt), (string) $people['submitted']) ?></dd>
<?php endif; ?>
<?php if ($doc->postedAt !== null): ?>
  <dt><?= $word('RECORD', 'final') ?></dt><dd><?= $say('RECORD', 'by', \CW\Ui\Html::when($doc->postedAt), (string) $people['posted']) ?></dd>
<?php endif; ?>
<?php if ($doc->cancelledAt !== null): ?>
  <dt><?= $word('RECORD', 'cancelled') ?></dt><dd><?= $say('RECORD', 'by', \CW\Ui\Html::when($doc->cancelledAt), (string) $people['cancelled']) ?>: <?= $e($doc->cancelReason) ?></dd>
<?php endif; ?>
<?php if ($reverses !== null): ?>
  <dt><?= $word('RECORD', 'cancels') ?></dt><dd><a href="<?= $u('/ui/documents/' . $reverses['id']) ?>"><?= $e($reverses['number']) ?></a></dd>
<?php endif; ?>
<?php if ($reversedBy !== null): ?>
  <dt><?php if ($reversedBy['number'] === null): ?><?= $word('RECORD', 'cancel_asked') ?><?php else: ?><?= $word('RECORD', 'cancelled_by') ?><?php endif; ?></dt>
  <dd><a href="<?= $u('/ui/documents/' . $reversedBy['id']) ?>"><?php if ($reversedBy['number'] === null): ?><?= $word('RECORD', 'cancel_waiting') ?><?php else: ?><?= $e($reversedBy['number']) ?><?php endif; ?></a></dd>
<?php endif; ?>
</dl>
<p><a href="<?= $u('/ui/documents/' . $doc->id . '/pdf') ?>"><?= $word('RECORD', 'download') ?></a></p>

<?php if ($reverse !== null): ?>
<section aria-labelledby="reverse-h">
  <h2 id="reverse-h"><?= $word('RECORD', 'cancel_title') ?></h2>
  <p class="muted"><?= $word('RECORD', 'cancel_text') ?></p>
  <form class="inline" method="post" action="<?= $u('/ui/documents/' . $doc->id . '/reverse') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('RECORD', 'why') ?>
      <select name="reason_code" required>
        <option value=""><?= $word('RECORD', 'choose') ?></option>
<?php foreach ($reverse['reasons'] as $r): ?>
        <option value="<?= $e($r['code']) ?>"><?= $e($r['label']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label><?= $word('RECORD', 'note') ?> <input type="text" name="note" maxlength="1000"></label>
    <button type="submit"><?= $word('RECORD', 'cancel_button') ?></button>
  </form>
</section>
<?php endif; ?>

<section aria-labelledby="lines-h">
  <h2 id="lines-h"><?= $word('RECORD', 'lines') ?></h2>
<?php if ($lines === []): ?>
  <p class="muted"><?= $word('RECORD', 'no_lines') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack lines">
    <thead>
      <tr>
        <th scope="col"><?= $word('RECORD', 'product') ?></th>
        <th scope="col" class="num"><?= $word('RECORD', 'line') ?></th>
<?php if ($cols['warehouse']): ?>
        <th scope="col"><?= $word('RECORD', 'warehouse') ?></th>
<?php endif; ?>
        <th scope="col" class="num"><?= $word('RECORD', 'items') ?></th>
<?php if ($cols['unit_cost']): ?>
        <th scope="col" class="num"><?= $word('RECORD', 'per_item') ?></th>
<?php endif; ?>
<?php if ($cols['amount']): ?>
        <th scope="col" class="num"><?= $word('RECORD', 'total') ?></th>
<?php endif; ?>
<?php if ($cols['reason_code']): ?>
        <th scope="col"><?= $word('RECORD', 'reason') ?></th>
<?php endif; ?>
<?php if ($cols['description']): ?>
        <th scope="col"><?= $word('RECORD', 'description') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($lines as $l): ?>
      <tr>
        <th scope="row" class="c-head"><?php if ($l['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . $l['sku_id']) ?>"><?= $e($l['sku_code'] ?? '#' . $l['sku_id']) ?></a> <?= $e($l['sku_name']) ?><?php endif; ?></th>
        <td data-label="<?= $word('RECORD', 'line') ?>" class="num"><?= $n($l['line_no']) ?></td>
<?php if ($cols['warehouse']): ?>
        <td data-label="<?= $word('RECORD', 'warehouse') ?>"><?= $e($l['warehouse_name']) ?></td>
<?php endif; ?>
        <td data-label="<?= $word('RECORD', 'items') ?>" class="num"><?= $n($l['qty']) ?></td>
<?php if ($cols['unit_cost']): ?>
        <td data-label="<?= $word('RECORD', 'per_item') ?>" class="num"><?= $money($l['unit_cost']) ?></td>
<?php endif; ?>
<?php if ($cols['amount']): ?>
        <td data-label="<?= $word('RECORD', 'total') ?>" class="num"><?= $money($l['amount']) ?></td>
<?php endif; ?>
<?php if ($cols['reason_code']): ?>
        <td data-label="<?= $word('RECORD', 'reason') ?>"><?= $e($l['reason_label'] ?? $l['reason_code']) ?></td>
<?php endif; ?>
<?php if ($cols['description']): ?>
        <td data-label="<?= $word('RECORD', 'description') ?>"><?= $e($l['description']) ?></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="files-h">
  <h2 id="files-h"><?= $word('RECORD', 'files') ?></h2>
<?php if ($files === []): ?>
  <p class="muted"><?= $word('RECORD', 'no_files') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack files">
    <thead>
      <tr>
        <th scope="col"><?= $word('RECORD', 'file') ?></th>
        <th scope="col"><?= $word('RECORD', 'file_role') ?></th>
        <th scope="col" class="num"><?= $word('RECORD', 'size') ?></th>
        <th scope="col"><?= $word('RECORD', 'added') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($files as $f): ?>
      <tr>
        <th scope="row" class="c-head"><a href="<?= $u('/ui/files/' . $f['id']) ?>"><?= $e($f['original_name']) ?></a></th>
        <td data-label="<?= $word('RECORD', 'file_role') ?>"><?= $e(ucfirst(str_replace('_', ' ', (string) $f['role']))) ?></td>
        <td data-label="<?= $word('RECORD', 'size') ?>" class="num"><?= $e(\CW\Ui\Html::size((int) $f['size_bytes'])) ?></td>
        <td data-label="<?= $word('RECORD', 'added') ?>"><?= $when($f['attached_at']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('RECORD', 'history') ?></h2>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('RECORD', 'no_history') ?></p>
<?php else: ?>
  <ol class="versions checks-history">
<?php foreach ($history as $h): ?>
    <li>
      <p><?= $e($h['asked']) ?> <span class="muted"><?= $e($h['reason']) ?></span></p>
      <p><?= $chip($h['tone'], $h['outcome']) ?><?php if ($h['note'] !== null): ?> <?= $e($h['note']) ?><?php endif; ?></p>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<?php if ($doc->postedHash !== null || $files !== []): ?>
<details class="fold">
  <summary><?= $word('RECORD', 'tech') ?></summary>
  <dl class="tech">
<?php if ($doc->postedHash !== null): ?>
    <dt><?= $word('RECORD', 'fingerprint') ?></dt><dd><code><?= $e($doc->postedHash) ?></code></dd>
<?php endif; ?>
<?php foreach ($files as $f): ?>
    <dt><?= $e($f['original_name']) ?></dt><dd><code><?= $e($f['mime']) ?> · <?= $e($f['sha256']) ?></code></dd>
<?php endforeach; ?>
  </dl>
</details>
<?php endif; ?>
