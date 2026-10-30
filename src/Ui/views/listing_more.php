<?php
// What a store product's page holds beyond the approved design (TP_Match; docs/decisions.md W12.2), under the bar's one More: each a fold of
// the menu (no script needed), in this order: why the computer suggests it, more details of the two products (and why a second OK will be
// needed: the item card's small tags say that it will), where the store product's
// barcode is too, its duplicate groups, the spot check it is in, its history, the technical details. Nothing is left out of the page.
?>
<?php if ($why !== null): ?>
<details class="doc-act mt-why" id="why">
  <summary class="btn"><?= $word('LISTING', 'why') ?></summary>
  <div class="doc-act-panel">
  <dl class="why-lines">
    <dt><?= $word('LISTING', 'how_sure') ?></dt><dd><strong><?= $e($why['band']) ?></strong> – <?= $e($why['band_help']) ?></dd>
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
      <li><span class="tag blocked"><?= $e($c) ?></span></li>
<?php endforeach; ?>
<?php foreach ($why['watch'] as $w): ?>
      <li><span class="tag needs"><?= $e($w) ?></span></li>
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
    <dt><?= $word('LISTING', 'ai_reason') ?></dt><dd><?php foreach ($why['reason'] as $part): ?><?php if ($part['ref']): ?><a href="#cand-<?= $e($part['text']) ?>"><?= $e($part['text']) ?></a><?php else: ?><?= $e($part['text']) ?><?php endif; ?><?php endforeach; ?></dd>
<?php endif; ?>
  </dl>
  </div>
</details>
<?php endif; ?>
<details class="doc-act mt-details" id="mt-details">
  <summary class="btn"><?= $word('MATCH', 'store_more') ?></summary>
  <div class="doc-act-panel">
  <dl class="why-lines">
    <dt><?= $word('LISTING', 'website') ?></dt><dd><?= $e($l['channel_name'] ?? $l['channel']) ?> · <?= $word('LISTING_STATUS', $l['status']) ?></dd>
    <dt><?= $word('LISTING', 'sold') ?></dt><dd><?= $say('LISTING', 'sold_line', (int) ($l['units_30d'] ?? 0), (int) ($l['units_365d'] ?? 0)) ?> <span class="muted"><?php if ($l['units_to'] !== null): ?><?= $say('DUPS', 'from_history', \CW\Ui\Html::day($l['units_to'])) ?><?php else: ?><?= $word('DUPS', 'from_profile') ?><?php endif; ?></span></dd>
<?php if ($l['url'] !== null): ?>
    <dt><?= $word('LISTING', 'page') ?></dt><dd><a href="<?= $e($l['url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= $word('LISTING', 'open_page') ?></a></dd>
<?php endif; ?>
<?php foreach ($attributes as $a): ?>
    <dt><?= $e($a['name']) ?></dt><dd><?= $e($a['value']) ?></dd>
<?php endforeach; ?>
<?php if ($linked_sku !== null): ?>
    <dt><?= $word('LISTING', 'matched_to') ?></dt><dd><a href="/ui/items/<?= $e($linked_sku['id']) ?>"><?= $e($linked_sku['code']) ?></a> <?= $e($linked_sku['name']) ?> (<?= $e(\CW\Ui\Words::saleUses($l['units_per_item'])) ?>)</dd>
<?php endif; ?>
<?php if ($target !== null && ($target['family'] ?? null) !== null): ?>
    <dt><?= $word('FAMILY', 'item_box_short') ?></dt><dd><?= $e($target['family']) ?></dd>
<?php endif; ?>
<?php if ($target !== null): ?>
    <dt><?= $word('LISTING', 'stock_rule') ?></dt><dd><?= $stateChip('POLICY', $target['policy']) ?><?php if ($protected): ?> <span class="muted">(<?= $word('LISTING', 'protected') ?>)</span><?php endif; ?></dd>
<?php endif; ?>
  </dl>
<?php if ($protected): ?>
  <p><?= $word('LISTING', 'protected_note') ?></p>
<?php endif; ?>
<?php if ($previously_rejected): ?>
  <p><?= $word('LISTING', 'previously_rejected') ?></p>
<?php endif; ?>
  </div>
</details>
<?php if ($elsewhere['items'] !== [] || $elsewhere['listings'] !== []): ?>
<details class="doc-act mt-elsewhere" id="elsewhere">
  <summary class="btn"><?= $word('LISTING', 'elsewhere') ?></summary>
  <div class="doc-act-panel">
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
  </div>
</details>
<?php endif; ?>
<?php foreach ($dup_groups as $g): ?>
<a class="menu-item" href="/ui/review/duplicates/<?= $e($g['id']) ?>"><?= $word('LISTING', 'dup_link') ?> <?= $say('LISTING', 'group', $g['number']) ?></a>
<?php endforeach; ?>
<?php if ($strip !== null): ?>
<details class="doc-act mt-spot" id="spot">
  <summary class="btn"><?= $word('MATCH', 'spot') ?></summary>
  <div class="doc-act-panel">
  <p><?php if ($strip['position'] !== null): ?><?= $say('SPOT', 'box', $strip['name'], $strip['position'], $strip['size']) ?><?php else: ?><?= $say('SAMPLE', 'title', $strip['name']) ?><?php endif; ?></p>
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
<?php if ($strip['mine'] && $strip['next'] === null): ?>
  <p class="muted"><?= $word('SPOT', 'all_answered') ?></p>
<?php endif; ?>
  <p><a href="<?= $e($strip['url']) ?>"><?= $word('SPOT', 'back') ?></a></p>
  </div>
</details>
<?php endif; ?>
<?php if ($decisions !== []): ?>
<details class="doc-act mt-history" id="hist">
  <summary class="btn"><?= $word('LISTING', 'history') ?></summary>
  <div class="doc-act-panel">
  <ol class="plain history">
<?php foreach ($decisions as $d): ?>
<?php if ($d['action'] === 'suggest'): ?>
    <li><?= $when($d['created_at']) ?> · <?php if ($d['sku_code'] !== null): ?><?= $say('LISTING', 'h_suggest', $d['sku_code']) ?><?php else: ?><?= $word('LISTING', 'h_suggest_none') ?><?php endif; ?></li>
<?php continue; endif; ?>
    <li><?= $when($d['created_at']) ?> · <?= $e($d['decider']) ?>: <?= $word('ACTION', $d['action']) ?><?php if ($d['sku_code'] !== null): ?> <?= $e($d['sku_code']) ?><?php endif; ?><?php if ($d['units'] !== null): ?> (<?= $e($d['units']) ?>)<?php endif; ?> – <?= $word('DECISION_STATE', $d['state']) ?>.<?php if ($d['reason'] !== null): ?> <span class="muted"><?= $say('LISTING', 'h_note', $d['reason']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
  </ol>
  </div>
</details>
<?php endif; ?>
<?php if ($proposal !== null || ($target['cwp'] ?? null) !== null): ?>
<details class="doc-act tech-details">
  <summary class="btn"><?= $word('LISTING', 'tech') ?></summary>
  <div class="doc-act-panel">
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
  </div>
</details>
<?php endif; ?>
