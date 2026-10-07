<p class="crumbs"><a href="/ui/reference/settings"><?= $word('MENU', 'settings') ?></a></p>
<h1><?= $word('MENU', 'company') ?></h1>
<?= $intro('company') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($errorMissing !== null): ?> <span class="visually-hidden"><?= $say('COMPANY', 'missing', implode(', ', $errorMissing)) ?></span><?php endif; ?></p>
<?php endif; ?>

<section class="card company-status<?php if (!$p['confirmed']): ?> waiting<?php endif; ?>" aria-labelledby="status-h">
  <h2 id="status-h"><?php if ($p['confirmed']): ?><?= $chip('done', \CW\Ui\Words::COMPANY['confirmed']) ?><?php else: ?><?= $chip('blocked', \CW\Ui\Words::COMPANY['not_confirmed']) ?><?php endif; ?></h2>
<?php if ($p['confirmed']): ?>
  <p><?= $say('COMPANY', 'confirmed_by', $confirmedLabel, \CW\Ui\Html::when($p['confirmed_at'])) ?></p>
<?php else: ?>
  <p><?= $word('COMPANY', 'banner') ?></p>
<?php if ($missing !== []): ?>
  <p class="note"><?= $say('COMPANY', 'missing', implode(', ', $missing)) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($problems !== []): ?>
  <p class="note"><?php if ($p['confirmed']): ?><?= $word('COMPANY', 'problems_confirmed') ?><?php else: ?><?= $word('COMPANY', 'problems') ?><?php endif; ?></p>
  <ul class="warnings">
<?php foreach ($problems as $problem): ?>
    <li><?= $e($problem) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if (!$p['confirmed']): ?>
<?php if ($mayConfirm): ?>
  <form class="confirm" method="post" action="/ui/reference/company/confirm">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($confirmKey) ?>">
    <input type="hidden" name="version" value="<?= $e($p['version']) ?>">
    <p><?= $word('COMPANY', 'check_first') ?></p>
    <p><strong><?= $word('UI', 'what_happens') ?></strong> <?= $word('COMPANY', 'confirm_after') ?></p>
    <button type="submit" class="primary"><?= $word('COMPANY', 'confirm') ?></button>
  </form>
<?php elseif ($canConfirm && $unsaved): ?>
  <p class="muted"><?= $word('COMPANY', 'unsaved') ?></p>
<?php elseif ($canConfirm): ?>
  <p class="muted"><?= $say('COMPANY', 'fill_in', \CW\Ui\Words::COMPANY[$p['version'] === 0 || $missing !== [] ? 'add' : 'change']) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($vatWarning): ?>
  <p class="note"><?= $word('COMPANY', 'vat_digits') ?></p>
<?php endif; ?>
<?php if ($rejectedOrders !== []): ?>
  <div class="note rejected-orders" role="alert">
    <p><?php if (count($rejectedOrders) === 1): ?><?= $word('COMPANY', 'rejected_one') ?><?php else: ?><?= $say('COMPANY', 'rejected_many', count($rejectedOrders)) ?><?php endif; ?></p>
    <ul class="plain">
<?php foreach ($rejectedOrders as $o): ?>
      <li><a href="<?= $u('/ui/purchasing/orders/' . $o['document_id']) ?>"><?= $e($o['number']) ?></a> (<?= $word('PO_STATE', $o['state']) ?>)</li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($staleOrders !== []): ?>
  <div class="note stale-orders">
    <p><?php if (count($staleOrders) === 1): ?><?= $word('COMPANY', 'stale_one') ?><?php else: ?><?= $say('COMPANY', 'stale_many', count($staleOrders)) ?><?php endif; ?></p>
    <ul class="plain">
<?php foreach ($staleOrders as $o): ?>
      <li><a href="<?= $u('/ui/purchasing/orders/' . $o['document_id']) ?>"><?= $e($o['number']) ?></a></li>
<?php endforeach; ?>
    </ul>
    <p><?php if ($p['confirmed']): ?><?= $word('COMPANY', 'stale_fix') ?><?php else: ?><?= $word('COMPANY', 'stale_fix_first') ?><?php endif; ?></p>
  </div>
<?php endif; ?>
  <p class="actions">
<?php if ($canEdit): ?>
    <a class="button primary-link" href="/ui/reference/company/edit"><?php if ($p['version'] === 0 || $missing !== []): ?><?= $word('COMPANY', 'add') ?><?php else: ?><?= $word('COMPANY', 'change') ?><?php endif; ?></a>
<?php endif; ?>
    <a href="/ui/reference/company/sample.pdf"><?= $word('COMPANY', 'sample') ?></a>
  </p>
<?php if ($lookOnly !== null): ?>
  <p class="muted read-only"><?= $e($lookOnly) ?></p>
<?php endif; ?>
</section>

<?php if ($reviews !== []): ?>
<section aria-labelledby="reviews-h">
  <div class="head-help">
    <h2 id="reviews-h"><?= $word('COMPANY', 'checks') ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p class="muted"><?= $word('COMPANY', 'checks_text') ?></p>
<?php foreach ($reviews as $t): ?>
  <article class="card review<?php if ($t['open']): ?> waiting<?php endif; ?>">
    <p><strong><?= $say('COMPANY', 'check_line', $t['who'], \CW\Ui\Html::when($t['opened_at'])) ?></strong>
<?php if ($t['open']): ?>
      <?= $word('COMPANY', 'check_waiting') ?><?php if (!$t['alone']): ?> <?= $say('COMPANY', 'check_by', \CW\Ui\Html::day($t['due_at'])) ?><?php if ($t['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::COMPANY['late']) ?><?php endif; ?><?php endif; ?>
<?php else: ?>
      <?= $chip(\CW\Ui\Words::tone('TASK_STATE', $t['state']), \CW\Ui\Words::say('COMPANY', 'check_decided', \CW\Ui\Words::of('TASK_STATE', $t['state']), (string) $t['decided_by_name'], \CW\Ui\Html::when($t['decided_at']))) ?><?php if ($t['decision_note'] !== null): ?> <?= $e($t['decision_note']) ?><?php endif; ?>
<?php endif; ?>
    </p>
<?php if ($t['changes'] !== []): ?>
    <p class="muted"><?= $word('COMPANY', 'changed') ?></p>
    <ul class="plain changes">
<?php foreach ($t['changes'] as $c): ?>
      <li<?php if ($c['watched']): ?> class="watched"<?php endif; ?>><span class="field"><?= $e(ucfirst($c['label'])) ?></span>
        <span class="was"><?= $word('COMPANY', 'was') ?> <span class="pre"><?php if ($c['before'] === ''): ?><span class="muted"><?= $word('COMPANY', 'empty') ?></span><?php else: ?><?= $e($c['before']) ?><?php endif; ?></span></span>
        <span class="now"><?= $word('COMPANY', 'now') ?> <span class="pre"><?php if ($c['after'] === ''): ?><span class="muted"><?= $word('COMPANY', 'empty') ?></span><?php else: ?><?= $e($c['after']) ?><?php endif; ?></span></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($t['open']): ?>
<?php if ($t['may_decide']): ?>
    <div class="answers">
      <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
      <dl class="answer-list">
        <div class="answer done">
          <dt><?= $word('COMPANY', 'right') ?></dt>
          <dd><?= $word('COMPANY', 'right_does') ?></dd>
        </div>
        <div class="answer blocked">
          <dt><?= $word('COMPANY', 'wrong') ?></dt>
          <dd><?= $word('COMPANY', 'wrong_does') ?></dd>
        </div>
      </dl>
    </div>
    <form class="inline" method="post" action="<?= $u('/ui/reference/company/reviews/' . $t['id'] . '/approve') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label><?= $word('COMPANY', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
      <button type="submit" class="primary"><?= $word('COMPANY', 'right') ?></button>
    </form>
    <form class="inline" method="post" action="<?= $u('/ui/reference/company/reviews/' . $t['id'] . '/reject') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label><?= $word('COMPANY', 'why_wrong') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
      <button type="submit"><?= $word('COMPANY', 'wrong') ?></button>
    </form>
<?php elseif ($t['refusal'] !== null): ?>
    <p class="note read-only"><?= $e($t['refusal']) ?></p>
<?php endif; ?>
<?php if ($t['alone']): ?>
    <p class="muted"><?= $word('COMPANY', 'alone') ?></p>
<?php endif; ?>
<?php endif; ?>
  </article>
<?php endforeach; ?>
</section>
<?php endif; ?>

<section aria-labelledby="details-h">
  <h2 id="details-h"><?= $word('COMPANY', 'details') ?></h2>
  <dl class="wide company">
<?php foreach (['legal_name', 'trading_name', 'company_number', 'vat', 'address', 'phone', 'email', 'delivery_address'] as $f): ?>
    <dt><?= $word('COMPANY', $f) ?></dt>
    <dd<?php if ($f === 'address' || $f === 'delivery_address'): ?> class="pre"<?php endif; ?>><?php if ($show[$f] === ''): ?><span class="muted"><?php if ($f === 'trading_name'): ?><?= $word('COMPANY', 'none') ?><?php else: ?><?= $word('COMPANY', 'not_given') ?><?php endif; ?></span><?php else: ?><?= $e($show[$f]) ?><?php endif; ?></dd>
<?php endforeach; ?>
<?php if ($p['version'] > 0): ?>
    <dt><?= $word('COMPANY', 'last_saved') ?></dt>
    <dd><?= $say('COMPANY', 'by', \CW\Ui\Html::when($p['saved_at']), $savedLabel) ?></dd>
<?php endif; ?>
  </dl>
  <p class="muted"><?= $word('COMPANY', 'no_bank') ?></p>
</section>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('COMPANY', 'history') ?></h2>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('COMPANY', 'no_history') ?></p>
<?php else: ?>
  <ol class="versions" reversed>
<?php foreach ($history as $h): ?>
    <li>
      <p><strong><?= $when($h['saved_at']) ?></strong> &middot; <?= $e($h['what']) ?><?php if ($h['check'] !== null): ?> (<?= $e($h['check']) ?>)<?php endif; ?>
        <?php if ($h['confirmed']): ?><?= $chip('done', \CW\Ui\Words::COMPANY['confirmed']) ?><?php else: ?><?= $chip('off', \CW\Ui\Words::COMPANY['not_confirmed']) ?><?php endif; ?></p>
<?php if ($h['reason'] !== null): ?>
      <p class="muted"><?= $say('COMPANY', 'why', $h['reason']) ?></p>
<?php endif; ?>
<?php if ($h['changes'] !== []): ?>
<?php if ($h['kind'] === 'confirm' || $h['kind'] === 'unconfirm'): ?>
      <p class="error"><?= $word('COMPANY', 'broken') ?></p>
<?php endif; ?>
      <ul class="plain changes">
<?php foreach ($h['changes'] as $c): ?>
        <li><span class="field"><?= $e(ucfirst($c['label'])) ?></span>
          <span class="was"><?= $word('COMPANY', 'was') ?> <span class="pre"><?php if ($c['before'] === ''): ?><span class="muted"><?= $word('COMPANY', 'empty') ?></span><?php else: ?><?= $e($c['before']) ?><?php endif; ?></span></span>
          <span class="now"><?= $word('COMPANY', 'now') ?> <span class="pre"><?php if ($c['after'] === ''): ?><span class="muted"><?= $word('COMPANY', 'empty') ?></span><?php else: ?><?= $e($c['after']) ?><?php endif; ?></span></span></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
