<p class="crumbs">
<?php if ($queue_link !== null): ?>
  <a href="<?= $e($queue_link) ?>">&larr; <?= $e($queue_label) ?> queue</a>
<?php elseif ($sample !== null): ?>
  <a href="<?= $e($sample['url']) ?>">&larr; Key spot-check <?= $e($sample['name']) ?></a>
<?php else: ?>
  <a href="/ui/">&larr; Dashboard</a>
<?php endif; ?>
<?php if ($next_link !== null): ?>
  <a class="skip" href="<?= $e($next_link) ?>">Skip to the next listing &rarr;</a>
<?php endif; ?>
</p>

<h1>Listing #<?= $e($l['id']) ?> <span class="status status-<?= $e($l['status']) ?>"><?= $e($l['status']) ?></span></h1>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($spot !== null): ?>
<?php if ($spot['mine']): ?>
<p class="notice spot-check">This proposal is #<?= $n($spot['position']) ?> of <?= $n($spot['size']) ?> in your
  <a href="<?= $e($spot['url']) ?>">Key spot-check <?= $e($spot['name']) ?></a>. Confirm it only if it is right: rejecting it, or any
  other decision, stops the bulk confirm of that spot-check.</p>
<?php else: ?>
<p class="error spot-check" role="alert">This proposal is #<?= $n($spot['position']) ?> of the
  <a href="<?= $e($spot['url']) ?>">Key spot-check <?= $e($spot['name']) ?></a> of <?= $e($spot['owner'] ?? 'another mapping lead') ?>.
  Leave it to them: a decision by anyone else makes the spot-check fail.</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($held !== null): ?>
<p class="notice held-back">Held back from the bulk confirm: <?= $e($held['reason']) ?></p>
<p class="muted held-back">Set aside for one-at-a-time review by <?= $e($held['by'] ?? 'a mapping lead') ?> on <?= $dt($held['at']) ?>
  (<a href="<?= $e($held['url']) ?>">Key spot-check <?= $e($held['sample']) ?></a>, proposal #<?= $e($held['proposal_id']) ?>).
<?php if (!$held['waiting']): ?>
  This listing was decided since; the hold stays on record.</p>
<?php elseif ($held['newer']): ?>
  The hold is on this listing, so it covers its newer proposal too. Decide it here as usual: no bulk confirm links this listing.</p>
<?php else: ?>
  Decide it here as usual: no bulk confirm links this listing.</p>
<?php endif; ?>
<?php endif; ?>

<?php if ($dup_groups !== []): ?>
<p class="muted dup-groups">Duplicates: <?php foreach ($dup_groups as $gid): ?><a href="/ui/review/duplicates/<?= $e($gid) ?>">group <?= $e($gid) ?></a> <?php endforeach; ?>(this page's duplicate suggestions, what was decided, and the undo of a wrong merge).</p>
<?php endif; ?>

<div class="cols">
  <section class="card" aria-labelledby="listing-h">
    <h2 id="listing-h">This listing</h2>
    <p class="title"><?= $e($l['title'] ?? '(no title)') ?></p>
<?php if ($l['variant_title'] !== null): ?>
    <p><?= $e($l['variant_title']) ?></p>
<?php endif; ?>
    <dl>
      <dt>Site</dt><dd><?= $e($l['channel']) ?> <span class="muted"><?= $e($l['channel_name']) ?></span></dd>
      <dt>Variant id</dt><dd><?= $e($l['variant']) ?></dd>
<?php if ($l['brand'] !== null): ?>
      <dt>Brand</dt><dd><?= $e($l['brand']) ?></dd>
<?php endif; ?>
<?php if ($l['price'] !== null): ?>
      <dt>Price</dt><dd><?= $e($l['price']) ?></dd>
<?php endif; ?>
      <dt>Sold</dt><dd><?= $n($l['units_30d'] ?? 0) ?> in 30 days, <?= $n($l['units_365d'] ?? 0) ?> in 365 days</dd>
<?php if ($l['url'] !== null): ?>
      <dt>Page</dt><dd><a href="<?= $e($l['url']) ?>" rel="noopener noreferrer nofollow" target="_blank">open on the site</a></dd>
<?php endif; ?>
<?php foreach ($attributes as $a): ?>
      <dt><?= $e($a['name']) ?></dt><dd><?= $e($a['value']) ?></dd>
<?php endforeach; ?>
      <dt>Barcodes</dt><dd><?php if ($listing_barcodes === []): ?><span class="muted">none</span><?php else: ?><?= $e(implode(', ', $listing_barcodes)) ?><?php endif; ?></dd>
<?php if ($linked_sku !== null): ?>
      <dt>Linked to</dt><dd><a href="/ui/items/<?= $e($linked_sku['id']) ?>"><?= $e($linked_sku['code']) ?></a> <?= $e($linked_sku['name']) ?>, <?= $e($l['units_per_item']) ?> per item</dd>
<?php endif; ?>
    </dl>
  </section>

  <section class="card" aria-labelledby="item-h">
<?php if ($pending !== null && $pending['action'] === 'new_item'): ?>
    <h2 id="item-h">New item this decision creates</h2>
    <p class="muted">Approving it mints this identity card and links the listing to it<?php if ($pending['units'] !== null): ?>, <?= $e($pending['units']) ?> per item<?php endif; ?>.</p>
    <dl>
<?php foreach ($pending['card'] as $f): ?>
      <dt><?= $e($f['label']) ?></dt>
      <dd><?= $e($f['value']) ?></dd>
<?php endforeach; ?>
    </dl>
<?php elseif ($pending !== null && $target === null): ?>
    <h2 id="item-h">What this decision does</h2>
    <p class="title"><?php if ($pending['action'] === 'unlink'): ?>Unlinks the listing<?php elseif ($pending['action'] === 'ignore'): ?>Marks the listing as ignored<?php elseif ($pending['action'] === 'split'): ?>Splits the listing off its merge, to a new item minted from it<?php else: ?><?= $e($pending['action']) ?><?php endif; ?></p>
<?php else: ?>
    <h2 id="item-h"><?= $e($target_heading) ?></h2>
<?php if ($pick_note !== null): ?>
    <p class="error" role="alert"><?= $e($pick_note) ?></p>
<?php endif; ?>
<?php if ($target !== null): ?>
    <p class="title"><a href="/ui/items/<?= $e($target['id']) ?>"><?= $e($target['code']) ?></a> <?= $e($target['name']) ?><?php if ($target['cwp'] !== null): ?> <span class="muted"><?= $e($target['cwp']) ?></span><?php endif; ?></p>
    <dl>
      <dt>Sell policy</dt><dd><?= $e($target['policy']) ?><?php if ($protected): ?> <span class="tag">protected</span><?php endif; ?></dd>
      <dt>Barcodes</dt><dd><?php if ($target_barcodes === []): ?><span class="muted">none</span><?php else: ?><?= $e(implode(', ', $target_barcodes)) ?><?php endif; ?></dd>
<?php if ($pending !== null && $pending['action'] === 'merge_skus' && $pending['merge_from_id'] !== null): ?>
      <dt>Merges into it</dt><dd><a href="/ui/items/<?= $e($pending['merge_from_id']) ?>"><?= $e($pending['merge_from_code']) ?></a> <?= $e($pending['merge_from_name']) ?></dd>
<?php endif; ?>
    </dl>
<?php if ($previously_rejected): ?>
    <p class="note">This listing was rejected for this item (or an item merged into it) before, so linking it needs a second person.</p>
<?php endif; ?>
<?php elseif ($proposal !== null && $proposal['new_item']): ?>
    <p class="title">The run proposes a new item.</p>
    <p class="muted">Its card is filled in from this listing below; you can edit it before saving.</p>
<?php else: ?>
    <p class="muted">No item is proposed. Search below or pick one of the candidates.</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($pending === null): ?>
    <p><a href="#search">Choose other item</a></p>
<?php endif; ?>
  </section>
</div>

<?php if ($elsewhere['items'] !== [] || $elsewhere['listings'] !== []): ?>
<section class="card elsewhere" aria-labelledby="elsewhere-h">
  <h2 id="elsewhere-h">This listing's barcode is also on</h2>
  <ul class="plain">
<?php foreach ($elsewhere['items'] as $x): ?>
    <li>item <a href="/ui/items/<?= $e($x['id']) ?>"><?= $e($x['code']) ?></a> <?= $e($x['name']) ?> <span class="muted">(<?= $e($x['barcode']) ?>)</span></li>
<?php endforeach; ?>
<?php foreach ($elsewhere['listings'] as $x): ?>
    <li><?= $e($x['channel']) ?> listing <a href="/ui/review/listing/<?= $e($x['id']) ?>"><?= $e($x['variant']) ?></a> <?= $e($x['title']) ?>
      <span class="muted">(<?= $e($x['status']) ?><?php if ($x['site_status'] !== null): ?>, on the site: <?= $e($x['site_status']) ?><?php endif; ?><?php if ($x['sku_code'] !== null): ?>, linked to <?= $e($x['sku_code']) ?><?php endif; ?>)</span></li>
<?php endforeach; ?>
  </ul>
  <p class="muted">A new item for this listing may duplicate one of these: check them before creating it.</p>
</section>
<?php endif; ?>

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
  <button type="submit" class="primary">Confirm link to <?= $e($target['code']) ?><?php if ($form['units'] !== '1'): ?> (<?= $e($form['units']) ?> per item)<?php endif; ?><?php if ($next_link !== null): ?> and open the next listing<?php endif; ?></button>
  <span class="muted">or read on, and decide below.</span>
</form>
<?php endif; ?>

<section aria-labelledby="compare-h">
  <h2 id="compare-h">What is the same and what differs</h2>
  <table class="compare">
    <thead>
      <tr>
        <th scope="col">Field</th>
        <th scope="col">Listing</th>
        <th scope="col"><?php if ($target !== null): ?><?= $e($target['code']) ?><?php else: ?>Item<?php endif; ?></th>
        <th scope="col"><span class="visually-hidden">Result</span></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($target !== null ? $compare : $card_rows as $r): ?>
      <tr<?php if ($r['state'] === 'differs'): ?> class="differs"<?php endif; ?>>
        <th scope="row"><?= $e($r['label']) ?></th>
        <td><?= $e($r['listing']) ?></td>
        <td><?= $e($r['item']) ?></td>
        <td class="state"><?php if ($r['state'] === 'differs'): ?>differs<?php elseif ($r['state'] === 'same'): ?>same<?php elseif ($r['state'] === 'alike'): ?>spelt differently<?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($proposal !== null): ?>
<section aria-labelledby="ai-h">
  <h2 id="ai-h">What the matching run says</h2>
  <dl class="wide">
    <dt>Band</dt><dd><strong><?= $e($proposal['band_label']) ?></strong><?php if ($proposal['run_band'] !== null): ?> <span class="muted">(the run says <?= $e($proposal['run_band']) ?>)</span><?php endif; ?><?php if ($proposal['lane'] !== null): ?> <span class="muted">lane <?= $e($proposal['lane']) ?></span><?php endif; ?></dd>
<?php if ($proposal['relabel'] !== null): ?>
    <dt>Rename</dt><dd><span class="tag warn">relabel pending</span> <?= $e($proposal['relabel']) ?>. <span class="muted">The two sites name this line differently; no alias for it is confirmed yet.</span></dd>
<?php endif; ?>
<?php if ($partners !== []): ?>
    <dt>Paired items</dt><dd><ul class="plain">
<?php foreach ($partners as $p): ?>
      <li><?php if ($p['sku'] !== null): ?><a href="/ui/items/<?= $e($p['sku']['id']) ?>"><?= $e($p['sku']['code']) ?></a> <?= $e($p['sku']['name']) ?><?php else: ?><?= $e($p['cw_id']) ?> <?= $e($p['title']) ?> <span class="muted">not in CW</span><?php endif; ?><?php if ($p['pick'] !== null): ?> &middot; <a href="<?= $e($p['pick']) ?>">use this item</a><?php endif; ?></li>
<?php endforeach; ?>
    </ul></dd>
<?php endif; ?>
<?php if ($proposal['ai']['outcome'] !== null): ?>
    <dt>AI outcome</dt><dd><?= $e($proposal['ai']['outcome']) ?><?php if ($proposal['ai']['confidence'] !== null): ?> at <?= $e($proposal['ai']['confidence']) ?>% confidence<?php endif; ?><?php if ($proposal['ai']['units'] !== null): ?>, <?= $e($proposal['ai']['units']) ?> per item<?php endif; ?></dd>
<?php endif; ?>
<?php if ($proposal['ai']['chosen'] !== null): ?>
    <dt>AI picked</dt><dd><?php if ($proposal['ai']['chosen']['sku'] !== null): ?><a href="/ui/items/<?= $e($proposal['ai']['chosen']['sku']['id']) ?>"><?= $e($proposal['ai']['chosen']['sku']['code']) ?></a> <?= $e($proposal['ai']['chosen']['sku']['name']) ?><?php else: ?><?= $e($proposal['ai']['chosen']['cw_id']) ?> <?= $e($proposal['ai']['chosen']['title']) ?> <span class="muted">not in CW</span><?php endif; ?><?php if ($proposal['ai']['chosen']['is_target']): ?> <span class="muted">(the item shown above)</span><?php elseif ($proposal['ai']['chosen']['pick'] !== null): ?> &middot; <a href="<?= $e($proposal['ai']['chosen']['pick']) ?>">use this item</a><?php endif; ?></dd>
<?php endif; ?>
<?php if ($proposal['ai']['reason'] !== null): ?>
    <dt>Reason</dt><dd><?= $e($proposal['ai']['reason']) ?> <span class="muted">(C1, C2, &hellip; are the rows of the candidates table below)</span></dd>
<?php endif; ?>
<?php if ($proposal['ai']['fields'] !== []): ?>
    <dt>Fields that do not agree</dt><dd><?php foreach ($proposal['ai']['fields'] as $f): ?><span class="tag<?php if ($f['state'] === 'conflict'): ?> bad<?php endif; ?>"><?= $e($f['field']) ?><?php if ($f['state'] !== null): ?>: <?= $e($f['state']) ?><?php endif; ?></span> <?php endforeach; ?></dd>
<?php endif; ?>
<?php if ($proposal['band_reasons'] !== []): ?>
    <dt>Band reasons</dt><dd><?= $e(implode(', ', $proposal['band_reasons'])) ?></dd>
<?php endif; ?>
<?php if ($proposal['blocks'] !== []): ?>
    <dt>Blocks a link</dt><dd><?php foreach ($proposal['blocks'] as $f): ?><span class="tag bad">veto: <?= $e($f['flag']) ?> <span class="muted">(<?= $e($f['on']) ?>)</span></span> <?php endforeach; ?></dd>
<?php endif; ?>
<?php if ($proposal['checks'] !== []): ?>
    <dt>Check</dt><dd><?php foreach ($proposal['checks'] as $f): ?><span class="tag warn"><?= $e($f['flag']) ?> <span class="muted">(<?= $e($f['on']) ?>)</span></span> <?php endforeach; ?></dd>
<?php endif; ?>
<?php if ($proposal['other_flags'] !== []): ?>
    <dt>Other flags</dt><dd><?php foreach ($proposal['other_flags'] as $flag): ?><span class="tag"><?= $e($flag) ?></span> <?php endforeach; ?></dd>
<?php endif; ?>
<?php if ($proposal['ai']['warnings'] !== []): ?>
    <dt>AI warnings</dt><dd><?= $e(implode(', ', $proposal['ai']['warnings'])) ?></dd>
<?php endif; ?>
<?php if ($closest !== null): ?>
    <dt>Closest item</dt><dd><a href="/ui/items/<?= $e($closest['id']) ?>"><?= $e($closest['code']) ?></a> <?= $e($closest['name']) ?><?php if ($closest_pick !== null): ?> &middot; <a href="<?= $e($closest_pick) ?>">use this item</a><?php endif; ?></dd>
<?php endif; ?>
    <dt>Run</dt><dd class="muted"><?= $e($proposal['run']) ?><?php if ($proposal['ai']['model'] !== null): ?>, <?= $e($proposal['ai']['model']) ?><?php endif; ?>, <?= $dt($proposal['created_at']) ?></dd>
  </dl>
<?php if ($candidates !== []): ?>
  <h3>Candidates the judge saw</h3>
  <table class="candidates">
    <thead>
      <tr><th scope="col">Ref</th><th scope="col">Item</th><th scope="col">Role</th><th scope="col" class="num">Score</th><th scope="col">Concerns</th><th scope="col"><span class="visually-hidden">Use</span></th></tr>
    </thead>
    <tbody>
<?php foreach ($candidates as $c): ?>
      <tr<?php if ($c['ai_picked']): ?> class="picked"<?php endif; ?>>
        <th scope="row"><?= $e($c['ref']) ?></th>
        <td><?php if ($c['sku'] !== null): ?><a href="/ui/items/<?= $e($c['sku']['id']) ?>"><?= $e($c['sku']['code']) ?></a> <?= $e($c['sku']['name']) ?><?php else: ?><?= $e($c['cw_id']) ?> <span class="muted">not in CW</span><?php endif; ?><?php if ($c['ai_picked']): ?> <span class="tag ok">AI picked</span><?php endif; ?><?php if ($c['proposed']): ?> <span class="tag">proposed</span><?php endif; ?></td>
        <td><?= $e($c['role']) ?></td>
        <td class="num"><?= $e($c['prescore']) ?></td>
        <td><?php foreach ($c['vetoes'] as $flag): ?><span class="tag bad">veto: <?= $e($flag) ?></span> <?php endforeach; ?><?php foreach ($c['soft'] as $flag): ?><span class="tag warn"><?= $e($flag) ?></span> <?php endforeach; ?></td>
        <td><?php if ($c['pick'] !== null): ?><a href="<?= $e($c['pick']) ?>">Use this item</a><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($pending !== null): ?>
<section class="card waiting" aria-labelledby="waiting-h">
  <h2 id="waiting-h">Waiting for a second person</h2>
  <p><?= $partial('pending_decision', ['d' => $pending]) ?></p>
  <p>Decided by <?= $e($pending['decider']) ?> on <?= $dt($pending['created_at']) ?>.
<?php foreach ($pending['needs'] as $need): ?>
    <span class="tag"><?= $e($need) ?></span>
<?php endforeach; ?>
  </p>
<?php if ($pending['reason'] !== null): ?>
  <p class="muted">Reason: <?= $e($pending['reason']) ?></p>
<?php endif; ?>
<?php foreach ($pending['stale'] as $why): ?>
  <p class="error"><?= $e($why) ?> Withdraw it and decide again.</p>
<?php endforeach; ?>
  <div class="actions"><?= $partial('pending_actions', ['id' => $pending['id'], 'from' => 'listing', 'can_approve' => $pending['can_approve'], 'can_withdraw' => $pending['can_withdraw'], 'own' => $pending['own']]) ?></div>
</section>
<?php endif; ?>

<section id="decide" aria-labelledby="decide-h">
  <h2 id="decide-h">Decide</h2>
<?php if ($no_form !== null): ?>
  <p class="note"><?= $e($no_form) ?></p>
<?php else: ?>
  <form class="decide" method="post" action="/ui/review/listing/<?= $e($l['id']) ?>/decide">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="expected_map_version" value="<?= $e($l['map_version']) ?>">
<?php if ($proposal !== null): ?>
    <input type="hidden" name="proposal_id" value="<?= $e($proposal['id']) ?>">
<?php endif; ?>
    <input type="hidden" name="sku_id" value="<?= $e($form['sku_id']) ?>">
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
    <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
    <fieldset>
      <legend>What should happen to this listing?</legend>
      <label class="choice"><input type="radio" name="action" value="link"<?php if ($form['action'] === 'link'): ?> checked<?php endif; ?><?php if (!$can_link): ?> disabled<?php endif; ?>>
        Confirm link<?php if ($target !== null): ?> to <?= $e($target['code']) ?><?php endif; ?></label>
      <label class="choice"><input type="radio" name="action" value="new_item"<?php if ($form['action'] === 'new_item'): ?> checked<?php endif; ?>>
        Mark as a new item</label>
      <label class="choice"><input type="radio" name="action" value="ignore"<?php if ($form['action'] === 'ignore'): ?> checked<?php endif; ?>>
        Ignore (not a product to stock; say why below)</label>
      <label class="choice"><input type="radio" name="action" value="reject"<?php if ($form['action'] === 'reject'): ?> checked<?php endif; ?><?php if ($target === null): ?> disabled<?php endif; ?>>
        Reject this proposal (it is not this item)</label>
    </fieldset>

    <label>Units per item
      <input type="number" id="units" name="units_per_item" min="1" max="<?= $e($max_units) ?>" value="<?= $e($form['units']) ?>" required>
    </label>
    <p id="note-units" class="note"<?php if ($form['units'] === '1'): ?> hidden<?php endif; ?>>Units per item other than 1: a second person (a mapping lead) has to approve this before it takes effect.</p>
<?php if ($protected): ?>
    <p class="note">This item is protected (sell policy <?= $e($target['policy']) ?>): a second person (a mapping lead) has to approve a link to it.</p>
<?php endif; ?>

    <label>Reason (needed to ignore; optional otherwise)
      <input type="text" name="reason" maxlength="<?= $e($max_reason) ?>" value="<?= $e($form['reason']) ?>">
    </label>

    <details<?php if ($form['action'] === 'new_item' || $error_field !== null && str_starts_with($error_field, 'card.')): ?> open<?php endif; ?>>
      <summary>Identity card of the new item (only used for &ldquo;Mark as a new item&rdquo;)</summary>
<?php foreach ($card_fields as $f): ?>
      <label><?= $e($f === 'name' ? 'Name' : $labels[$f]) ?>
        <input type="text" name="card_<?= $e($f) ?>" value="<?= $e($form['card_' . $f]) ?>" maxlength="300">
      </label>
<?php endforeach; ?>
    </details>

    <p><button type="submit" class="primary">Save decision</button>
<?php if ($next_link !== null): ?>
      <span class="muted">then the next listing in the queue opens</span>
<?php endif; ?>
    </p>
  </form>
<?php endif; ?>
</section>

<section id="search" aria-labelledby="search-h">
  <h2 id="search-h">Choose another item</h2>
  <form class="filters" method="get" action="/ui/review/listing/<?= $e($l['id']) ?>#search">
<?php foreach ($qq as $key => $value): ?>
<?php if ($value !== null && $value !== ''): ?>
    <input type="hidden" name="<?= $e($key) ?>" value="<?= $e($value) ?>">
<?php endif; ?>
<?php endforeach; ?>
    <label>Name, barcode or CW code
      <input type="search" name="s" value="<?= $e($search_text) ?>" maxlength="100">
    </label>
    <button type="submit">Search items</button>
  </form>
<?php if ($search_text !== '' && $found === []): ?>
  <p class="muted">No item matches.</p>
<?php endif; ?>
<?php if ($found !== []): ?>
  <table>
    <thead>
      <tr><th scope="col">Item</th><th scope="col">Card</th><th scope="col">Barcodes</th><th scope="col"><span class="visually-hidden">Use</span></th></tr>
    </thead>
    <tbody>
<?php foreach ($found as $s): ?>
      <tr>
        <td><a href="/ui/items/<?= $e($s['id']) ?>"><?= $e($s['code']) ?></a> <?= $e($s['name']) ?><?php if ($s['cwp'] !== null): ?> <span class="muted"><?= $e($s['cwp']) ?></span><?php endif; ?><?php if ($s['policy'] !== 'legacy'): ?> <span class="tag">protected</span><?php endif; ?></td>
        <td class="muted"><?= $e(implode(' / ', array_filter([$s['brand'], $s['strength_mg'], $s['line'], $s['flavour'], $s['volume_ml']], static fn (?string $v): bool => $v !== null))) ?></td>
        <td class="muted"><?= $e(implode(', ', $s['barcodes'])) ?></td>
        <td><a href="<?= $u('/ui/review/listing/' . $l['id'], $qq + ['pick' => $s['id']]) ?>#decide">Use this item</a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<?php if ($decisions !== []): ?>
<section aria-labelledby="hist-h">
  <h2 id="hist-h">Earlier decisions on this listing</h2>
  <table>
    <thead>
      <tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Item</th><th scope="col">State</th><th scope="col">Reason</th></tr>
    </thead>
    <tbody>
<?php foreach ($decisions as $d): ?>
      <tr>
        <td><?= $dt($d['created_at']) ?></td>
        <td><?= $e($d['decider']) ?></td>
        <td><?= $e($d['action']) ?></td>
        <td><?= $e($d['sku_code']) ?><?php if ($d['units'] !== null): ?> <span class="muted">x<?= $e($d['units']) ?></span><?php endif; ?></td>
        <td><?= $e($d['state']) ?></td>
        <td><?= $e($d['reason']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
