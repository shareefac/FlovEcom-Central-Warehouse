<?php
// Where a refused field is, so the message at the top can lead to it (plan F213).
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
// The answers come straight after the two product cards for a spot check's match and a strong match's quick yes (plan F203), else
// after "Why the computer suggests this".
$showDecide = $no_form === null || (!$spotOther && $pending === null);
$early = $spot_mode || $quick_link;
$decide = ['l' => $l, 'no_form' => $no_form, 'change_match' => $change_match, 'change_spot' => $change_spot, 'change_target' => $change_target,
    'form' => $form, 'qq' => $qq, 'proposal' => $proposal, 'target' => $target, 'can_link' => $can_link, 'protected' => $protected,
    'max_units' => $max_units, 'max_reason' => $max_reason, 'card_fields' => $card_fields, 'error' => $error, 'error_field' => $error_field,
    'spot_mode' => $spot_mode, 'spot' => $spot, 'strip' => $strip, 'spot_yes' => $spot_yes, 'spot_instead' => $spot_instead, 'spot_open' => $spot_open,
    'quick_link' => $quick_link, 'next_link' => $next_link, 'skip_href' => $next_link ?? $back[0],
    'skip_label' => \CW\Ui\Words::say('LISTING', 'skip_back', $back[1])];
?>
<p class="crumbs">
  <a href="<?= $e($back[0]) ?>"><?= $e($back[1]) ?></a>
<?php if ($next_link !== null): ?>
  <a class="next-link" href="<?= $e($next_link) ?>"><?= $word('LISTING', 'skip') ?> &rarr;</a>
<?php endif; ?>
</p>

<h1><?= $e($l['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></h1>
<?php if ($l['variant_title'] !== null): ?>
<p class="title subtitle"><?= $e($l['variant_title']) ?></p>
<?php endif; ?>
<p class="eyebrow"><?= $e($l['channel_name'] ?? $l['channel']) ?> · <?= $stateChip('LISTING_STATUS', $l['status']) ?></p>
<?= $intro($intro_page, $lookOnly) ?>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($fieldId !== null): ?> <a href="#<?= $e($fieldId) ?>"><?= $word('LISTING', 'go_to_field') ?></a><?php endif; ?></p>
<?php endif; ?>

<?php if ($strip !== null): ?>
<section class="card spot-box<?php if ($spotOther): ?> waiting<?php endif; ?>" aria-labelledby="spot-h">
  <div class="head-help">
    <h2 id="spot-h"><?php if ($strip['position'] !== null): ?><?= $say('SPOT', 'box', $strip['name'], $strip['position'], $strip['size']) ?><?php else: ?><?= $say('SAMPLE', 'title', $strip['name']) ?><?php endif; ?></h2>
    <?= $explain('spot_check', \CW\Ui\Words::MENU['samples']) ?>
  </div>
  <p class="progress-line"><strong><?= $say('SPOT', 'progress', $strip['decided'], $strip['size']) ?></strong></p>
  <ol class="segments" aria-hidden="true">
<?php foreach ($strip['blocks'] as $b): ?>
    <li class="<?= $e($b['state']) ?>" title="<?= $e($b['title']) ?>"></li>
<?php endforeach; ?>
  </ol>
  <ul class="segments-legend">
    <li><span class="key yes"></span><?= $say('SPOT', 'legend_yes', $strip['yes']) ?></li>
<?php if ($strip['wrong'] > 0): ?>
    <li><span class="key no"></span><?= $say('SPOT', 'legend_wrong', $strip['wrong']) ?></li>
<?php endif; ?>
<?php if ($strip['position'] !== null): ?>
    <li><span class="key now"></span><?= $say('SPOT', 'legend_now', $strip['position']) ?></li>
<?php endif; ?>
    <li><span class="key"></span><?= $say('SPOT', 'legend_todo', $strip['todo']) ?></li>
  </ul>
<?php if ($spotOther && $spotDone): ?>
  <p class="note spot-check" role="alert"><?= $say('SPOT', 'other_done', $spot['owner']) ?> <?= $word('SPOT', 'other_done_text') ?></p>
<?php elseif ($spotOther): ?>
  <p class="note spot-check" role="alert"><?= $say('SPOT', 'other', $spot['owner']) ?> <?= $word('SPOT', 'other_text') ?></p>
<?php elseif ($spotDone): ?>
  <p class="spot-check"><?= $word('SPOT', 'mine_done') ?></p>
<?php elseif ($spot !== null): ?>
  <p class="spot-check"><?= $word('SPOT', 'mine') ?></p>
<?php endif; ?>
<?php if ($strip['mine'] && $strip['next'] !== null && ($spot === null || $spotDone)): ?>
  <p class="actions"><a class="btn primary" href="<?= $e($strip['next']) ?>"><?= $say('SPOT', 'next', $strip['next_position'], $strip['size']) ?> &rarr;</a></p>
<?php elseif ($strip['mine'] && $strip['next'] === null && ($spot === null || $spotDone)): ?>
  <p class="muted"><?= $word('SPOT', 'all_answered') ?></p>
<?php endif; ?>
  <p class="see-also"><?php if ($spot_mode): ?><a href="#decide"><?= $word('SPOT', 'to_answers') ?> &darr;</a> <?php endif; ?><a href="<?= $e($strip['url']) ?>"><?= $word('SPOT', 'back') ?></a></p>
</section>
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
  <?= $explain('set_aside', \CW\Ui\Words::SAMPLE['held']) ?>
</div>
<?php endif; ?>

<?php if ($pending !== null): ?>
<section class="card waiting" aria-labelledby="waiting-h">
  <div class="head-help">
    <h2 id="waiting-h"><?= $word('LISTING', 'waiting') ?></h2>
    <?= $explain('second_ok', \CW\Ui\Words::THING['second']) ?>
  </div>
  <p><?= $partial('pending_decision', ['d' => $pending]) ?></p>
  <p><?= $say('LISTING', 'decided_by', $pending['decider'] ?? \CW\Ui\Words::LISTING['h_computer'], \CW\Ui\Html::when($pending['created_at'])) ?></p>
<?php if ($pending['needs'] !== []): ?>
  <p><span class="muted"><?= $word('LISTING', 'why_second') ?>:</span>
<?php foreach ($pending['needs'] as $need): ?>
    <span class="tag"><?= $word('NEEDS_SECOND', $need) ?></span>
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
<?php endif; ?>

<?php if ($dup_groups !== []): ?>
<p class="muted dup-groups"><?= $word('LISTING', 'dup_link') ?> <?php foreach ($dup_groups as $g): ?><a href="/ui/review/duplicates/<?= $e($g['id']) ?>"><?= $say('LISTING', 'group', $g['number']) ?></a> <?php endforeach; ?></p>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="listing-h">
    <h2 id="listing-h"><?= $word('LISTING', 'on_website') ?></h2>
    <dl>
      <dt><?= $word('LISTING', 'website') ?></dt><dd><?= $e($l['channel_name'] ?? $l['channel']) ?></dd>
      <dt><?= $word('LISTING', 'option') ?></dt><dd><?= $e($l['variant']) ?></dd>
<?php if ($l['brand'] !== null): ?>
      <dt><?= $word('LISTING', 'brand') ?></dt><dd><?= $e($l['brand']) ?></dd>
<?php endif; ?>
<?php if ($l['price'] !== null): ?>
      <dt><?= $word('LISTING', 'price') ?></dt><dd><?php if (is_numeric($l['price'])): ?><?= $money($l['price']) ?><?php else: ?><?= $e($l['price']) ?><?php endif; ?></dd>
<?php endif; ?>
      <dt><?= $word('LISTING', 'sold') ?></dt><dd><?= $say('LISTING', 'sold_line', (int) ($l['units_30d'] ?? 0), (int) ($l['units_365d'] ?? 0)) ?> <span class="muted"><?php if ($l['units_to'] !== null): ?><?= $say('DUPS', 'from_history', \CW\Ui\Html::day($l['units_to'])) ?><?php else: ?><?= $word('DUPS', 'from_profile') ?><?php endif; ?></span></dd>
<?php if ($l['url'] !== null): ?>
      <dt><?= $word('LISTING', 'page') ?></dt><dd><a href="<?= $e($l['url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= $word('LISTING', 'open_page') ?></a></dd>
<?php endif; ?>
<?php foreach ($attributes as $a): ?>
      <dt><?= $e($a['name']) ?></dt><dd><?= $e($a['value']) ?></dd>
<?php endforeach; ?>
      <dt><?= $word('LISTING', 'barcodes') ?></dt><dd><?php if ($listing_barcodes === []): ?><span class="muted"><?= $word('LISTING', 'none') ?></span><?php else: ?><?= $e(implode(', ', $listing_barcodes)) ?><?php endif; ?></dd>
<?php if ($linked_sku !== null): ?>
      <dt><?= $word('LISTING', 'matched_to') ?></dt><dd><a href="/ui/items/<?= $e($linked_sku['id']) ?>"><?= $e($linked_sku['code']) ?></a> <?= $e($linked_sku['name']) ?> (<?= $e(\CW\Ui\Words::saleUses($l['units_per_item'])) ?>)</dd>
<?php endif; ?>
    </dl>
  </section>

  <section class="card" aria-labelledby="item-h">
<?php if ($pending !== null && $pending['action'] === 'new_item'): ?>
    <h2 id="item-h"><?= $word('LISTING', 'new_by_decision') ?></h2>
    <p class="muted"><?= $say('LISTING', 'new_by_decision_text', $pending['sale'] ?? \CW\Ui\Words::saleUses(1)) ?></p>
    <dl>
<?php foreach ($pending['card'] as $f): ?>
      <dt><?= $e($f['label']) ?></dt>
      <dd><?= $e($f['value']) ?></dd>
<?php endforeach; ?>
    </dl>
<?php elseif ($pending !== null && $target === null): ?>
    <h2 id="item-h"><?= $word('LISTING', 'decision_does') ?></h2>
    <p class="title"><?= $partial('pending_decision', ['d' => $pending]) ?></p>
<?php else: ?>
    <h2 id="item-h"><?= $e($target_heading) ?></h2>
<?php if ($pick_note !== null): ?>
    <p class="error" role="alert"><?= $e($pick_note) ?></p>
<?php endif; ?>
<?php if ($target !== null): ?>
    <p class="title"><a href="/ui/items/<?= $e($target['id']) ?>"><?= $e($target['code']) ?></a> <?= $e($target['name']) ?></p>
    <dl>
      <dt><?= $word('LISTING', 'stock_rule') ?></dt><dd><?= $stateChip('POLICY', $target['policy']) ?><?php if ($protected): ?> <span class="muted">(<?= $word('LISTING', 'protected') ?>)</span><?php endif; ?> <?= $explain('stock_rule', \CW\Ui\Words::LISTING['stock_rule']) ?></dd>
      <dt><?= $word('LISTING', 'barcodes') ?></dt><dd><?php if ($target_barcodes === []): ?><span class="muted"><?= $word('LISTING', 'none') ?></span><?php else: ?><?= $e(implode(', ', $target_barcodes)) ?><?php endif; ?></dd>
<?php if ($pending !== null && $pending['action'] === 'merge_skus' && $pending['merge_from_id'] !== null): ?>
      <dt><?= $word('PENDING', 'join') ?></dt><dd><a href="/ui/items/<?= $e($pending['merge_from_id']) ?>"><?= $e($pending['merge_from_code']) ?></a> <?= $e($pending['merge_from_name']) ?> <?= $word('PENDING', 'joins') ?> <?= $e($target['code']) ?></dd>
<?php endif; ?>
    </dl>
<?php if ($previously_rejected): ?>
    <p class="note"><?= $word('LISTING', 'previously_rejected') ?></p>
<?php endif; ?>
<?php elseif ($proposal !== null && $proposal['new_item']): ?>
    <p class="title"><?= $word('LISTING', 'suggest_new') ?></p>
    <p class="muted"><?= $word('LISTING', 'suggest_new_text') ?></p>
<?php else: ?>
    <p class="muted"><?= $word('LISTING', 'no_suggestion') ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($show_search): ?>
    <details class="fold pick" id="f-pick"<?php if ($search_text !== '' || $error_field === 'sku_id'): ?> open<?php endif; ?>>
      <summary><?= $word('LISTING', 'pick_other') ?></summary>
      <form class="filters" method="get" action="/ui/review/listing/<?= $e($l['id']) ?>#f-pick">
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
        <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
        <label><?= $word('UI', 'search_placeholder') ?>
          <input type="search" name="s" value="<?= $e($search_text) ?>" maxlength="100">
        </label>
        <button type="submit"><?= $word('SEARCH', 'button') ?></button>
      </form>
<?php if ($search_text !== '' && $found === []): ?>
      <p class="muted"><?= $word('SEARCH', 'no_items') ?></p>
<?php endif; ?>
<?php if ($found !== []): ?>
      <div class="table-wrap">
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
            <td class="c-next"><a class="btn secondary" href="<?= $u('/ui/review/listing/' . $l['id'], $qq + ['pick' => $s['id']]) ?>#decide"><?= $word('LISTING', 'use') ?></a></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
<?php endif; ?>
    </details>
<?php endif; ?>
  </section>
</div>

<?php if ($elsewhere['items'] !== [] || $elsewhere['listings'] !== []): ?>
<section class="card elsewhere" aria-labelledby="elsewhere-h">
  <h2 id="elsewhere-h"><?= $word('LISTING', 'elsewhere') ?></h2>
  <ul class="plain">
<?php foreach ($elsewhere['items'] as $x): ?>
    <li><?= $word('LISTING', 'elsewhere_item') ?> <a href="/ui/items/<?= $e($x['id']) ?>"><?= $e($x['code']) ?></a> <?= $e($x['name']) ?> <span class="muted">(<?= $e($x['barcode']) ?>)</span></li>
<?php endforeach; ?>
<?php foreach ($elsewhere['listings'] as $x): ?>
    <li><?= $say('LISTING', 'elsewhere_listing', (string) $x['channel']) ?> <a href="/ui/review/listing/<?= $e($x['id']) ?>"><?= $e($x['title'] ?? $x['variant']) ?></a>
      <span class="muted">(<?= $word('LISTING_STATUS', $x['status']) ?><?php if ($x['site_status'] !== null): ?>; <?= $say('LISTING', 'elsewhere_site', $x['site_status']) ?><?php endif; ?><?php if ($x['sku_code'] !== null): ?>; <?= $say('LISTING', 'elsewhere_matched', $x['sku_code']) ?><?php endif; ?>)</span></li>
<?php endforeach; ?>
  </ul>
  <p class="muted"><?= $word('LISTING', 'elsewhere_note') ?></p>
</section>
<?php endif; ?>

<?php if ($early && $showDecide): ?>
<?= $partial('listing_decide', $decide) ?>
<?php endif; ?>

<?php if ($why !== null): ?>
<section class="card why" aria-labelledby="why-h">
  <h2 id="why-h"><?= $word('LISTING', 'why') ?></h2>
  <dl class="why-lines">
    <dt><?= $word('LISTING', 'how_sure') ?></dt><dd><strong><?= $e($why['band']) ?></strong> – <?= $e($why['band_help']) ?> <?= $explain('how_sure', \CW\Ui\Words::THING['band']) ?></dd>
<?php if ($why['barcode'] !== null): ?>
    <dt><?= $word('LISTING', 'barcode') ?></dt><dd><?= $chip($why['barcode']['tone'], $why['barcode']['text']) ?></dd>
<?php endif; ?>
<?php if ($why['ai'] !== null): ?>
    <dt><?= $word('LISTING', 'ai') ?></dt><dd><?= $e($why['ai']) ?></dd>
<?php endif; ?>
<?php if ($why['here'] !== null): ?>
    <dt><?= $word('LISTING', 'here') ?></dt><dd><?= $e($why['here']) ?></dd>
<?php endif; ?>
<?php if ($why['cannot'] !== [] || $why['watch'] !== []): ?>
    <dt><?= $word('LISTING', 'watch') ?></dt><dd><ul class="plain watch">
<?php foreach ($why['cannot'] as $c): ?>
      <li><span class="tag bad"><?= $e($c) ?></span></li>
<?php endforeach; ?>
<?php foreach ($why['watch'] as $w): ?>
      <li><span class="tag warn"><?= $e($w) ?></span></li>
<?php endforeach; ?>
    </ul></dd>
<?php endif; ?>
<?php if ($why['renamed'] !== null): ?>
    <dt><?= $word('LISTING', 'renamed') ?></dt><dd><?= $e($why['renamed']) ?> <?= $word('LISTING', 'renamed_text') ?></dd>
<?php endif; ?>
<?php if ($partners !== []): ?>
    <dt><?= $word('LISTING', 'partners') ?></dt><dd><ul class="plain">
<?php foreach ($partners as $p): ?>
      <li><?php if ($p['sku'] !== null): ?><a href="/ui/items/<?= $e($p['sku']['id']) ?>"><?= $e($p['sku']['code']) ?></a> <?= $e($p['sku']['name']) ?><?php else: ?><?= $e($p['title']) ?> <span class="muted"><?= $word('LISTING', 'not_in_cw') ?></span><?php endif; ?><?php if ($p['pick'] !== null && $show_search): ?> &middot; <a href="<?= $e($p['pick']) ?>"><?= $word('LISTING', 'use') ?></a><?php endif; ?></li>
<?php endforeach; ?>
    </ul></dd>
<?php endif; ?>
<?php if ($proposal['ai']['chosen'] !== null && !$proposal['ai']['chosen']['is_target']): ?>
    <dt><?= $word('LISTING', 'ai_pick') ?></dt><dd><?php if ($proposal['ai']['chosen']['sku'] !== null): ?><a href="/ui/items/<?= $e($proposal['ai']['chosen']['sku']['id']) ?>"><?= $e($proposal['ai']['chosen']['sku']['code']) ?></a> <?= $e($proposal['ai']['chosen']['sku']['name']) ?><?php else: ?><?= $e($proposal['ai']['chosen']['title']) ?> <span class="muted"><?= $word('LISTING', 'not_in_cw') ?></span><?php endif; ?><?php if ($proposal['ai']['chosen']['pick'] !== null && $show_search): ?> &middot; <a href="<?= $e($proposal['ai']['chosen']['pick']) ?>"><?= $word('LISTING', 'use') ?></a><?php endif; ?></dd>
<?php elseif ($proposal['ai']['chosen'] !== null): ?>
    <dt><?= $word('LISTING', 'ai_pick') ?></dt><dd><?php if ($proposal['ai']['chosen']['sku'] !== null): ?><?= $e($proposal['ai']['chosen']['sku']['code']) ?> <?= $e($proposal['ai']['chosen']['sku']['name']) ?> <?php endif; ?><span class="muted"><?= $word('LISTING', 'ai_pick_same') ?></span></dd>
<?php endif; ?>
<?php if ($closest !== null): ?>
    <dt><?= $word('LISTING', 'closest') ?></dt><dd><a href="/ui/items/<?= $e($closest['id']) ?>"><?= $e($closest['code']) ?></a> <?= $e($closest['name']) ?><?php if ($closest_pick !== null && $show_search): ?> &middot; <a href="<?= $e($closest_pick) ?>"><?= $word('LISTING', 'use') ?></a><?php endif; ?></dd>
<?php endif; ?>
<?php if ($why['reason'] !== null): ?>
    <dt><?= $word('LISTING', 'ai_reason') ?></dt><dd><?php foreach ($why['reason'] as $part): ?><?php if ($part['ref']): ?><a href="#cand-<?= $e($part['text']) ?>"><?= $e($part['text']) ?></a><?php else: ?><?= $e($part['text']) ?><?php endif; ?><?php endforeach; ?><?php if ($candidates !== []): ?> <span class="muted">(<?= $word('LISTING', 'ai_refs') ?>)</span><?php endif; ?></dd>
<?php endif; ?>
  </dl>
</section>
<?php endif; ?>

<?php if (!$early && $showDecide): ?>
<?= $partial('listing_decide', $decide) ?>
<?php endif; ?>

<?php if ($compare !== []): ?>
<section aria-labelledby="compare-h">
  <h2 id="compare-h"><?= $word('LISTING', 'compare') ?></h2>
  <div class="table-wrap">
  <table class="stack compare">
    <thead>
      <tr>
        <th scope="col"><?= $word('LISTING', 'compare_what') ?></th>
        <th scope="col"><?= $word('LISTING', 'compare_website') ?></th>
        <th scope="col"><?= $e($target['code'] ?? \CW\Ui\Words::THING['item']) ?></th>
        <th scope="col"><?= $word('LISTING', 'compare_result') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($compare as $r): ?>
      <tr<?php if ($r['state'] === 'differs'): ?> class="differs"<?php endif; ?>>
        <th scope="row" class="c-head"><?= $e($r['label']) ?></th>
        <td data-label="<?= $word('LISTING', 'compare_website') ?>"><?php if ($r['listing'] === ''): ?><span class="muted"><?= $word('LISTING', 'not_stated') ?></span><?php else: ?><?= $e($r['listing']) ?><?php endif; ?></td>
        <td data-label="<?= $e($target['code'] ?? \CW\Ui\Words::THING['item']) ?>"><?php if ($r['item'] === ''): ?><span class="muted"><?= $word('LISTING', 'not_stated') ?></span><?php else: ?><?= $e($r['item']) ?><?php endif; ?></td>
        <td class="state c-status"><?= $stateChip('FIELD_STATE', $r['state']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php elseif ($new_rows !== []): ?>
<section class="card" aria-labelledby="compare-h">
  <h2 id="compare-h"><?= $word('LISTING', 'new_compare') ?></h2>
  <dl>
<?php foreach ($new_rows as $r): ?>
    <dt><?= $e($r['label']) ?></dt><dd><?= $e($r['value']) ?></dd>
<?php endforeach; ?>
  </dl>
</section>
<?php endif; ?>

<?php if ($candidates !== []): ?>
<section aria-labelledby="cand-h">
  <h2 id="cand-h"><?= $word('LISTING', 'candidates') ?></h2>
  <div class="table-wrap">
  <table class="stack candidates">
    <thead>
      <tr><th scope="col"><?= $word('LISTING', 'no') ?></th><th scope="col"><?= $word('LISTING', 'product') ?></th><th scope="col" class="num"><?= $word('LISTING', 'score') ?></th><th scope="col"><?= $word('LISTING', 'problems') ?></th><th scope="col"><span class="visually-hidden"><?= $word('LISTING', 'use') ?></span></th></tr>
    </thead>
    <tbody>
<?php foreach ($candidates as $c): ?>
      <tr<?php if ($c['ref'] !== null): ?> id="cand-<?= $e($c['ref']) ?>"<?php endif; ?><?php if ($c['ai_picked']): ?> class="picked"<?php endif; ?>>
        <th scope="row" class="c-head"><?= $e($c['ref']) ?></th>
        <td data-label="<?= $word('LISTING', 'product') ?>"><?php if ($c['sku'] !== null): ?><a href="/ui/items/<?= $e($c['sku']['id']) ?>"><?= $e($c['sku']['code']) ?></a> <?= $e($c['sku']['name']) ?><?php else: ?><?= $e($c['cw_id']) ?> <span class="muted"><?= $word('LISTING', 'not_in_cw') ?></span><?php endif; ?><?php if ($c['ai_picked']): ?> <span class="tag ok"><?= $word('LISTING', 'tag_ai') ?></span><?php endif; ?><?php if ($c['proposed']): ?> <span class="tag"><?= $word('LISTING', 'tag_suggested') ?></span><?php endif; ?></td>
        <td class="num" data-label="<?= $word('LISTING', 'score') ?>"><?= $e($c['prescore']) ?></td>
        <td data-label="<?= $word('LISTING', 'problems') ?>"><?php foreach ($c['problems'] as $p): ?><span class="tag<?php if ($p['bad']): ?> bad<?php else: ?> warn<?php endif; ?>"><?= $e($p['text']) ?></span> <?php endforeach; ?></td>
        <td class="c-next"><?php if ($c['pick'] !== null && $show_search): ?><a class="btn secondary" href="<?= $e($c['pick']) ?>"><?= $word('LISTING', 'use') ?></a><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if ($proposal !== null || ($target['cwp'] ?? null) !== null): ?>
<details class="fold tech-details">
  <summary><?= $word('LISTING', 'tech') ?></summary>
  <dl class="tech">
<?php if ($proposal !== null): ?>
    <dt><?= $word('LISTING', 'tech_band') ?></dt><dd><code><?= $e($proposal['band']) ?></code><?php if ($proposal['run_band'] !== null): ?> (<?= $word('LISTING', 'tech_run_band') ?> <code><?= $e($proposal['run_band']) ?></code>)<?php endif; ?></dd>
<?php if ($proposal['lane'] !== null): ?>
    <dt><?= $word('LISTING', 'tech_lane') ?></dt><dd><code><?= $e($proposal['lane']) ?></code></dd>
<?php endif; ?>
<?php if ($proposal['band_reasons'] !== []): ?>
    <dt><?= $word('LISTING', 'tech_reasons') ?></dt><dd><code><?= $e(implode(', ', $proposal['band_reasons'])) ?></code></dd>
<?php endif; ?>
<?php if ($proposal['flags'] !== [] || $proposal['lane_flags'] !== []): ?>
    <dt><?= $word('LISTING', 'tech_flags') ?></dt><dd><code><?= $e(implode(', ', [...$proposal['flags'], ...$proposal['lane_flags']])) ?></code></dd>
<?php endif; ?>
<?php if ($proposal['ai']['fields'] !== [] || $proposal['ai']['vetoes'] !== [] || $proposal['ai']['soft'] !== []): ?>
    <dt><?= $word('LISTING', 'tech_fields') ?></dt><dd><code><?= $e(implode(', ', [...$proposal['ai']['fields'], ...$proposal['ai']['vetoes'], ...$proposal['ai']['soft']])) ?></code></dd>
<?php endif; ?>
<?php if ($proposal['ai']['warnings'] !== []): ?>
    <dt><?= $word('LISTING', 'tech_warnings') ?></dt><dd><code><?= $e(implode(', ', $proposal['ai']['warnings'])) ?></code></dd>
<?php endif; ?>
<?php if ($proposal['ai']['roles'] !== []): ?>
    <dt><?= $word('LISTING', 'tech_role') ?></dt><dd><code><?= $e(implode(', ', $proposal['ai']['roles'])) ?></code></dd>
<?php endif; ?>
    <dt><?= $word('LISTING', 'tech_run') ?></dt><dd><code><?= $e($proposal['run']) ?></code><?php if ($proposal['ai']['model'] !== null): ?>, <code><?= $e($proposal['ai']['model']) ?></code><?php endif; ?>, <?= $when($proposal['created_at']) ?></dd>
<?php endif; ?>
<?php if (($target['cwp'] ?? null) !== null): ?>
    <dt><?= $word('LISTING', 'tech_cwp') ?></dt><dd><code><?= $e($target['cwp']) ?></code></dd>
<?php endif; ?>
    <dt><?= $word('LISTING', 'tech_ids') ?></dt><dd><?= $say('LISTING', 'tech_ids_text', '#' . $l['id'], $proposal !== null ? '#' . $proposal['id'] : '-') ?></dd>
  </dl>
</details>
<?php endif; ?>

<?php if ($decisions !== []): ?>
<section aria-labelledby="hist-h">
  <h2 id="hist-h"><?= $word('LISTING', 'history') ?></h2>
  <ol class="plain history">
<?php foreach ($decisions as $d): ?>
<?php if ($d['action'] === 'suggest'): ?>
    <li><?= $when($d['created_at']) ?> · <?php if ($d['sku_code'] !== null): ?><?= $say('LISTING', 'h_suggest', $d['sku_code']) ?><?php else: ?><?= $word('LISTING', 'h_suggest_none') ?><?php endif; ?></li>
<?php continue; endif; ?>
    <li><?= $when($d['created_at']) ?> · <?= $e($d['decider']) ?>: <?= $word('ACTION', $d['action']) ?><?php if ($d['sku_code'] !== null): ?> <?= $e($d['sku_code']) ?><?php endif; ?><?php if ($d['units'] !== null): ?> (<?= $e($d['units']) ?>)<?php endif; ?> – <?= $word('DECISION_STATE', $d['state']) ?>.<?php if ($d['reason'] !== null): ?> <span class="muted"><?= $say('LISTING', 'h_note', $d['reason']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
