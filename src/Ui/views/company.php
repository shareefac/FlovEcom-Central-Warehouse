<p class="crumbs"><a href="/ui/reference/settings">Settings</a></p>
<h1>Company details</h1>
<p class="muted">The company that buys the stock, as every purchase order prints it: at the top of the order, in its footer and in the
  "Deliver to" box. An order keeps the details it was approved with; a draft shows the details below.</p>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($errorMissing !== null): ?> <span class="visually-hidden">Missing: <?= $e(implode(', ', $errorMissing)) ?></span><?php endif; ?></p>
<?php endif; ?>

<section class="card company-status<?php if (!$p['confirmed']): ?> waiting<?php endif; ?>" aria-labelledby="status-h">
  <h2 id="status-h"><?php if ($p['confirmed']): ?><span class="tag ok">Confirmed</span><?php else: ?><span class="tag bad">Not confirmed</span><?php endif; ?></h2>
<?php if ($p['confirmed']): ?>
  <p>Confirmed by <?= $e($confirmedLabel) ?> on <?= $dt($p['confirmed_at']) ?> UTC. Purchase orders print these details.</p>
<?php else: ?>
  <p>Every purchase order PDF says <strong>"COMPANY DETAILS NOT CONFIRMED — DO NOT SEND"</strong> until someone checks these details and confirms them.</p>
<?php if ($missing !== []): ?>
  <p class="note">Still missing: <?= $e(implode(', ', $missing)) ?>.</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($problems !== []): ?>
  <p class="note">These details need correcting<?php if ($p['confirmed']): ?> (they were confirmed before today's checks)<?php endif; ?>:</p>
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
    <p>Check every detail below against the company's records (Companies House, the VAT registration certificate) and the warehouse address
      first.</p>
    <button type="submit" class="primary">These details are correct</button>
  </form>
<?php elseif ($canConfirm && $unsaved): ?>
  <p class="muted">These details were copied from the old settings. Press "Change the details" and Save once (spaces and line breaks are tidied),
    then confirm them here.</p>
<?php elseif ($canConfirm): ?>
  <p class="muted">Press "<?php if ($p['version'] === 0 || $missing !== []): ?>Add or change the details<?php else: ?>Change the details<?php endif; ?>",
    fill in what is missing or wrong, then confirm them here.</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($vatWarning): ?>
  <p class="note">The check digits of the VAT number do not add up: check it against the VAT registration certificate (it is kept as typed).</p>
<?php endif; ?>
<?php if ($rejectedOrders !== []): ?>
  <div class="note rejected-orders" role="alert">
    <p>A reviewer rejected a change of these details, and <?= $n(count($rejectedOrders)) ?> approved purchase order<?php if (count($rejectedOrders) !== 1): ?>s<?php endif; ?>
      still <?php if (count($rejectedOrders) !== 1): ?>carry<?php else: ?>carries<?php endif; ?> it (their PDF says "do not send"). Cancel or amend
      <?php if (count($rejectedOrders) !== 1): ?>them<?php else: ?>it<?php endif; ?>:</p>
    <ul class="plain">
<?php foreach ($rejectedOrders as $o): ?>
      <li><a href="<?= $u('/ui/purchasing/orders/' . $o['document_id']) ?>"><?= $e($o['number']) ?></a> (<?= $e(str_replace('_', ' ', $o['state'])) ?>)</li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($staleOrders !== null && $staleOrders > 0): ?>
  <p class="note"><?= $n($staleOrders) ?> approved purchase order<?php if ($staleOrders !== 1): ?>s<?php endif; ?>, not sent yet, <?php if ($staleOrders !== 1): ?>were<?php else: ?>was<?php endif; ?>
    approved with company details that were not confirmed: <?php if ($staleOrders !== 1): ?>their PDFs keep<?php else: ?>its PDF keeps<?php endif; ?> those
    details and say "do not send". Once the details are confirmed, amend <?php if ($staleOrders !== 1): ?>them<?php else: ?>it<?php endif; ?>
    (<a href="/ui/purchasing/orders">Purchase orders</a>) to print the confirmed ones.</p>
<?php endif; ?>
  <p class="actions">
<?php if ($canEdit): ?>
    <a class="button primary-link" href="/ui/reference/company/edit"><?php if ($p['version'] === 0 || $missing !== []): ?>Add or change the details<?php else: ?>Change the details<?php endif; ?></a>
<?php endif; ?>
    <a href="/ui/reference/company/sample.pdf">See how a purchase order will look (PDF)</a>
  </p>
<?php if (!$canEdit): ?>
  <p class="muted"><?= $e($who->rolesPhrase()) ?> can look at the company details but not change them: a reviewer does.</p>
<?php endif; ?>
</section>

<section aria-labelledby="details-h">
  <h2 id="details-h">The details<?php if ($p['version'] > 0): ?> <span class="muted">(version <?= $n($p['version']) ?>)</span><?php endif; ?></h2>
  <dl class="wide company">
    <dt>Legal name</dt>
    <dd><?php if ($show['legal_name'] === ''): ?><span class="muted">not given (prints "[to be confirmed]")</span><?php else: ?><?= $e($show['legal_name']) ?><?php endif; ?></dd>
    <dt>Trading name</dt>
    <dd><?php if ($show['trading_name'] === ''): ?><span class="muted">none</span><?php else: ?><?= $e($show['trading_name']) ?><?php endif; ?></dd>
    <dt>Company number</dt>
    <dd><?php if ($show['company_number'] === ''): ?><span class="muted">not given (prints "[to be confirmed]")</span><?php else: ?><?= $e($show['company_number']) ?><?php endif; ?></dd>
    <dt>VAT</dt>
    <dd><?php if ($show['vat'] === ''): ?><span class="muted">not given (prints "[to be confirmed]")</span><?php else: ?><?= $e($show['vat']) ?><?php endif; ?></dd>
    <dt>Registered address</dt>
    <dd class="pre"><?php if ($show['address'] === ''): ?><span class="muted">not given (prints "[to be confirmed]")</span><?php else: ?><?= $e($show['address']) ?><?php endif; ?></dd>
    <dt>Purchasing phone</dt>
    <dd><?php if ($show['phone'] === ''): ?><span class="muted">not given (prints "[to be confirmed]")</span><?php else: ?><?= $e($show['phone']) ?><?php endif; ?></dd>
    <dt>Purchasing e-mail</dt>
    <dd><?php if ($show['email'] === ''): ?><span class="muted">not given (prints "[to be confirmed]")</span><?php else: ?><?= $e($show['email']) ?><?php endif; ?></dd>
    <dt>Delivery address</dt>
    <dd class="pre"><?php if ($show['delivery_address'] === ''): ?><span class="muted">not given (prints "[to be confirmed]")</span><?php else: ?><?= $e($show['delivery_address']) ?><?php endif; ?></dd>
<?php if ($p['version'] > 0): ?>
    <dt>Last saved</dt>
    <dd><?= $dt($p['saved_at']) ?> UTC by <?= $e($savedLabel) ?></dd>
<?php endif; ?>
  </dl>
  <p class="muted">We never keep bank details here.</p>
</section>

<?php if ($reviews !== []): ?>
<section aria-labelledby="reviews-h">
  <h2 id="reviews-h">Changes checked by another reviewer</h2>
  <p class="muted">When a person confirms their own change of the legal name, company number, VAT, purchasing e-mail or delivery address, another
    reviewer is asked to check it. It stops nothing; rejecting it makes the details unconfirmed again and flags the orders that carry it.</p>
<?php foreach ($reviews as $t): ?>
  <article class="card review<?php if ($t['open']): ?> waiting<?php endif; ?>">
    <p><strong>Version <?= $n($t['subject_id']) ?></strong>, confirmed by <?= $e($t['opened_by_name'] ?? $t['opened_actor']) ?> on <?= $dt($t['opened_at']) ?> UTC:
<?php if ($t['open']): ?>
      waiting for a check<?php if (!$t['alone']): ?> (due <?= $dt($t['due_at']) ?> UTC<?php if ($t['overdue']): ?>, <span class="tag bad">overdue</span><?php endif; ?>)<?php endif; ?>.
<?php else: ?>
      <?= $e($t['state']) ?> by <?= $e($t['decided_by_name']) ?> on <?= $dt($t['decided_at']) ?> UTC<?php if ($t['decision_note'] !== null): ?>: <?= $e($t['decision_note']) ?><?php endif; ?>.
<?php endif; ?>
    </p>
<?php if ($t['changes'] !== []): ?>
    <p class="muted">What changed since the details were last confirmed<?php if ($t['baseline_version'] !== null): ?> (version <?= $n($t['baseline_version']) ?>)<?php endif; ?>:</p>
    <ul class="plain changes">
<?php foreach ($t['changes'] as $c): ?>
      <li<?php if ($c['watched']): ?> class="watched"<?php endif; ?>><span class="field"><?= $e(ucfirst($c['label'])) ?></span>
        <span class="was">Was: <span class="pre"><?php if ($c['before'] === ''): ?><span class="muted">(empty)</span><?php else: ?><?= $e($c['before']) ?><?php endif; ?></span></span>
        <span class="now">Now: <span class="pre"><?php if ($c['after'] === ''): ?><span class="muted">(empty)</span><?php else: ?><?= $e($c['after']) ?><?php endif; ?></span></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($t['open']): ?>
<?php if ($t['may_decide']): ?>
    <form class="inline" method="post" action="<?= $u('/ui/reference/company/reviews/' . $t['id'] . '/approve') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label>Note (optional) <input type="text" name="note" maxlength="500"></label>
      <button type="submit" class="primary">The change is right</button>
    </form>
    <form class="inline" method="post" action="<?= $u('/ui/reference/company/reviews/' . $t['id'] . '/reject') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <label>What is wrong (required) <input type="text" name="note" minlength="3" maxlength="500" required></label>
      <button type="submit">Reject the change</button>
    </form>
<?php elseif ($t['refusal'] !== null): ?>
    <p class="note read-only"><?= $e($t['refusal']) ?></p>
<?php endif; ?>
<?php if ($t['alone']): ?>
    <p class="muted">Nobody else holds the reviewer role yet, so this check stays open. It stops nothing; once a second person holds the reviewer
      role (People and roles), they can close it.</p>
<?php endif; ?>
<?php endif; ?>
  </article>
<?php endforeach; ?>
</section>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h">History</h2>
<?php if ($history === []): ?>
  <p class="muted">No details were saved yet.</p>
<?php else: ?>
  <ol class="versions" reversed>
<?php foreach ($history as $h): ?>
    <li>
      <p><strong>Version <?= $n($h['version']) ?></strong> &middot;
<?php if ($h['kind'] === 'seed'): ?>
        copied from the old settings
<?php elseif ($h['kind'] === 'confirm'): ?>
        confirmed by <?= $e($h['confirmed_label']) ?>
<?php elseif ($h['kind'] === 'unconfirm'): ?>
        made unconfirmed by <?= $e($h['saved_label']) ?>
<?php else: ?>
        changed by <?= $e($h['saved_label']) ?>
<?php endif; ?>
        on <?= $dt($h['saved_at']) ?> UTC
        <?php if ($h['confirmed']): ?><span class="tag ok">confirmed</span><?php else: ?><span class="tag">not confirmed</span><?php endif; ?>
<?php if ($h['review'] !== null): ?>
        <span class="tag<?php if ($h['review']['state'] === 'rejected'): ?> bad<?php endif; ?>">check: <?= $e($h['review']['state']) ?></span>
<?php endif; ?></p>
<?php if ($h['reason'] !== null): ?>
      <p class="muted">Why: <?= $e($h['reason']) ?></p>
<?php endif; ?>
<?php if ($h['changes'] !== []): ?>
<?php if ($h['kind'] === 'confirm' || $h['kind'] === 'unconfirm'): ?>
      <p class="error">This <?php if ($h['kind'] === 'confirm'): ?>confirmation<?php else: ?>version<?php endif; ?> changed details, which this screen never
        does: tell the person who looks after CW.</p>
<?php endif; ?>
      <ul class="plain changes">
<?php foreach ($h['changes'] as $c): ?>
        <li><span class="field"><?= $e(ucfirst($c['label'])) ?></span>
          <span class="was">Was: <span class="pre"><?php if ($c['before'] === ''): ?><span class="muted">(empty)</span><?php else: ?><?= $e($c['before']) ?><?php endif; ?></span></span>
          <span class="now">Now: <span class="pre"><?php if ($c['after'] === ''): ?><span class="muted">(empty)</span><?php else: ?><?= $e($c['after']) ?><?php endif; ?></span></span></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
