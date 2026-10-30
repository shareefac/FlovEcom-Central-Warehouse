<?php
// One supplier, the record page (the owner's approved design of 29 Oct 2026, PO_View; docs/decisions.md W9.3): its name with its status as
// a solid block, the action bar at the head's right (supplier_bar: the next step in the accent, the rest under More), a reviewer's or a
// second person's box when one waits, whether we can order from it, the figures strip (products, main supplier of, no price yet, the next
// background check), then its four tabs: Details (the paper card: the supplier's block beside its terms, the background check and duty
// stamps, its arrivals settings), Items and packs, Orders, Invoices; then its approvals and checks. Every form posts where it did.
?>
<p class="crumbs"><a href="/ui/purchasing/suppliers"><?= $word('MENU', 'suppliers') ?></a></p>
<?php $bar = $partial('supplier_bar', ['s' => $s, 'canEdit' => $canEdit, 'canOrder' => $canOrder, 'canRequest' => $canRequest, 'canDeactivate' => $canDeactivate,
    'missing' => $missing, 'secondPerson' => $secondPerson, 'maxMb' => $maxMb, 'errorCode' => $errorCode]); ?>
<div class="doc-head">
<?= $head((string) $title, intro: 'supplier', side: $bar, meta: $stateChip('SUPPLIER_STATUS', (string) $s['status']) . ($alone !== null && $alone['checked'] === null ? ' ' . $chip('needs', \CW\Ui\Words::SUPPLIERS['alone_chip']) : '')) ?>
</div>
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
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/check-alone') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="version" value="<?= $e($s['version']) ?>">
    <label><?= $word('SUPPLIER', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="btn primary"><?= $word('SUPPLIER', 'alone_ok') ?></button>
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
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $t['id'] . '/approve') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('SUPPLIER', 'note_optional') ?> <input type="text" name="note" maxlength="500"></label>
    <button type="submit" class="btn primary"><?= $e($t['ok']) ?></button>
  </form>
  <form class="inline" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $t['id'] . '/reject') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label><?= $word('SUPPLIER', 'why_not_ok') ?> <input type="text" name="note" minlength="3" maxlength="500" required></label>
    <button class="btn" type="submit"><?= $word('SUPPLIER', 'not_ok') ?></button>
  </form>
<?php else: ?>
  <p class="note read-only"><?= $e($t['refusal']) ?></p>
<?php endif; ?>
<?php if ($t['may_withdraw']): ?>
  <form class="quick" method="post" action="<?= $u('/ui/purchasing/suppliers/tasks/' . $t['id'] . '/withdraw') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button type="submit" class="btn lg"><span class="btn-title"><?= $word('SUPPLIER', 'withdraw') ?></span> <span class="sub"><?= $word('SUPPLIER', 'withdraw_does') ?></span></button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endforeach; ?>

<section id="activation" aria-labelledby="activation-h">
  <h2 id="activation-h" class="visually-hidden"><?= $word('SUPPLIER', 'can_order') ?></h2>
<?php if ($s['status'] === 'active'): ?>
  <p><?= $say('SUPPLIER', 'yes_since', \CW\Ui\Html::day((string) $s['approved_at']), (string) ($people['approved'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?></p>
<?php elseif ($s['status'] === 'pending_approval'): ?>
  <p><strong><?= $word('SUPPLIER', 'waiting') ?></strong> <?= $word('SUPPLIER', 'waiting_locked') ?></p>
<?php if (!$secondPerson && ($open['activation']['may_withdraw'] ?? false)): ?>
  <p><?= $word('SUPPLIER', 'waiting_rule_off') ?></p>
<?php endif; ?>
<?php elseif ($s['status'] === 'inactive'): ?>
  <p><?php if ($s['deactivated_at'] !== null): ?><?= $say('SUPPLIER', 'stopped', \CW\Ui\Html::day((string) $s['deactivated_at']), (string) ($people['deactivated'] ?? \CW\Ui\Words::ANOMALIES['set_up']), (string) $s['deactivate_reason']) ?><?php else: ?><?= $word('SUPPLIER', 'stopped_plain') ?><?php endif; ?> <?= $word('SUPPLIER', $secondPerson ? 'stopped_again' : 'stopped_again_alone') ?></p>
<?php else: ?>
  <p><?= $word('SUPPLIER', $secondPerson ? 'draft' : 'draft_alone') ?><?php if ($canRequest): ?> <?= $word('SUPPLIER', $secondPerson ? 'draft_todo' : 'draft_todo_alone') ?><?php endif; ?></p>
<?php endif; ?>
<?php if ($canRequest && $missing !== []): ?>
  <p><strong><?= $say('SUPPLIER', 'missing', $missingText) ?></strong></p>
<?php elseif ($canRequest): ?>
  <p class="muted"><?= $word('SUPPLIER', $secondPerson ? 'ask_does' : 'ask_alone_does') ?></p>
<?php endif; ?>
</section>

<section class="doc-progress rp-figs" aria-label="<?= $word('SUPPLIER', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('SUPPLIER', 'fig_products') ?></p><p class="doc-step-big"><?= $n($items['active']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('SUPPLIER', 'fig_main') ?></p><p class="doc-step-big"><?= $n($items['preferred']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('SUPPLIER', 'fig_no_price') ?></p><p class="doc-step-big"><?= $n($items['no_price']) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('SUPPLIER', 'dd_next') ?></p><p class="doc-step-big"><?php if ($s['dd_next_review_on'] !== null): ?><?= $day($s['dd_next_review_on']) ?><?php else: ?><?= $word('SUPPLIER', 'not_yet') ?><?php endif; ?></p><?php if ($ddOverdue): ?><p class="step-sub"><?= $word('SUPPLIERS', 'overdue') ?></p><?php endif; ?></div>
</section>

<nav class="tabs record-tabs" id="record-tabs" aria-label="<?= $e($title) ?>">
<?php foreach ($tabs as $t): ?>
  <a class="tab"<?php if ($t['current']): ?> aria-current="page"<?php endif; ?><?php if (!$t['current']): ?> href="<?= $u((string) $t['href']) ?>"<?php endif; ?>><span><?= $e($t['label']) ?></span></a>
<?php endforeach; ?>
</nav>

<?php if ($tab === 'details'): ?>
<article class="doc-view" aria-labelledby="details-h">
  <h2 id="details-h" class="doc-label"><?= $word('SUPPLIER', 'details') ?></h2>
<?= $partial('doc_party', ['party' => $party, 'facts' => $facts]) ?>
<?php if ($s['contacts_note'] !== null && $s['contacts_note'] !== ''): ?>
  <p class="doc-label"><?= $word('SUPPLIER', 'contacts_note') ?></p>
  <p class="pre"><?= $e($s['contacts_note']) ?></p>
<?php endif; ?>
<?php if ($s['notes'] !== null && $s['notes'] !== ''): ?>
  <p class="doc-label"><?= $word('SUPPLIER', 'notes') ?></p>
  <p class="pre"><?= $e($s['notes']) ?></p>
<?php endif; ?>
  <div class="doc-meta">
    <section aria-labelledby="dd-h">
      <h3 id="dd-h" class="doc-label"><?= $word('SUPPLIER', 'dd') ?></h3>
      <dl class="doc-facts">
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
    </section>
    <section aria-labelledby="route-h">
      <h3 id="route-h" class="doc-label"><?= $word('SUPPLIER', 'route') ?></h3>
      <dl class="doc-facts">
        <dt><?= $word('SUPPLIER', 'abroad') ?></dt><dd><?php if ((int) $s['is_overseas'] === 1): ?><?= $word('SUPPLIER', 'yes') ?><?php else: ?><?= $word('SUPPLIER', 'no_uk') ?><?php endif; ?></dd>
<?php if ((int) $s['is_overseas'] === 1 || $s['import_route'] !== null): ?>
        <dt><?= $word('SUPPLIER', 'route_how') ?></dt><dd class="pre"><?= $e($s['import_route']) ?></dd>
        <dt><?= $word('SUPPLIER', 'route_file') ?></dt><dd><?php if ($files['import_route'] !== null): ?><a href="<?= $u('/ui/files/' . $files['import_route']['id']) ?>"><?= $e($files['import_route']['original_name']) ?></a><?php else: ?><span class="muted"><?= $word('SUPPLIER', 'none_uploaded') ?></span><?php endif; ?></dd>
        <dt><?= $word('SUPPLIER', 'route_ok') ?></dt><dd><?php if ($s['import_route_approved_at'] !== null): ?><?= $say('SUPPLIER', 'route_ok_line', \CW\Ui\Html::when((string) $s['import_route_approved_at']), (string) ($people['route'] ?? \CW\Ui\Words::ANOMALIES['set_up'])) ?><?php else: ?><?= $chip('needs', \CW\Ui\Words::SUPPLIER['not_yet']) ?><?php endif; ?></dd>
<?php endif; ?>
      </dl>
    </section>
  </div>
<?php if ($emptyText !== ''): ?>
  <p class="muted"><?= $say('SUPPLIER', 'not_filled', $emptyText) ?></p>
<?php endif; ?>
  <p class="muted"><?= $say('SUPPLIER', 'created', \CW\Ui\Html::when((string) $s['created_at']), (string) $people['created']) ?> · <?= $say('SUPPLIER', 'last_changed', \CW\Ui\Html::when((string) $s['updated_at']), (string) $people['updated']) ?></p>
</article>

<section class="card" aria-labelledby="arrivals-h" id="arrivals">
  <h2 id="arrivals-h"><?= $word('ARRIVALS', 'supplier_title') ?></h2>
<?php if (!$arrivals['own']): ?>
  <p class="muted"><?= $word('ARRIVALS', 'own_none') ?></p>
<?php endif; ?>
<?php if ($arrivals['canEdit']): ?>
  <form class="record" method="post" action="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/arrivals') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <label class="choice"><input type="checkbox" name="sell_before" value="1"<?php if ($arrivals['sell_before']): ?> checked<?php endif; ?>> <?= $word('ARRIVALS', 'sell_before') ?></label>
    <label><?= $word('ARRIVALS', 'default_mode') ?>
      <select name="default_mode">
<?php foreach (\CW\Ui\Words::RECEIVE_MODE as $code => $label): ?>
        <option value="<?= $e($code) ?>"<?php if ($arrivals['mode'] === $code): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <p class="actions"><button class="btn" type="submit"><?= $word('ARRIVALS', 'save') ?></button></p>
  </form>
<?php else: ?>
  <dl class="doc-facts">
    <dt><?= $word('ARRIVALS', 'sell_before') ?></dt><dd><?php if ($arrivals['sell_before']): ?><?= $word('ARRIVALS', 'yes') ?><?php else: ?><?= $word('ARRIVALS', 'no') ?><?php endif; ?></dd>
    <dt><?= $word('ARRIVALS', 'default_mode') ?></dt><dd><?= $e(\CW\Ui\Words::RECEIVE_MODE[$arrivals['mode']] ?? '') ?></dd>
  </dl>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'items'): ?>
<section class="doc-related" aria-labelledby="items-h">
  <div class="board-head"><h2 id="items-h" class="board-sub"><?= $word('SUPPLIER', 'tab_items') ?></h2><a href="<?= $u('/ui/purchasing/suppliers/' . $s['id'] . '/items') ?>"><?= $word('SUPPLIER', 'products_open') ?></a></div>
  <p class="muted"><?= $say('SUPPLIER', 'products_line', $items['active'], $items['preferred']) ?></p>
<?= $partial('supplier_items_board', ['rows' => $itemRows, 'canManage' => $canManage, 'supplierId' => (int) $s['id'], 'cut' => $itemsCut]) ?>
</section>
<?php endif; ?>

<?php if ($tab === 'orders' && $recentPos !== null): ?>
<section class="doc-related" aria-labelledby="pos-h">
  <div class="board-head"><h2 id="pos-h" class="board-sub"><?= $word('SUPPLIER', 'orders') ?></h2><a href="<?= $u('/ui/purchasing/orders', ['supplier' => $s['id']]) ?>"><?= $word('SUPPLIER', 'all_orders') ?></a></div>
<?php if ($recentPos === []): ?>
  <p class="muted"><?= $word('SUPPLIER', 'no_orders') ?></p>
<?php else: ?>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list orders">
    <thead>
      <tr><th scope="col" class="c-item"><?= $word('SUPPLIER', 'order') ?></th><th scope="col" class="c-status"><?= $word('SUPPLIER', 'status') ?></th><th scope="col"><?= $word('SUPPLIER', 'dated') ?></th>
        <th scope="col"><?= $word('SUPPLIER', 'expected') ?></th><th scope="col" class="num"><?= $word('SUPPLIER', 'total') ?></th><th scope="col"><?= $word('SUPPLIER', 'check') ?></th></tr>
    </thead>
    <tbody>
<?php foreach ($recentPos as $p): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/purchasing/orders/' . $p['id']) ?>"><?= $e($p['number_line']) ?></a></th>
        <td class="c-status doc-st <?= $e($p['state_tone']) ?>"><?= $e($p['state_word']) ?></td>
        <td data-label="<?= $word('SUPPLIER', 'dated') ?>"><?php if ($p['doc_date'] !== null): ?><?= $day($p['doc_date']) ?><?php endif; ?></td>
        <td data-label="<?= $word('SUPPLIER', 'expected') ?>"><?php if ($p['expected_date'] !== null): ?><?= $day($p['expected_date']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('SUPPLIER', 'total') ?>"><?= $e($p['total']) ?></td>
        <td data-label="<?= $word('SUPPLIER', 'check') ?>"><?php if ($p['check'] !== null): ?><span class="tag <?= $e($p['check_tone']) ?>"><?= $e($p['check']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($tab === 'invoices' && $invoiceRows !== null): ?>
<section class="doc-related" aria-labelledby="invoices-h">
  <div class="board-head"><h2 id="invoices-h" class="board-sub"><?= $word('SUPPLIER', 'invoices') ?></h2><a href="<?= $u('/ui/purchasing/invoices', ['supplier' => $s['id'], 'show' => 'all']) ?>"><?= $word('SUPPLIER', 'all_invoices') ?></a></div>
<?php if ($invoiceRows === []): ?>
  <p class="muted"><?= $word('SUPPLIER', 'no_invoices') ?></p>
<?php else: ?>
  <p class="muted"><?= $say('SUPPLIER', 'invoices_latest', $tabRows) ?></p>
  <div class="table-wrap doc-board info">
  <table class="stack list board doc-list">
    <thead>
      <tr><th scope="col" class="c-item"><?= $word('INVOICE_KIND', 'col') ?></th><th scope="col" class="c-status"><?= $word('DOC_LIST', 'status') ?></th><th scope="col"><?= $word('DOC_LIST', 'match') ?></th>
        <th scope="col"><?= $word('DOC_LIST', 'due') ?></th><th scope="col" class="num"><?= $word('DOC_LIST', 'total') ?></th><th scope="col" class="num"><?= $word('DOC_LIST', 'balance') ?></th></tr>
    </thead>
    <tbody>
<?php foreach ($invoiceRows as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u($r['href']) ?>"><?= $e($r['title']) ?></a><span class="o-sub"><?= $e($r['date']) ?><?php if ($r['refs'] !== ''): ?> · <?= $e($r['refs']) ?><?php endif; ?></span></th>
        <td class="c-status doc-st <?= $e($r['cell_tone']) ?>"><?= $e($r['cell_word']) ?></td>
        <td data-label="<?= $word('DOC_LIST', 'match') ?>"><?php if ($r['tag'] !== null): ?><span class="tag <?= $e($r['tag']['tone']) ?>"><?= $e($r['tag']['text']) ?></span><?php endif; ?></td>
        <td data-label="<?= $word('DOC_LIST', 'due') ?>"<?php if ($r['late']): ?> class="late"<?php endif; ?>><?= $e($r['due']) ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'total') ?>"><?php if ($r['gross'] !== null): ?><?= $money($r['gross']) ?><?php endif; ?></td>
        <td class="num" data-label="<?= $word('DOC_LIST', 'balance') ?>"><?= $e($r['balance'] ?? '') ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
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
