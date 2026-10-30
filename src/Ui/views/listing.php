<?php
// Check a match (the approved Listings artboard of 30 Oct 2026; docs/decisions.md RD-C1-RD-C6): "Is this the same item?" with the store and how many
// are left to check under it; ONE comparison card: the store product on the left (its blue dot), our item on the right (its green dot), one row
// per fact with a green tick when the two sides are the same and an amber ! when they differ (that row lightly tinted); under it "Not this one?
// Find another item" (its own GET form) and "1 sale on the store is N of our item"; the bar at the bottom: Skip, More, Not the same, Same item.
// ONE decision form posts exactly what it posted before (the note is a field of it under the bar's More, open when it was refused). Every other
// answer and every other part of the page is under the bar's More (listing_more). A store product that is not answered here (a landing page,
// one waiting for a second OK, a matched one, one only a lead or the spot check's owner decides, look only) says so under the card; a spot
// check's own match keeps its three answers (Skip, Not the same behind its warning, Same item: the suggestion, as the spot check counts it).
$fieldId = match (true) {
    $error_field === null => null,
    $error_field === 'action' => 'f-action',
    $error_field === 'units_per_item' => 'units',
    $error_field === 'reason' => 'f-reason',
    $error_field === 'sku_id' => 'f-pick',
    str_starts_with($error_field, 'card.') => 'card_' . substr($error_field, 5),
    default => null,
};
$spotOther = $spot !== null && !$spot['mine'];
$spotDone = $spot !== null && $spot['done'];
// The one decision form: someone who may decide, a store product still to answer, not the spot check's own answers.
$one = $landing === null && $no_form === null && !$spot_mode;
// Its main answer: Same item with an item to compare; with none, New item when the computer suggests one (what the form chose first before).
$newMain = $one && $target === null && $preselect === 'new_item';
$cardOpen = $one && ($form['action'] === 'new_item' || ($error_field !== null && str_starts_with($error_field, 'card.')));
// More is closed while New item is the main answer and nothing was refused; a refused one opens it, the refused field in view (W12.20); a
// refused note opens it on the note (RD-C4).
$changeOpen = $change_match && ($error !== null || $change_target !== null);
$noteOpen = $one && $error_field === 'reason';
$skip = $spot_mode ? ($strip['next'] ?? ($strip['url'] ?? '/ui/review/samples')) : ($next_link ?? $back[0]);
$nextSpot = !$spot_mode && $strip !== null && $strip['mine'] && $strip['next'] !== null && ($spot === null || $spotDone) ? $strip['next'] : null;
// The facts in the approved design's order (brand, line, flavour, strength, pack), then the rest of what either side states.
$rank = ['brand' => 0, 'line' => 1, 'flavour' => 2, 'strength_mg' => 3, 'pack_units' => 4];
$facts = $compare;
usort($facts, static fn (array $a, array $b): int => ($rank[$a['field']] ?? 9) <=> ($rank[$b['field']] ?? 9));
$agrees = static fn (string $state): bool => in_array($state, ['same', 'alike'], true);
$others = count(array_filter($candidates, static fn (array $c): bool => $c['sku'] === null || $target === null || (int) $c['sku']['id'] !== (int) $target['id']));
$status = in_array($l['status'], ['unmapped', 'suggested'], true) ? null : \CW\Ui\Words::of('LISTING_STATUS', $l['status']);
$count = $strip !== null ? \CW\Ui\Words::say('SPOT', 'progress', $strip['decided'], $strip['size'])
    : ($progress_left !== null ? \CW\Ui\Words::say('MATCH', 'to_match', $progress_left) : null);
// The line under the question (the artboard's "Electrofag · 144 left to check"): the store, the state when it is not still to match, how many
// are left; the progress in words for a screen reader (data-progress, the list's own words).
$line = implode(' · ', array_filter([$l['channel_name'] ?? $l['channel'], $status, $count], static fn (?string $v): bool => $v !== null && $v !== ''));
$more = ['l' => $l, 'why' => $why, 'partners' => $partners, 'proposal' => $proposal, 'closest' => $closest, 'closest_pick' => $closest_pick, 'show_search' => $show_search,
    'attributes' => $attributes, 'linked_sku' => $linked_sku, 'target' => $target, 'protected' => $protected, 'elsewhere' => $elsewhere, 'dup_groups' => $dup_groups,
    'strip' => $strip, 'decisions' => $decisions, 'previously_rejected' => $previously_rejected];
// Opened from a spot check: its blocks at the head's right (done, wrong, this one, to do); no bar for a list (its line says how many are left).
$bar = $strip !== null ? $partial('listing_progress', ['strip' => $strip, 'words' => null, 'fill' => 0]) : '';
$question = \CW\Ui\Words::MATCH['question'];
// Our item's column: "Our item" for the suggestion; the item picked, the matched one or what a waiting decision does say so.
$ourHead = $target_heading === \CW\Ui\Words::LISTING['suggested'] ? \CW\Ui\Words::MATCH['our_item'] : $target_heading;
?>
<div class="match-head">
<?= $head($question, intro: $intro_page, look: $lookOnly, meta: $progress !== null ? '<span class="mt-line" data-progress="' . $e($progress) . '">' . $e($line) . '</span>' : '<span class="mt-line">' . $e($line) . '</span>', side: $bar, back: [$back[0], $back[1]]) ?>
</div>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($fieldId !== null): ?> <a href="#<?= $e($fieldId) ?>"><?= $word('LISTING', 'go_to_field') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php if ($held !== null): ?>
<div class="alert needs held-back" role="note">
  <p class="alert-title"><?= $say('LISTING', 'held', $held['reason'], $held['by'], \CW\Ui\Html::day($held['at'])) ?></p>
<?php if (!$held['waiting']): ?>
  <p><?= $word('LISTING', 'held_decided') ?></p>
<?php elseif ($held['newer']): ?>
  <p><?= $word('LISTING', 'held_newer') ?></p>
<?php endif; ?>
  <p><a href="<?= $e($held['url']) ?>"><?= $say('SAMPLE', 'title', $held['sample']) ?></a></p>
</div>
<?php endif; ?>

<?php if ($one): ?>
<form class="mt-form" id="decide" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($error !== null): ?> data-dirty="1"<?php endif; ?>>
  <button type="submit" class="po-default" disabled hidden tabindex="-1" aria-hidden="true"><?= $word('MATCH', 'same') ?></button>
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
<?php if ($proposal !== null): ?>
  <input type="hidden" name="proposal_id" value="<?= $e($proposal['id']) ?>">
<?php endif; ?>
  <input type="hidden" name="sku_id" value="<?= $e($form['sku_id']) ?>">
<?php if (($form['seen'] ?? '') !== ''): ?>
  <input type="hidden" name="seen" value="<?= $e($form['seen']) ?>">
<?php endif; ?>
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
<?php else: ?>
<div class="mt-form" id="decide">
<?php endif; ?>
  <section class="card mt-compare" aria-label="<?= $word('MATCH', 'compare') ?>">
    <div class="mt-row mt-heads">
      <div class="mt-k"></div>
      <div class="mt-side mt-store">
        <h2 id="mt-store-h" class="mt-h"><span class="mt-dot" aria-hidden="true"></span><?= $word('MATCH', 'on_store') ?></h2>
        <p class="mt-name"><?= $e($l['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?><?php if ($l['variant_title'] !== null): ?> – <?= $e($l['variant_title']) ?><?php endif; ?></p>
        <p class="mt-sub"><?= $say('MATCH', 'variant', (string) $l['variant']) ?><?php if ($l['price'] !== null): ?> · <?php if (is_numeric($l['price'])): ?><?= $money($l['price']) ?><?php else: ?><?= $e($l['price']) ?><?php endif; ?><?php endif; ?></p>
      </div>
      <div class="mt-side mt-item">
        <h2 id="mt-item-h" class="mt-h"><span class="mt-dot" aria-hidden="true"></span><?php if ($pending !== null && $pending['action'] === 'new_item'): ?><?= $word('LISTING', 'new_by_decision') ?><?php elseif ($pending !== null && $target === null): ?><?= $word('LISTING', 'decision_does') ?><?php else: ?><?= $e($ourHead) ?><?php endif; ?></h2>
<?php if ($pending !== null && $pending['action'] === 'new_item'): ?>
        <p class="mt-sub"><?= $say('LISTING', 'new_by_decision_text', $pending['sale'] ?? \CW\Ui\Words::saleUses(1)) ?></p>
        <dl class="mt-facts">
<?php foreach ($pending['card'] as $f): ?>
          <dt><?= $e($f['label']) ?></dt> <dd><?= $e($f['value']) ?></dd>
<?php endforeach; ?>
        </dl>
<?php elseif ($pending !== null && $target === null): ?>
        <p class="mt-name"><?= $partial('pending_decision', ['d' => $pending]) ?></p>
<?php else: ?>
<?php if ($pick_note !== null): ?>
        <p class="error" role="alert"><?= $e($pick_note) ?></p>
<?php endif; ?>
<?php if ($target !== null): ?>
        <p class="mt-name"><a href="/ui/items/<?= $e($target['id']) ?>"><?= $e($target['name'] ?? $target['code']) ?></a></p>
        <p class="mt-sub"><?= $e($target['code']) ?> · <?= $say('MATCH', 'in_stock', (int) $target_stock) ?><?php if ($protected): ?> <span class="tag needs"><?= $word('MATCH', 'protected') ?></span><?php endif; ?><?php if ($previously_rejected): ?> <span class="tag needs"><?= $word('MATCH', 'refused_before') ?></span><?php endif; ?></p>
<?php if ($pending !== null && $pending['action'] === 'merge_skus' && $pending['merge_from_id'] !== null): ?>
        <p class="mt-sub"><?= $word('PENDING', 'join') ?>: <a href="/ui/items/<?= $e($pending['merge_from_id']) ?>"><?= $e($pending['merge_from_code']) ?></a> <?= $e($pending['merge_from_name']) ?> <?= $word('PENDING', 'joins') ?> <?= $e($target['code']) ?></p>
<?php endif; ?>
<?php elseif ($proposal !== null && $proposal['new_item']): ?>
        <p class="mt-name"><?= $word('LISTING', 'suggest_new') ?></p>
        <p class="mt-sub"><?= $word('LISTING', 'suggest_new_text') ?></p>
<?php else: ?>
        <p class="mt-sub"><?= $word('LISTING', 'no_suggestion') ?></p>
<?php endif; ?>
<?php endif; ?>
      </div>
      <div class="mt-m"></div>
    </div>
<?php if ($facts !== []): ?>
<?php foreach ($facts as $r): ?>
<?php $same = $agrees($r['state']); ?>
    <div class="mt-row mt-fact <?php if ($same): ?>same<?php else: ?>diff<?php endif; ?>" data-state="<?= $e($r['state']) ?>">
      <div class="mt-k"><?= $e($r['label']) ?></div>
      <div class="mt-v mt-store"><span class="visually-hidden"><?= $word('MATCH', 'on_store') ?>: </span><?php if ($r['listing'] === ''): ?><span class="muted"><?= $word('LISTING', 'not_stated') ?></span><?php else: ?><?= $e($r['listing']) ?><?php endif; ?></div>
      <div class="mt-v mt-item"><span class="visually-hidden"><?= $e($ourHead) ?>: </span><?php if ($r['item'] === ''): ?><span class="muted"><?= $word('LISTING', 'not_stated') ?></span><?php else: ?><?= $e($r['item']) ?><?php endif; ?></div>
      <div class="mt-m"><?php if ($same): ?><span class="mt-mark same" role="img" aria-label="<?= $word('MATCH', 'mark_same') ?>">✓</span><?php else: ?><span class="mt-mark diff" role="img" aria-label="<?= $word('MATCH', 'mark_differs') ?>">!</span><?php endif; ?></div>
    </div>
<?php endforeach; ?>
<?php elseif ($new_rows !== []): ?>
<?php foreach ($new_rows as $r): ?>
    <div class="mt-row mt-fact">
      <div class="mt-k"><?= $e($r['label']) ?></div>
      <div class="mt-v mt-store"><span class="visually-hidden"><?= $word('MATCH', 'on_store') ?>: </span><?= $e($r['value']) ?></div>
      <div class="mt-v mt-item"></div>
      <div class="mt-m"></div>
    </div>
<?php endforeach; ?>
<?php endif; ?>
  </section>

<?php if ($show_search || $one): ?>
  <div class="mt-pick">
<?php if ($show_search): ?>
    <div class="field mt-find" id="f-pick">
      <label for="mt-q"><?= $word('MATCH', 'find') ?></label>
      <input id="mt-q" type="search" name="s" form="mt-find" value="<?= $e($search_text) ?>" maxlength="100" placeholder="<?= $word('MATCH', 'find_hint') ?>" aria-keyshortcuts="/" data-page-search>
<?php if ($error_field === 'sku_id' && $error !== null): ?>
      <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($one): ?>
    <div class="field mt-units">
      <label for="units"><?= $word('MATCH', 'units') ?></label>
      <span class="mt-units-row"><input class="units" type="number" id="units" name="units_per_item" min="1" max="<?= $e($max_units) ?>" inputmode="numeric" value="<?= $e($form['units']) ?>" required<?php if ($error_field === 'units_per_item'): ?> aria-invalid="true"<?php endif; ?>> <span><?= $word('MATCH', 'units_of') ?></span></span>
<?php if ($error_field === 'units_per_item' && $error !== null): ?>
      <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
    </div>
<?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($show_search && $search_text !== '' && $found === []): ?>
  <p class="muted mt-none"><?= $word('SEARCH', 'no_items') ?></p>
<?php endif; ?>
<?php if ($show_search && $found !== []): ?>
  <div class="table-wrap mt-found">
  <table class="stack list found">
    <thead>
      <tr><th scope="col"><?= $word('LISTING', 'product') ?></th><th scope="col"><?= $word('SEARCH', 'rule') ?></th><th scope="col"><?= $word('LISTING', 'barcodes') ?></th><th scope="col"><span class="visually-hidden"><?= $word('LISTING', 'use') ?></span></th></tr>
    </thead>
    <tbody>
<?php foreach ($found as $s): ?>
      <tr>
        <th scope="row" class="c-head"><a href="/ui/items/<?= $e($s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?><?php if ($s['details'] !== ''): ?> <span class="o-sub"><?= $e($s['details']) ?></span><?php endif; ?></th>
        <td data-label="<?= $word('SEARCH', 'rule') ?>"><?= $word('POLICY', $s['policy']) ?></td>
        <td data-label="<?= $word('LISTING', 'barcodes') ?>" class="muted"><?= $e(implode(', ', $s['barcodes'])) ?></td>
        <td class="c-next"><?php if ($s['stop'] === null): ?><a class="btn sm" href="<?= $u('/ui/review/listing/' . $l['id'], $qq + ['pick' => $s['id']]) ?>#decide"><?= $word('LISTING', 'use') ?></a><?php else: ?><span class="muted"><?= $e($s['stop']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php if ($candidates !== [] && $others > 0): ?>
  <details class="mt-others" id="cand">
    <summary><?php if ($others === 1): ?><?= $word('MATCH', 'others_one') ?><?php else: ?><?= $say('MATCH', 'others_many', $others) ?><?php endif; ?></summary>
    <div class="table-wrap">
    <table class="stack candidates">
      <thead>
        <tr><th scope="col"><?= $word('LISTING', 'no') ?></th><th scope="col"><?= $word('LISTING', 'product') ?></th><th scope="col" class="num"><?= $word('LISTING', 'score') ?></th><th scope="col"><?= $word('LISTING', 'problems') ?></th><th scope="col"><span class="visually-hidden"><?= $word('LISTING', 'use') ?></span></th></tr>
      </thead>
      <tbody>
<?php foreach ($candidates as $c): ?>
        <tr<?php if ($c['ref'] !== null): ?> id="cand-<?= $e($c['ref']) ?>"<?php endif; ?><?php if ($c['ai_picked']): ?> class="picked"<?php endif; ?>>
          <th scope="row" class="c-head"><?= $e($c['ref']) ?></th>
          <td data-label="<?= $word('LISTING', 'product') ?>"><?php if ($c['sku'] !== null): ?><a href="/ui/items/<?= $e($c['sku']['id']) ?>"><?= $e($c['sku']['code']) ?></a> <?= $e($c['sku']['name']) ?><?php else: ?><?= $e($c['cw_id']) ?> <span class="muted"><?= $word('LISTING', 'not_in_cw') ?></span><?php endif; ?><?php if ($c['ai_picked']): ?> <span class="tag done"><?= $word('LISTING', 'tag_ai') ?></span><?php endif; ?><?php if ($c['proposed']): ?> <span class="tag info"><?= $word('LISTING', 'tag_suggested') ?></span><?php endif; ?></td>
          <td class="num" data-label="<?= $word('LISTING', 'score') ?>"><?= $e($c['prescore']) ?></td>
          <td data-label="<?= $word('LISTING', 'problems') ?>"><?php foreach ($c['problems'] as $p): ?><span class="tag<?php if ($p['bad']): ?> blocked<?php else: ?> needs<?php endif; ?>"><?= $e($p['text']) ?></span> <?php endforeach; ?></td>
          <td class="c-next"><?php if ($c['pick'] !== null && $show_search): ?><a class="btn sm" href="<?= $e($c['pick']) ?>"><?= $word('LISTING', 'use') ?></a><?php endif; ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
<?php endif; ?>

<?php if (!$one && $landing !== null): ?>
  <section class="card mt-answer landing-box" id="landing" aria-labelledby="landing-h">
    <h2 id="landing-h" class="mt-h"><?= $word('LISTING_STATUS', 'landing') ?></h2>
    <p><?= $word('LANDING', 'is_landing') ?></p>
    <p class="muted"><?= $e($landing['why']) ?></p>
<?php if ($landing['can_real']): ?>
    <details class="confirm-step" id="real-product"<?php if ($error_field === 'reason'): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('ACTION', 'not_landing') ?></summary>
      <form method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1"<?php if ($error !== null): ?> data-dirty="1"<?php endif; ?>>
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="not_landing">
        <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
        <input type="hidden" name="seen" value="<?= $e($form['seen'] ?? '') ?>">
        <label for="f-reason"><?= $word('LANDING', 'real_why') ?></label>
        <input type="text" id="f-reason" name="reason" maxlength="<?= $e($max_reason) ?>" required value="<?= $e($form['reason'] ?? '') ?>">
        <p class="actions"><button type="submit" class="btn"><?= $word('LANDING', 'real_button') ?></button></p>
      </form>
    </details>
<?php else: ?>
    <p class="muted"><?= $word('LANDING', 'lead_only') ?></p>
<?php endif; ?>
  </section>
<?php elseif (!$one && $pending !== null): ?>
  <section class="card mt-answer waiting" aria-labelledby="waiting-h">
    <h2 id="waiting-h" class="mt-h"><?= $word('LISTING', 'waiting') ?></h2>
    <p><?= $partial('pending_decision', ['d' => $pending]) ?></p>
    <p><?= $say('LISTING', 'decided_by', $pending['decider'] ?? \CW\Ui\Words::LISTING['h_computer'], \CW\Ui\Html::when($pending['created_at'])) ?></p>
<?php if ($pending['needs'] !== []): ?>
    <p><span class="muted"><?= $word('LISTING', 'why_second') ?>:</span>
<?php foreach ($pending['needs'] as $need): ?>
      <span class="tag needs"><?= $word('NEEDS_SECOND', $need) ?></span>
<?php endforeach; ?>
    </p>
<?php endif; ?>
<?php if ($pending['reason'] !== null): ?>
    <p class="muted"><?= $say('PENDING', 'note_line', $pending['reason']) ?></p>
<?php endif; ?>
<?php foreach ($pending['stale'] as $stale): ?>
    <p class="error"><?= $e($stale) ?> <?= $word('LISTING', 'stale_fix') ?></p>
<?php endforeach; ?>
<?php if ($no_form !== null): ?>
    <p><?= $e($no_form) ?></p>
<?php endif; ?>
    <div class="actions"><?= $partial('pending_actions', ['id' => $pending['id'], 'from' => 'listing', 'can_approve' => $pending['can_approve'], 'can_withdraw' => $pending['can_withdraw'], 'own' => $pending['own'], 'decider' => $pending['decider'], 'say_wait' => $no_form === null]) ?></div>
  </section>
<?php elseif (!$one): ?>
  <section class="card mt-answer<?php if ($spotOther): ?> waiting<?php endif; ?>" aria-label="<?= $word('MATCH', 'question') ?>">
<?php if ($no_form !== null): ?>
    <p class="note read-only<?php if ($spotOther): ?> spot-check<?php endif; ?>"<?php if ($spotOther): ?> role="alert"<?php endif; ?>><?= $e($no_form) ?></p>
<?php elseif ($spot_mode): ?>
    <p class="spot-check"><?= $word('SPOT', 'mine') ?></p>
<?php if ($spot_yes !== null && $spot_instead !== null): ?>
    <p class="note"><?= $say('SPOT', 'picked_other', $spot_instead, $spot_yes['code'], $spot_instead) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($spotDone && !$spotOther): ?>
    <p class="spot-check"><?= $word('SPOT', 'mine_done') ?></p>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($spot_mode): ?>
  <div class="doc-bar mt-bar spot-answers" id="f-action">
    <a class="btn ghost" href="<?= $e($skip) ?>" data-leave><?= $word('MATCH', 'skip') ?></a>
    <details class="more">
      <summary class="btn"><?= $word('MATCH', 'more') ?></summary>
      <div class="more-menu">
<?= $partial('listing_more', $more) ?>
      </div>
    </details>
    <details class="more mt-step" id="not-a-match"<?php if ($spot_open): ?> open<?php endif; ?>>
      <summary class="btn"><?= $word('MATCH', 'not_same') ?></summary>
      <div class="more-menu">
        <div class="alert blocked" role="note">
          <p class="alert-title"><?= $word('SPOT', 'no_confirm_title') ?></p>
          <p><?= $word('SPOT', 'no_confirm_text') ?></p>
          <p><?= $word('SPOT', 'no_confirm_what') ?></p>
        </div>
        <?= $partial('listing_form', ['l' => $l, 'form' => $form, 'qq' => $qq, 'proposal' => $proposal, 'target' => $target, 'can_link' => $spot_instead !== null,
            'offer_link' => $spot_instead !== null, 'link_label' => $spot_instead !== null ? \CW\Ui\Words::say('SPOT', 'instead_link', $spot_instead) : null,
            'protected' => $protected, 'max_units' => $max_units, 'max_reason' => $max_reason, 'card_fields' => $card_fields,
            'error' => $error, 'error_field' => $error_field, 'button' => \CW\Ui\Words::SPOT['no_confirm_button'], 'danger' => true]) ?>
      </div>
    </details>
<?php if ($spot_yes !== null): ?>
    <form class="quick" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide" data-leaves="1">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="action" value="link">
      <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
<?php if ($proposal !== null): ?>
      <input type="hidden" name="proposal_id" value="<?= $e($proposal['id']) ?>">
<?php endif; ?>
      <input type="hidden" name="sku_id" value="<?= $e($spot_yes['sku_id']) ?>">
      <input type="hidden" name="units_per_item" value="<?= $e($spot_yes['units']) ?>">
<?php if (($form['seen'] ?? '') !== ''): ?>
      <input type="hidden" name="seen" value="<?= $e($form['seen']) ?>">
<?php endif; ?>
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
      <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
      <button type="submit" class="btn primary"><?= $word('MATCH', 'same') ?><?php if ($spot_yes['units'] !== '1'): ?> (<?= $e(\CW\Ui\Words::saleUses($spot_yes['units'])) ?>)<?php endif; ?></button>
    </form>
<?php endif; ?>
  </div>
<?php else: ?>
  <div class="doc-bar mt-bar" id="f-action">
    <a class="btn ghost" href="<?= $e($skip) ?>" data-leave><?= $word('MATCH', 'skip') ?></a>
    <details class="more"<?php if (($cardOpen && (!$newMain || $error !== null)) || $changeOpen || $noteOpen): ?> open<?php endif; ?>>
      <summary class="btn"<?php if ($one): ?> aria-label="<?= $word('MATCH', 'more_label') ?>"<?php endif; ?>><?= $word('MATCH', 'more') ?></summary>
      <div class="more-menu">
<?php if ($one): ?>
        <div class="field mt-note">
          <label for="f-reason"><?= $word('MATCH', 'note') ?></label>
          <input type="text" id="f-reason" name="reason" maxlength="<?= $e($max_reason) ?>" value="<?= $e($form['reason']) ?>" placeholder="<?= $word('MATCH', 'note_hint') ?>"<?php if ($error_field === 'reason'): ?> aria-invalid="true"<?php endif; ?>>
<?php if ($error_field === 'reason' && $error !== null): ?>
          <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
        </div>
        <details class="doc-act mt-new" id="new-product"<?php if ($cardOpen): ?> open<?php endif; ?>>
          <summary class="btn"><?= $word('MATCH', 'new_item') ?></summary>
          <div class="doc-act-panel">
          <fieldset class="doc-grid">
            <legend class="visually-hidden"><?= $word('LISTING', 'new_details') ?></legend>
<?php foreach ($card_fields as $f): ?>
<?php $bad = $error_field === 'card.' . $f['name']; ?>
            <div class="field">
              <label for="card_<?= $e($f['name']) ?>"><?= $e($f['label']) ?></label>
<?php if ($f['options'] !== null): ?>
              <select id="card_<?= $e($f['name']) ?>" name="card_<?= $e($f['name']) ?>"<?php if ($bad): ?> aria-invalid="true"<?php endif; ?>>
<?php foreach ($f['options'] as $value => $label): ?>
                <option value="<?= $e($value) ?>"<?php if ((string) $value === $f['value']): ?> selected<?php endif; ?>><?= $e($label) ?></option>
<?php endforeach; ?>
              </select>
<?php else: ?>
              <input type="text" id="card_<?= $e($f['name']) ?>" name="card_<?= $e($f['name']) ?>" value="<?= $e($f['value']) ?>" maxlength="300"<?php if ($bad): ?> aria-invalid="true"<?php endif; ?>>
<?php endif; ?>
<?php if ($bad && $error !== null): ?>
              <p class="field-error"><?= $e($error) ?></p>
<?php endif; ?>
            </div>
<?php endforeach; ?>
          </fieldset>
<?php if (!$newMain): ?>
          <p class="actions"><button type="submit" name="action" value="new_item" class="btn"><?= $word('MATCH', 'new_item_button') ?></button></p>
<?php endif; ?>
          </div>
        </details>
        <button type="submit" name="action" value="ignore" class="btn"><?= $word('ACTION', 'ignore') ?></button>
<?php if ($mark_landing): ?>
        <button type="submit" form="mark-landing" class="btn"><?= $word('ACTION', 'landing') ?></button>
<?php endif; ?>
<?php endif; ?>
<?php if ($change_match): ?>
        <details class="doc-act change-match<?php if ($change_spot): ?> spot-change<?php endif; ?>" id="change-match"<?php if ($changeOpen): ?> open<?php endif; ?>>
          <summary class="btn<?php if ($change_spot): ?> risky<?php endif; ?>"><?php if ($change_spot): ?><?= $word('SPOT', 'change') ?><?php else: ?><?= $word('LISTING', 'change_match') ?><?php endif; ?></summary>
          <div class="doc-act-panel">
<?php if ($change_spot): ?>
          <div class="alert blocked" role="note">
            <p class="alert-title"><?= $word('SPOT', 'no_confirm_title') ?></p>
            <p><?= $word('SPOT', 'no_confirm_text') ?></p>
          </div>
<?php endif; ?>
          <?= $partial('listing_form', ['l' => $l, 'form' => ['sku_id' => (string) ($change_target['id'] ?? '')] + $form, 'qq' => $qq, 'proposal' => $proposal,
              'target' => $change_target, 'can_link' => $change_target !== null, 'offer_link' => true, 'link_label' => null,
              // On a spot check's confirmed member every answer left here changes the match, so the button's words are true; "No, wrong
              // product" about another product would change nothing, so it is not offered there.
              'offer_reject' => !$change_spot, 'offer_unlink' => true, 'protected' => $protected,
              'max_units' => $max_units, 'max_reason' => $max_reason, 'card_fields' => $card_fields, 'error' => $error, 'error_field' => $error_field,
              'button' => $change_spot ? \CW\Ui\Words::SPOT['change_button'] : \CW\Ui\Words::LISTING['save'], 'danger' => $change_spot]) ?>
          </div>
        </details>
<?php endif; ?>
<?= $partial('listing_more', $more) ?>
      </div>
    </details>
<?php if ($one && $newMain): ?>
    <button type="submit" name="action" value="new_item" class="btn primary"><?= $word('MATCH', 'new_item') ?></button>
<?php elseif ($one): ?>
    <button type="submit" name="action" value="reject" class="btn"<?php if ($target === null): ?> disabled<?php endif; ?>><?= $word('MATCH', 'not_same') ?></button>
<?php if (!$can_link): ?>
    <span class="btn-why"><?= $word('MATCH', 'pick_first') ?></span>
<?php endif; ?>
    <button type="submit" name="action" value="link" class="btn primary"<?php if (!$can_link): ?> disabled<?php endif; ?>><?= $word('MATCH', 'same') ?><?php if ($form['units'] !== '1' && $can_link): ?> (<?= $e(\CW\Ui\Words::saleUses($form['units'])) ?>)<?php endif; ?></button>
<?php elseif ($nextSpot !== null): ?>
    <a class="btn primary" href="<?= $e($nextSpot) ?>"><?= $say('SPOT', 'next', $strip['next_position'], $strip['size']) ?></a>
<?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($one): ?>
</form>
<?php else: ?>
</div>
<?php endif; ?>
<?php if ($show_search): ?>
<form id="mt-find" method="get" action="/ui/review/listing/<?= $e($l['id']) ?>#f-pick" data-leaves="1">
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
  <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
</form>
<?php endif; ?>
<?php if ($one && $mark_landing): ?>
<form id="mark-landing" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide" data-leaves="1">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="action" value="landing">
  <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
<?php if ($proposal !== null): ?>
  <input type="hidden" name="proposal_id" value="<?= $e($proposal['id']) ?>">
<?php endif; ?>
<?php if (($form['seen'] ?? '') !== ''): ?>
  <input type="hidden" name="seen" value="<?= $e($form['seen']) ?>">
<?php endif; ?>
</form>
<?php endif; ?>
