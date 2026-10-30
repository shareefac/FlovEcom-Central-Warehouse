<?php
// A person's actions (docs/decisions.md W13.17; the approved record page, PO_View): in the head, at its right, for whoever manages staff
// (never on their own page): under More, the account's switch (stop signing in, with its tick; or let them sign in again), the sign-in's
// renewal (a new sign-up sheet while they have not set up; else a new sign-in code and a new password, each with its tick and only once its
// time allows) and signing them out everywhere. Every form posts where it did before, with the same fields.
?>
<div class="doc-actions" role="group" aria-label="<?= $word('DOC', 'actions') ?>">
  <details class="more">
    <summary class="btn"><?= $word('DOC', 'more') ?></summary>
    <div class="more-menu">
<?php if (!$person['is_active'] && $placeholder): ?>
      <p class="note read-only"><?= $word('STAFF', 'test_off') ?></p>
<?php else: ?>
      <details class="doc-act person-active">
        <summary class="btn"><?php if ($person['is_active']): ?><?= $word('STAFF', 'stop') ?><?php else: ?><?= $word('STAFF', 'allow') ?><?php endif; ?></summary>
        <div class="doc-act-panel">
          <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/active') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php if ($person['is_active']): ?>
            <input type="hidden" name="active" value="0">
            <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('STAFF', 'stop_confirm') ?></label>
            <button type="submit" class="btn risky"><?= $word('STAFF', 'stop') ?></button>
            <span class="muted"><?= $word('STAFF', 'stop_note') ?></span>
<?php else: ?>
            <input type="hidden" name="active" value="1">
            <button class="btn" type="submit"><?= $word('STAFF', 'allow') ?></button>
            <span class="muted"><?= $word('STAFF', 'allow_note') ?></span>
<?php endif; ?>
          </form>
        </div>
      </details>
<?php endif; ?>
<?php if (!$placeholder): ?>
<?php if ($setup['state'] === 'signup'): ?>
      <details class="doc-act person-sheet">
        <summary class="btn"><?= $word('STAFF', 'new_sheet') ?></summary>
        <div class="doc-act-panel">
<?php if ($addressMissing): ?>
          <p class="note"><?= $word('SHEET', 'address_missing') ?></p>
<?php endif; ?>
          <p class="muted"><?= $word('STAFF', 'new_sheet_text') ?> <?= $explain('sign_up', \CW\Ui\Words::STAFF['new_sheet']) ?></p>
          <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/sheet') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($formKeys['sheet']) ?>">
            <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('STAFF', 'new_sheet_confirm') ?></label>
            <button type="submit" class="btn risky"><?= $word('STAFF', 'new_sheet') ?></button>
          </form>
        </div>
      </details>
<?php else: ?>
      <details class="doc-act person-code">
        <summary class="btn"><?= $word('STAFF', 'new_code') ?></summary>
        <div class="doc-act-panel">
<?php if ($addressMissing): ?>
          <p class="note"><?= $word('SHEET', 'address_missing') ?></p>
<?php endif; ?>
          <p class="muted"><?= $word('STAFF', 'new_code_text') ?> <?= $explain('sign_up', \CW\Ui\Words::STAFF['new_code']) ?></p>
<?php if ($setup['until'] !== null && $setup['open']): ?>
          <p class="note read-only"><?= $say('STAFF', 'code_blocked', \CW\Ui\Html::when($setup['until'])) ?></p>
<?php else: ?>
          <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/authenticator') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($formKeys['code']) ?>">
            <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('STAFF', 'new_code_confirm') ?></label>
            <button type="submit" class="btn risky"><?= $word('STAFF', 'new_code') ?></button>
          </form>
<?php endif; ?>
          <p class="muted"><?= $word('STAFF', 'both_lost') ?></p>
        </div>
      </details>
      <details class="doc-act person-password">
        <summary class="btn"><?= $word('STAFF', 'new_password') ?></summary>
        <div class="doc-act-panel">
          <p class="muted"><?= $word('STAFF', 'new_password_text') ?></p>
<?php if ($setup['state'] === 'reset'): ?>
          <p class="note read-only"><?= $word('STAFF', 'password_blocked') ?></p>
<?php else: ?>
          <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/password') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($formKeys['password']) ?>">
            <label class="choice"><input type="checkbox" name="confirm" value="1" required> <?= $word('STAFF', 'new_password_confirm') ?></label>
            <button type="submit" class="btn risky"><?= $word('STAFF', 'new_password') ?></button>
          </form>
<?php endif; ?>
        </div>
      </details>
<?php endif; ?>
<?php endif; ?>
<?php if ($canManage && $devices !== []): ?>
      <details class="doc-act person-sign-out">
        <summary class="btn"><?= $word('STAFF', 'sign_out_all') ?></summary>
        <div class="doc-act-panel">
          <form class="inline" method="post" action="<?= $u('/ui/people/' . $person['id'] . '/sign-out') ?>" data-leaves="1">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="session" value="all">
            <button type="submit" class="btn risky"><?= $word('STAFF', 'sign_out_all') ?></button>
            <span class="muted"><?= $word('STAFF', 'sign_out_all_note') ?></span>
          </form>
        </div>
      </details>
<?php endif; ?>
    </div>
  </details>
</div>
