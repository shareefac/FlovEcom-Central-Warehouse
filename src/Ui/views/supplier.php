<p class="crumbs"><a href="/ui/purchasing/suppliers"><?= $word('MENU', 'suppliers') ?></a></p>
<h1><?= $e($title) ?></h1>
<p class="eyebrow"><?= $stateChip('SUPPLIER_STATUS', (string) $s['status']) ?></p>
<?= $intro('supplier') ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($ddOverdue): ?>
<p class="note"><?= $say('SUPPLIER', 'dd_overdue', \CW\Ui\Html::day((string) $s['dd_next_review_on'])) ?></p>
<?php endif; ?>
<?php if ($routeUnapproved && $s['status'] === 'active'): ?>
<p class="note"><?= $word('SUPPLIER', 'route_unapproved') ?></p>
<?php endif; ?>
<?php if ($s['last_decision_note'] !== null): ?>
<p class="muted"><?= $say('SUPPLIER', 'last_note', (string) $s['last_decision_note']) ?></p>
<?php endif; ?>

<?php if ($alone !== null): ?>
<section class="card decide-box<?php if ($alone['may_check']): ?> waiting<?php endif; ?>" id="alone" aria-labelledby="alone-h">
  <div class="head-help">
    <h2 id="alone-h"><?= $word('SUPPLIER', 'alone_title') ?> <?php if ($alone['checked'] === null): ?><?= $chip('needs', \CW\Ui\Words::SUPPLIERS['alone_chip']) ?><?php else: ?><?= $chip('done', \CW\Ui\Words::SUPPLIER['alone_done']) ?><?php endif; ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $e($alone['text']) ?></p>
<?php if ($alone['checked'] !== null): ?>
  <p><?= $e($alone['checked']) ?></p>
<?php elseif ($alone['may_check']): ?>
  <p class="muted"><?= $word('SUPPLIER', 'alone_does') ?></p>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/check-alone') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
    <label><?= $word('SUPPLIER', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary"><?= $word('SUPPLIER', 'alone_ok') ?></button>
  </form>
<?php elseif ($alone['refusal'] !== null): ?>
  <p class="note read-only"><?= $e($alone['refusal']) ?></p>
<?php endif; ?>
</section>
<?php endif; ?>

<?php foreach (['activation', 'route', 'review'] as $slot): ?>
<?php if ($open[$slot] !== null): ?>
<?php $t = $open[$slot]; ?>
<section class="card decide-box<?php if ($t['may_decide']): ?> waiting<?php endif; ?>" aria-labelledby="task-<?= $e($slot) ?>-h">
  <div class="head-help">
    <h2 id="task-<?= $e($slot) ?>-h"><?= $e($t['title']) ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $e($t['text']) ?> <?= $e($t['check_by']) ?><?php if ($t['overdue']): ?> <?= $chip('blocked', \CW\Ui\Words::SUPPLIER['late']) ?><?php endif; ?></p>
<?php if ($t['may_decide']): ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done">
        <dt><?= $e($t['ok']) ?></dt>
        <dd><?= $e($t['ok_does']) ?></dd>
      </div>
      <div class="answer blocked">
        <dt><?= $word('SUPPLIER', 'not_ok') ?></dt>
        <dd><?= $e($t['not_ok_does']) ?></dd>
      </div>
    </dl>
  </div>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $t['id'] . '/approve') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('SUPPLIER', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="primary"><?= $e($t['ok']) ?></button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $t['id'] . '/reject') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('SUPPLIER', 'why_not_ok') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button type="submit"><?= $word('SUPPLIER', 'not_ok') ?></button>
  </form>
<?php else: ?>
  <p class="note read-only"><?= $e($t['refusal']) ?></p>
<?php endif; ?>
<?php if ($t['may_withdraw']): ?>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $t['id'] . '/withdraw') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn big secondary"><span class="btn-title"><?= $word('SUPPLIER', 'withdraw') ?></span> <span class="sub"><?= $word('SUPPLIER', 'withdraw_does') ?></span></button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endforeach; ?>

<section class="card box" aria-labelledby="activation-h">
  <h2 id="activation-h"><?= $word('SUPPLIER', 'can_order') ?></h2>
<?php if ($s['status'] === 'active'): ?>
  <p><?= $say('SUPPLIER', 'yes_since', \CW\Ui\Html::day((string) $s['approved_at']), (string) ($people['approved'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?></p>
<?php if ($canDeactivate): ?>
  <details class="action">
    <summary><?= $word('SUPPLIER', 'stop') ?></summary>
    <p class="hint"><?= $word('SUPPLIER', 'stop_does') ?></p>
    <form class="record" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/deactivate') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
      <label><?= $word('SUPPLIER', 'stop_why') ?> <input type="text" name="reason" minlength="3" maxlength="500" required></label>
      <p class="actions"><button type="submit" class="danger"><?= $word('SUPPLIER', 'stop_button') ?></button></p>
    </form>
  </details>
<?php endif; ?>
<?php elseif ($s['status'] === 'pending_approval'): ?>
  <p><strong><?= $word('SUPPLIER', 'waiting') ?></strong> <?= $word('SUPPLIER', 'waiting_locked') ?></p>
<?php else: ?>
<?php if ($s['status'] === 'inactive'): ?>
  <p><?php if ($s['deactivated_at'] !== null): ?><?= $say('SUPPLIER', 'stopped', \CW\Ui\Html::day((string) $s['deactivated_at']), (string) ($people['deactivated'] ?? \CW\Ui\Words::ANOMALIES['set_up']), (string) $s['deactivate_reason']) ?><?php else: ?><?= $word('SUPPLIER', 'stopped_plain') ?><?php endif; ?> <?= $word('SUPPLIER', 'stopped_again') ?></p>
<?php else: ?>
  <p><?= $word('SUPPLIER', 'draft') ?><?php if ($canRequest): ?> <?= $word('SUPPLIER', 'draft_todo') ?><?php endif; ?></p>
<?php endif; ?>
<?php if ($canRequest && $missing !== []): ?>
  <p class="note"><?= $say('SUPPLIER', 'missing', $missingText) ?></p>
<?php elseif ($canRequest): ?>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/request-activation') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
    <button type="submit" class="primary btn big"><span class="btn-title"><?php if ($s['approved_at'] === null): ?><?= $word('SUPPLIER', 'ask') ?><?php else: ?><?= $word('SUPPLIER', 'ask_again') ?><?php endif; ?></span> <span class="sub"><?= $word('SUPPLIER', 'ask_does') ?></span></button>
  </form>
<?php endif; ?>
<?php endif; ?>
<?php if ($canEdit): ?>
  <p class="actions"><a class="btn secondary" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/edit') ?>"><?= $word('SUPPLIER', 'change') ?></a></p>
<?php endif; ?>
</section>

<div class="cols">
  <section class="card" aria-labelledby="details-h">
    <h2 id="details-h"><?= $word('SUPPLIER', 'details') ?></h2>
    <dl>
<?php if ($s['legal_name'] !== null && $s['legal_name'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'legal_name') ?></dt><dd><?= $e($s['legal_name']) ?></dd>
<?php endif; ?>
<?php if ($s['company_number'] !== null && $s['company_number'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'company_number') ?></dt><dd><?= $e($s['company_number']) ?></dd>
<?php endif; ?>
<?php if ($s['vat_number'] !== null && $s['vat_number'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'vat_number') ?></dt><dd><?= $e($s['vat_number']) ?></dd>
<?php endif; ?>
      <dt><?= $word('SUPPLIER', 'address') ?></dt><dd><?= $e($s['address_line1']) ?><?php if ($s['address_line2'] !== null): ?>, <?= $e($s['address_line2']) ?><?php endif; ?><?php if ($s['city'] !== null): ?>, <?= $e($s['city']) ?><?php endif; ?> <?= $e($s['postcode']) ?> <?= $e($s['country']) ?></dd>
<?php if ($s['contact_name'] !== null && $s['contact_name'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'contact') ?></dt><dd><?= $e($s['contact_name']) ?></dd>
<?php endif; ?>
      <dt><?= $word('SUPPLIER', 'email') ?></dt><dd><?= $e($s['email']) ?></dd>
<?php if ($s['phone'] !== null && $s['phone'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'phone') ?></dt><dd><?= $e($s['phone']) ?></dd>
<?php endif; ?>
<?php if ($s['contacts_note'] !== null && $s['contacts_note'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'contacts_note') ?></dt><dd class="pre"><?= $e($s['contacts_note']) ?></dd>
<?php endif; ?>
<?php if ($s['payment_terms'] !== null && $s['payment_terms'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'payment') ?></dt><dd><?php if ($s['payment_terms_days'] !== null): ?><?= $say('SUPPLIER', 'payment_days', (string) $s['payment_terms'], (int) $s['payment_terms_days']) ?><?php else: ?><?= $e($s['payment_terms']) ?><?php endif; ?></dd>
<?php endif; ?>
<?php if ($s['default_lead_days'] !== null): ?>
      <dt><?= $word('SUPPLIER', 'lead') ?></dt><dd><?= $n($s['default_lead_days']) ?></dd>
<?php endif; ?>
<?php if ($s['review_days'] !== null): ?>
      <dt><?= $word('SUPPLIER', 'every') ?></dt><dd><?= $n($s['review_days']) ?></dd>
<?php endif; ?>
<?php if ($s['min_order_value'] !== null): ?>
      <dt><?= $word('SUPPLIER', 'min_order') ?></dt><dd><?= $money($s['min_order_value']) ?></dd>
<?php endif; ?>
      <dt><?= $word('SUPPLIER', 'vat') ?></dt><dd><?= $e($s['default_vat_code']) ?></dd>
      <dt><?= $word('SUPPLIER', 'currency') ?></dt><dd><?= $e($s['currency']) ?></dd>
<?php if ($s['erp_name'] !== null): ?>
      <dt><?= $word('SUPPLIER', 'erp') ?></dt><dd><?= $e($s['erp_name']) ?></dd>
<?php endif; ?>
<?php if ($s['notes'] !== null && $s['notes'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'notes') ?></dt><dd class="pre"><?= $e($s['notes']) ?></dd>
<?php endif; ?>
    </dl>
<?php if ($emptyText !== ''): ?>
    <p class="hint"><?= $say('SUPPLIER', 'not_filled', $emptyText) ?></p>
<?php endif; ?>
    <p class="hint"><?= $say('SUPPLIER', 'created', \CW\Ui\Html::when((string) $s['created_at']), (string) $people['created']) ?> · <?= $say('SUPPLIER', 'last_changed', \CW\Ui\Html::when((string) $s['updated_at']), (string) $people['updated']) ?></p>
  </section>

  <section class="card" aria-labelledby="dd-h">
    <h2 id="dd-h"><?= $word('SUPPLIER', 'dd') ?></h2>
    <dl>
      <dt><?= $word('SUPPLIER', 'dd_on') ?></dt><dd><?php if ($s['dd_checked_on'] !== null): ?><?= $day($s['dd_checked_on']) ?><?php else: ?><span class="muted"><?= $word('SUPPLIER', 'not_yet') ?></span><?php endif; ?></dd>
<?php if ($people['dd'] !== null): ?>
      <dt><?= $word('SUPPLIER', 'dd_by') ?></dt><dd><?= $e($people['dd']) ?></dd>
<?php endif; ?>
<?php if ($s['dd_evidence'] !== null && $s['dd_evidence'] !== ''): ?>
      <dt><?= $word('SUPPLIER', 'dd_what') ?></dt><dd class="pre"><?= $e($s['dd_evidence']) ?></dd>
<?php endif; ?>
      <dt><?= $word('SUPPLIER', 'dd_file') ?></dt><dd><?php if ($files['dd'] !== null): ?><a href="<?= $u('/ui/files/' . $files['dd']['id']) ?>"><?= $e($files['dd']['original_name']) ?></a> <span class="muted">(<?= $e($files['dd']['size']) ?>)</span><?php else: ?><span class="muted"><?= $word('SUPPLIER', 'none_uploaded') ?></span><?php endif; ?></dd>
      <dt><?= $word('SUPPLIER', 'dd_next') ?></dt><dd><?php if ($s['dd_next_review_on'] !== null): ?><?= $day($s['dd_next_review_on']) ?><?php endif; ?><?php if ($ddOverdue): ?> <?= $chip('blocked', \CW\Ui\Words::SUPPLIERS['overdue']) ?><?php endif; ?></dd>
    </dl>
    <h3><?= $word('SUPPLIER', 'route') ?></h3>
    <dl>
      <dt><?= $word('SUPPLIER', 'abroad') ?></dt><dd><?php if ((int) $s['is_overseas'] === 1): ?><?= $word('SUPPLIER', 'yes') ?><?php else: ?><?= $word('SUPPLIER', 'no_uk') ?><?php endif; ?></dd>
<?php if ((int) $s['is_overseas'] === 1 || $s['import_route'] !== null): ?>
      <dt><?= $word('SUPPLIER', 'route_how') ?></dt><dd class="pre"><?= $e($s['import_route']) ?></dd>
      <dt><?= $word('SUPPLIER', 'route_file') ?></dt><dd><?php if ($files['import_route'] !== null): ?><a href="<?= $u('/ui/files/' . $files['import_route']['id']) ?>"><?= $e($files['import_route']['original_name']) ?></a><?php else: ?><span class="muted"><?= $word('SUPPLIER', 'none_uploaded') ?></span><?php endif; ?></dd>
      <dt><?= $word('SUPPLIER', 'route_ok') ?></dt><dd><?php if ($s['import_route_approved_at'] !== null): ?><?= $say('SUPPLIER', 'route_ok_line', \CW\Ui\Html::when((string) $s['import_route_approved_at']), (string) ($people['route'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?><?php else: ?><?= $chip('needs', \CW\Ui\Words::SUPPLIER['not_yet']) ?><?php endif; ?></dd>
<?php endif; ?>
    </dl>
<?php if ($canEdit): ?>
    <details class="action">
      <summary><?= $word('SUPPLIER', 'upload') ?></summary>
      <form class="upload" method="post" enctype="multipart/form-data" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/evidence') ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
        <label><?= $word('SUPPLIER', 'upload_of') ?>
          <select name="kind">
            <option value="dd"><?= $word('SUPPLIER', 'upload_dd') ?></option>
            <option value="import_route"><?= $word('SUPPLIER', 'upload_route') ?></option>
          </select>
        </label>
        <label><?= $say('SUPPLIER', 'upload_file', $maxMb) ?> <input type="file" name="file" required></label>
        <p class="actions"><button type="submit"><?= $word('SUPPLIER', 'upload_button') ?></button></p>
      </form>
    </details>
<?php endif; ?>
  </section>
</div>

<section aria-labelledby="items-h">
  <h2 id="items-h"><?= $word('SUPPLIER', 'products') ?></h2>
  <p><?= $say('SUPPLIER', 'products_line', $items['active'], $items['preferred']) ?><?php if ($items['no_price'] > 0): ?> <?= $say('SUPPLIER', 'products_no_price', $items['no_price']) ?><?php endif; ?></p>
  <p class="actions"><a class="btn secondary" href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>"><?= $word('SUPPLIER', 'products_open') ?></a><?php if ($canManage): ?>
    <a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items/new') ?>"><?= $word('SUPPLIER', 'products_add') ?></a><?php endif; ?></p>
</section>

<?php if ($recentPos !== null): ?>
<section aria-labelledby="pos-h">
  <h2 id="pos-h"><?= $word('SUPPLIER', 'orders') ?></h2>
<?php if ($recentPos === []): ?>
  <p class="muted"><?= $word('SUPPLIER', 'no_orders') ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="stack list orders">
    <thead>
      <tr><th scope="col"><?= $word('SUPPLIER', 'order') ?></th><th scope="col"><?= $word('SUPPLIER', 'status') ?></th><th scope="col"><?= $word('SUPPLIER', 'dated') ?></th>
        <th scope="col"><?= $word('SUPPLIER', 'expected') ?></th><th scope="col" class="num"><?= $word('SUPPLIER', 'total') ?></th><th scope="col"><?= $word('SUPPLIER', 'check') ?></th></tr>
    </thead>
    <tbody>
<?php foreach ($recentPos as $p): ?>
      <tr class="<?= $e($p['state_tone']) ?>">
        <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/purchasing/orders/' . $p['id']) ?>"><?= $e($p['number_line']) ?></a></th>
        <td class="c-status"><?= $chip($p['state_tone'], $p['state_word']) ?></td>
        <td data-label="<?= $word('SUPPLIER', 'dated') ?>"><?php if ($p['doc_date'] !== null): ?><?= $day($p['doc_date']) ?><?php endif; ?></td>
        <td data-label="<?= $word('SUPPLIER', 'expected') ?>"><?php if ($p['expected_date'] !== null): ?><?= $day($p['expected_date']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER', 'total') ?>"><?= $e($p['total']) ?></td>
        <td data-label="<?= $word('SUPPLIER', 'check') ?>"><?php if ($p['check'] !== null): ?><?= $chip($p['check_tone'], $p['check']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
  <p class="actions"><a href="<?= $u('/ui/purchasing/orders', ['supplier' => $s['id']]) ?>"><?= $word('SUPPLIER', 'all_orders') ?></a><?php if ($canOrder): ?>
    <a href="<?= $u('/ui/purchasing/orders', ['supplier' => $s['id']]) ?>#new"><?= $word('SUPPLIER', 'new_order') ?></a><?php endif; ?></p>
</section>
<?php endif; ?>

<section aria-labelledby="history-h">
  <h2 id="history-h"><?= $word('SUPPLIER', 'history') ?></h2>
<?php if ($history === []): ?>
  <p class="muted"><?= $word('SUPPLIER', 'no_history') ?></p>
<?php else: ?>
  <ol class="plain checks-history">
<?php foreach ($history as $h): ?>
    <li><p><?= $e($h['asked']) ?></p><p class="muted"><?= $e($h['outcome']) ?></p></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
