<?php
// The answers of one website product (plan §6.16, design B's "What each answer does"). listing.php draws this box straight after
// the two product cards for a spot check's match and for a strong match's quick yes (plan F203: the answer before the evidence,
// so it is not three phone screens down), else after "Why the computer suggests this".
?>
<section class="card decide-box" id="decide" aria-labelledby="decide-h">
  <h2 id="decide-h"><?= $word('LISTING', 'question') ?></h2>
<?php if ($no_form !== null): ?>
  <p class="note read-only"><?= $e($no_form) ?></p>
<?php if ($change_match): ?>
  <details class="fold change-match<?php if ($change_spot): ?> spot-change<?php endif; ?>" id="change-match"<?php if ($error !== null || $change_target !== null): ?> open<?php endif; ?>>
    <summary><?php if ($change_spot): ?><?= $word('SPOT', 'change') ?><?php else: ?><?= $word('LISTING', 'change_match') ?><?php endif; ?></summary>
<?php if ($change_spot): ?>
    <div class="alert blocked" role="note">
      <p class="alert-title"><?= $word('SPOT', 'no_confirm_title') ?></p>
      <p><?= $word('SPOT', 'no_confirm_text') ?></p>
    </div>
<?php endif; ?>
    <p class="hint"><?= $word('LISTING', 'change_match_text') ?></p>
    <?= $partial('listing_form', ['l' => $l, 'form' => ['sku_id' => (string) ($change_target['id'] ?? '')] + $form, 'qq' => $qq, 'proposal' => $proposal,
        'target' => $change_target, 'can_link' => $change_target !== null, 'offer_link' => true, 'link_label' => null,
        // On a spot check's confirmed member every answer left here changes the match, so the button's words are true; "No, wrong
        // product" about another product would change nothing, so it is not offered there.
        'offer_reject' => !$change_spot, 'protected' => $protected,
        'max_units' => $max_units, 'max_reason' => $max_reason, 'card_fields' => $card_fields, 'error' => $error, 'error_field' => $error_field,
        'button' => $change_spot ? \CW\Ui\Words::SPOT['change_button'] : \CW\Ui\Words::LISTING['save'], 'danger' => $change_spot]) ?>
  </details>
<?php endif; ?>
<?php elseif ($spot_mode): ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done"><dt><?= $word('SPOT', 'yes') ?></dt><dd><?= $say('SPOT', 'does_yes', $spot_yes['code'] ?? \CW\Ui\Words::THING['proposed_item'], $spot['size']) ?></dd></div>
      <div class="answer blocked"><dt><?= $word('SPOT', 'no') ?></dt><dd><?= $word('SPOT', 'does_no') ?></dd></div>
      <div class="answer info safe"><dt><?= $word('SPOT', 'unsure') ?> <span class="safe-tag"><?= $word('UI', 'safer') ?></span></dt><dd><?= $say('SPOT', 'does_unsure', $spot['size']) ?></dd></div>
    </dl>
  </div>
<?php if ($spot_yes !== null && $spot_instead !== null): ?>
  <p class="note"><?= $say('SPOT', 'picked_other', $spot_instead, $spot_yes['code'], $spot_instead) ?></p>
<?php endif; ?>
  <div class="decide-buttons spot-answers">
<?php if ($spot_yes !== null): ?>
    <form class="quick" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="link">
      <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
<?php if ($proposal !== null): ?>
      <input type="hidden" name="proposal_id" value="<?= $e($proposal['id']) ?>">
<?php endif; ?>
      <input type="hidden" name="sku_id" value="<?= $e($spot_yes['sku_id']) ?>">
      <input type="hidden" name="units_per_item" value="<?= $e($spot_yes['units']) ?>">
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
      <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
      <button type="submit" class="btn primary big"><span class="btn-title"><?= $word('SPOT', 'yes') ?><?php if ($spot_yes['units'] !== '1'): ?> (<?= $e(\CW\Ui\Words::saleUses($spot_yes['units'])) ?>)<?php endif; ?></span><span class="sub"><?= $word('SPOT', 'yes_sub') ?></span></button>
    </form>
<?php endif; ?>
    <details class="confirm-step" id="not-a-match"<?php if ($spot_open): ?> open<?php endif; ?>>
      <summary class="btn secondary big"><span class="btn-title"><?= $word('SPOT', 'no') ?></span><span class="sub"><?= $word('SPOT', 'no_sub') ?></span></summary>
      <div class="alert blocked" role="note">
        <p class="alert-title"><?= $word('SPOT', 'no_confirm_title') ?></p>
        <p><?= $word('SPOT', 'no_confirm_text') ?></p>
        <p><?= $word('SPOT', 'no_confirm_what') ?></p>
      </div>
      <?= $partial('listing_form', ['l' => $l, 'form' => $form, 'qq' => $qq, 'proposal' => $proposal, 'target' => $target, 'can_link' => $spot_instead !== null,
          'offer_link' => $spot_instead !== null, 'link_label' => $spot_instead !== null ? \CW\Ui\Words::say('SPOT', 'instead_link', $spot_instead) : null,
          'protected' => $protected, 'max_units' => $max_units, 'max_reason' => $max_reason, 'card_fields' => $card_fields,
          'error' => $error, 'error_field' => $error_field, 'button' => \CW\Ui\Words::SPOT['no_confirm_button'], 'danger' => true]) ?>
    </details>
    <a class="btn secondary big" href="<?= $e($strip['next'] ?? ($strip['url'] ?? '/ui/review/samples')) ?>"><span class="btn-title"><?= $word('SPOT', 'unsure') ?></span><span class="sub"><?= $word('SPOT', 'unsure_sub') ?></span></a>
  </div>
<?php else: ?>
<?php if ($quick_link): ?>
  <form class="quick" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="link">
    <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
<?php if ($proposal !== null): ?>
    <input type="hidden" name="proposal_id" value="<?= $e($proposal['id']) ?>">
<?php endif; ?>
    <input type="hidden" name="sku_id" value="<?= $e($form['sku_id']) ?>">
    <input type="hidden" name="units_per_item" value="<?= $e($form['units']) ?>">
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
    <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
    <button type="submit" class="primary"><?php if ($next_link !== null): ?><?= $word('LISTING', 'quick') ?><?php else: ?><?= $word('LISTING', 'quick_last') ?><?php endif; ?><?php if ($form['units'] !== '1'): ?> (<?= $e(\CW\Ui\Words::saleUses($form['units'])) ?>)<?php endif; ?></button>
    <span class="muted"><?= $word('LISTING', 'quick_note') ?></span>
  </form>
<?php endif; ?>
  <div class="answers">
    <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
    <dl class="answer-list">
      <div class="answer done"><dt><?= $word('SPOT', 'yes') ?></dt><dd><?php if ($target !== null): ?><?= $say('LISTING', 'does_yes', $target['code']) ?><?php else: ?><?= $word('LISTING', 'does_yes_none') ?><?php endif; ?></dd></div>
      <div class="answer info"><dt><?= $word('ACTION', 'new_item') ?></dt><dd><?= $word('LISTING', 'does_new') ?></dd></div>
      <div class="answer off"><dt><?= $word('ACTION', 'ignore') ?></dt><dd><?= $word('LISTING', 'does_ignore') ?></dd></div>
      <div class="answer blocked"><dt><?= $word('ACTION', 'reject') ?></dt><dd><?= $word('LISTING', 'does_reject') ?></dd></div>
      <div class="answer waiting safe"><dt><?= $word('LISTING', 'skip_answer') ?> <span class="safe-tag"><?= $word('UI', 'safer') ?></span></dt><dd><?= $word('LISTING', 'does_skip') ?> <a href="<?= $e($skip_href) ?>"><?php if ($next_link !== null): ?><?= $word('LISTING', 'skip') ?><?php else: ?><?= $e($skip_label) ?><?php endif; ?></a></dd></div>
    </dl>
    <p class="muted small"><?= $word('LISTING', 'does_second') ?> <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?></p>
  </div>
  <?= $partial('listing_form', ['l' => $l, 'form' => $form, 'qq' => $qq, 'proposal' => $proposal, 'target' => $target, 'can_link' => $can_link,
      'offer_link' => true, 'link_label' => null, 'protected' => $protected, 'max_units' => $max_units, 'max_reason' => $max_reason, 'card_fields' => $card_fields,
      'error' => $error, 'error_field' => $error_field, 'button' => $next_link !== null ? \CW\Ui\Words::LISTING['save_next'] : \CW\Ui\Words::LISTING['save'], 'danger' => false]) ?>
<?php endif; ?>
</section>
