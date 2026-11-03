<?php
// Approvals › New products (store_choice, 0058; docs/decisions.md SC9; the owner's drawing "CW asks first" of 3 Nov 2026): each store's choice
// at Add product that waits for a person, as a card: the variant's title (a link to its own page for who reads the matching), why it waits
// (the chip), whose choice it was, the variant beside the item the warehouse found (the same barcode, its stock). For the people who may
// (mapping.decide) "Is it the same product?" and its answers: the item found (the main one, only when one was found), a new item, not stock,
// a note if they like; a pack is said on the variant's own page. A variant set aside or in a spot check has no answers here: its own page
// decides it (DG2). Done: how each one ended, and who answered it.
?>
<?= $head(\CW\Ui\Words::NEW_PRODUCTS['heading'], intro: 'queue_new_products', look: $lookOnly) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?= $partial('queue_filter', ['action' => $action, 'states' => $states, 'state' => $state, 'count' => count($rows), 'more' => $more, 'pageSize' => $pageSize]) ?>
<?php if ($rows === [] && $state === 'open'): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none_open'], \CW\Ui\Words::NEW_PRODUCTS['none_open_text']) ?>
<?php elseif ($rows === []): ?>
<?= $empty(\CW\Ui\Words::QUEUES['none'], \CW\Ui\Words::QUEUES['none_text'], $action, \CW\Ui\Words::QUEUES['show_open']) ?>
<?php else: ?>
<ol class="incident-list queue-list">
<?php foreach ($rows as $r): ?>
  <li>
  <article class="incident card <?= $e($r['tone']) ?>" id="new-products-<?= $e($r['id']) ?>">
    <div class="task-head">
      <h2><?php if ($canOpen): ?><a href="<?= $u($r['link']) ?>"><?= $e($r['title']) ?></a><?php else: ?><?= $e($r['title']) ?><?php endif; ?></h2>
      <?= $chip($r['tone'], $r['why']) ?>
    </div>
    <p><?= $e($r['from']) ?> · <?= $e($r['chosen']) ?></p>
    <div class="doc-meta">
      <section class="doc-party" aria-label="<?= $e($r['new_on']) ?>">
        <p class="doc-label"><?= $e($r['new_on']) ?></p>
        <p class="doc-party-name"><?php if ($r['product'] !== '' && $r['product'] !== $r['title']): ?><?= $e($r['product']) ?> · <?php endif; ?><?= $e($r['title']) ?></p>
        <dl class="doc-facts">
          <dt><?= $word('NEW_PRODUCTS', 'barcode') ?></dt><dd><?= $e($r['barcode']) ?></dd>
        </dl>
      </section>
      <section class="doc-party" aria-label="<?= $word('NEW_PRODUCTS', 'in_cw') ?>">
        <p class="doc-label"><?= $word('NEW_PRODUCTS', 'in_cw') ?></p>
<?php if ($r['found'] !== null): ?>
        <p class="doc-party-name"><a href="<?= $u('/ui/items/' . $r['found']['id']) ?>"><?= $e($r['found']['code']) ?></a> · <?= $e($r['found']['name']) ?></p>
        <dl class="doc-facts">
<?php if ($r['found_barcode'] !== null): ?>
          <dt><?= $word('NEW_PRODUCTS', 'barcode') ?></dt><dd><?= $e($r['found_barcode']) ?></dd>
<?php endif; ?>
          <dt><?= $word('NEW_PRODUCTS', 'on_hand') ?></dt><dd><?= $n($r['found']['on_hand']) ?></dd>
        </dl>
<?php else: ?>
        <p class="muted"><?= $word('NEW_PRODUCTS', 'nothing_found') ?></p>
<?php endif; ?>
<?php if ($r['other'] !== null): ?>
        <p><?php if ($canOpen && $r['other_link'] !== null): ?><a href="<?= $u($r['other_link']) ?>"><?= $e($r['other']) ?></a><?php else: ?><?= $e($r['other']) ?><?php endif; ?></p>
<?php endif; ?>
      </section>
    </div>
    <p class="hint"><?= $e($r['received']) ?></p>
<?php if ($r['closed'] !== null): ?>
    <p class="hint"><?= $e($r['closed']) ?><?php if ($r['item_line'] !== null): ?> <?= $e($r['item_line']) ?><?php endif; ?></p>
<?php if ($r['note_line'] !== null): ?>
    <p class="hint"><?= $e($r['note_line']) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($r['held']): ?>
    <p><?= $word('NEW_PRODUCTS', 'held_line') ?><?php if ($canOpen): ?> <a href="<?= $u($r['link']) ?>"><?= $word('NEW_PRODUCTS', 'open_page') ?></a><?php endif; ?></p>
<?php elseif ($canAct && $r['closed'] === null): ?>
    <form class="record queue-answer" method="post" action="<?= $u($action . '/' . $r['id'] . '/answer') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="version" value="<?= $e($r['map_version']) ?>">
      <input type="hidden" name="proposal" value="<?= $e($r['proposal_id'] ?? '') ?>">
      <input type="hidden" name="seen" value="<?= $e($r['seen'] ?? '') ?>">
      <fieldset>
        <legend><?php if ($r['same'] !== null): ?><?= $word('NEW_PRODUCTS', 'ask_same') ?><?php else: ?><?= $word('NEW_PRODUCTS', 'ask_what') ?><?php endif; ?></legend>
        <label><?= $word('NEW_PRODUCTS', 'note') ?> <input type="text" name="note" maxlength="<?= $e($noteMax) ?>" value="<?= $e($r['typed_note'] ?? '') ?>"></label>
        <p class="actions">
<?php if ($r['same'] !== null): ?>
          <button class="btn primary" type="submit" name="answer" value="same"><?= $e($r['same']) ?></button>
          <button class="btn" type="submit" name="answer" value="new"><?= $word('NEW_PRODUCTS', 'different') ?></button>
<?php else: ?>
          <button class="btn primary" type="submit" name="answer" value="new"><?= $word('NEW_PRODUCTS', 'make_new') ?></button>
<?php endif; ?>
          <button class="btn" type="submit" name="answer" value="none"><?= $word('NEW_PRODUCTS', 'none') ?></button>
<?php if ($canOpen): ?>
          <a class="btn" href="<?= $u($r['link']) ?>"><?= $word('NEW_PRODUCTS', 'pack') ?></a>
<?php endif; ?>
        </p>
      </fieldset>
    </form>
<?php endif; ?>
  </article>
  </li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
