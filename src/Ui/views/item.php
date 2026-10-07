<h1><?= $e($sku['code']) ?> <span class="muted"><?= $e($sku['name']) ?></span></h1>

<?php if ($merged_into !== null): ?>
<p class="note">This item was merged into <a href="/ui/items/<?= $e($merged_into['id']) ?>"><?= $e($merged_into['code']) ?></a> <?= $e($merged_into['name']) ?>. Its listings and stock moved there
  (what its own orders in flight still needed stays here until they ship).</p>
<?php endif; ?>
<?php if ($merged_from !== []): ?>
<p class="note">Duplicate pages share this warehouse item: <?php foreach ($merged_from as $m): ?><a href="/ui/items/<?= $e($m['id']) ?>"><?= $e($m['code']) ?></a> <?php endforeach; ?>was merged into it.
  On the website they stay separate pages with their own price and reviews until Vape and Go switches to the warehouse system.</p>
<?php endif; ?>
<?php if ($dup_groups !== []): ?>
<p class="muted dup-groups">Duplicates: <?php foreach ($dup_groups as $gid): ?><a href="/ui/review/duplicates/<?= $e($gid) ?>">group <?= $e($gid) ?></a> <?php endforeach; ?>(what was decided, and the undo of a wrong merge).</p>
<?php endif; ?>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?><?php if ($errorCode === 'barcode_on_other_item' && isset($errorDetail['sku_id'])): ?>
  <a href="<?= $u('/ui/items/' . (int) $errorDetail['sku_id']) ?>">Open <?= $e($errorDetail['code'] ?? 'that item') ?></a><?php endif; ?></p>
<?php endif; ?>

<section class="card item-card<?php if ($rules['level'] === 'block'): ?> blocked<?php elseif ($rules['level'] === 'warn'): ?> warned<?php endif; ?>" id="card" aria-labelledby="card-h">
  <h2 id="card-h">Item card <span class="muted">legal and buying fields</span></h2>
<?php if ($card['state'] === 'none'): ?>
  <p class="card-state"><span class="tag">no card yet</span> Nothing has been entered for this item yet.</p>
<?php elseif ($card['state'] === 'confirmed'): ?>
  <p class="card-state"><span class="tag ok">confirmed</span> by <?= $e($card['confirmed_by']) ?> on <?= $dt($card['confirmed_at']) ?> (UTC).</p>
<?php elseif ($card['state'] === 'changed'): ?>
  <p class="card-state"><span class="tag warn">changed since it was confirmed</span> Last changed by <?= $e($card['updated_by']) ?> on <?= $dt($card['updated_at']) ?>: confirm it again.
    A rule the last confirmation blocked keeps blocking the item until then, whatever the fields say now; a rule broken since is a warning until then.</p>
<?php else: ?>
  <p class="card-state"><span class="tag warn">not confirmed</span> Last changed by <?= $e($card['updated_by']) ?> on <?= $dt($card['updated_at']) ?>. Until a person confirms
    the fields, a rule they break is a warning only.</p>
<?php endif; ?>
<?php if ($rules['blocked'] !== []): ?>
  <div class="rules block" role="status">
    <p><strong>BLOCKED: <?= $e($rules['blockEffect']) ?></strong></p>
    <ul class="plain">
<?php foreach ($rules['blocked'] as $b): ?>
      <li><span class="tag bad"><?= $e($b['label']) ?></span> <?= $e($b['why']) ?><?php if (!$b['still']): ?> <span class="muted">(the fields no longer say so: the block stays until someone confirms the card again)</span><?php endif; ?></li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($rules['warnings'] !== []): ?>
  <div class="rules warn" role="status">
    <p><strong>Warning: if these fields are right, confirming them blocks the item.</strong></p>
    <ul class="plain">
<?php foreach ($rules['warnings'] as $b): ?>
      <li><span class="tag warn"><?= $e($b['label']) ?></span> <?= $e($b['why']) ?></li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
  <dl class="item-card-fields">
<?php foreach ($card['values'] as $f): ?>
    <dt><?= $e(ucfirst($f['label'])) ?></dt>
    <dd><?php if ($f['shown'] === null): ?><span class="muted">not known</span><?php else: ?><?= $e($f['shown']) ?><?php endif; ?></dd>
<?php endforeach; ?>
  </dl>
<?php foreach ($rules['advice'] as $a): ?>
  <p class="note"><?= $e($a) ?></p>
<?php endforeach; ?>
<?php if ($canEditCard): ?>
  <p class="actions"><a class="button" href="<?= $u('/ui/items/' . $sku['id'] . '/card') ?>"><?php if ($card['exists']): ?>Change the item card<?php else: ?>Fill in the item card<?php endif; ?></a></p>
<?php if ($rules['missing'] !== [] && $card['state'] !== 'confirmed'): ?>
  <p class="muted">Before the card can be confirmed, fill in: <?= $e(implode(', ', $rules['missing'])) ?>.</p>
<?php elseif ($confirmKey !== null): ?>
  <form class="confirm-card" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/confirm') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($confirmKey) ?>">
    <input type="hidden" name="version" value="<?= $e($card['version']) ?>">
<?php if ($rules['warnings'] !== [] || array_filter($rules['blocked'], static fn (array $b): bool => $b['still']) !== []): ?>
    <label class="choice dup-confirm"><input type="checkbox" name="acknowledge_block" value="1"> I checked the packaging: these fields are right, and the item breaks the rules
      above, so it will be blocked.</label>
<?php endif; ?>
    <button type="submit">Confirm the card: these fields are right</button>
  </form>
<?php endif; ?>
<?php if ($fileFlavour !== null): ?>
  <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/accept') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($fileFlavour['formKey']) ?>">
    <input type="hidden" name="version" value="<?= $e($card['version']) ?>">
    <input type="hidden" name="field" value="flavour">
    <input type="hidden" name="value" value="<?= $e($fileFlavour['value']) ?>">
    <span>The flavour "<?= $e($fileFlavour['value']) ?>" came from a file.</span> <button type="submit">Confirm this flavour</button>
  </form>
<?php endif; ?>
<?php if ($proposals !== null): ?>
  <h3>Suggestions</h3>
<?php if ($proposals === []): ?>
  <p class="muted">Nothing to suggest: the matcher and the linked listings say nothing the card does not already have.</p>
<?php else: ?>
  <p class="muted">From the item's identity card and its linked listings. Nothing is used until you press "Use this"<?php if ($disagree !== []): ?>; the sources
    disagree on the <?= $e(implode(', ', $disagree)) ?>: check the box<?php endif; ?>.</p>
  <div class="scroll">
  <table class="stack proposals">
    <thead><tr><th scope="col">Field</th><th scope="col">Suggestion</th><th scope="col">Says so</th><th scope="col"><span class="visually-hidden">Use</span></th></tr></thead>
    <tbody>
<?php foreach ($proposals as $p): ?>
      <tr>
        <td data-label="Field"><?= $e($p['label']) ?></td>
        <td data-label="Suggestion"><strong><?= $e($p['shown']) ?></strong></td>
        <td data-label="Says so"><ul class="plain"><?php foreach ($p['sources'] as $src): ?><li><?= $e($src) ?></li><?php endforeach; ?></ul></td>
        <td>
          <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/card/accept') ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="form_key" value="<?= $e($p['formKey']) ?>">
            <input type="hidden" name="version" value="<?= $e($card['version']) ?>">
            <input type="hidden" name="field" value="<?= $e($p['field']) ?>">
            <input type="hidden" name="value" value="<?= $e($p['value']) ?>">
            <button type="submit">Use this</button>
          </form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
<?php if ($disposableSources !== []): ?>
  <p class="note">Called a "disposable" by: <?= $e(implode('; ', $disposableSources)) ?>. Whether it is SINGLE-USE is for a person to answer from the box (many
    "disposable-style" devices sold since June 2025 are rechargeable and refillable): it is never filled in from this.</p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($cardHistory !== []): ?>
  <details class="card-history">
    <summary>History of the card (<?= $n(count($cardHistory)) ?>)</summary>
    <ol class="plain">
<?php foreach ($cardHistory as $h): ?>
      <li>Version <?= $e($h['version']) ?> · <?= $dt($h['at']) ?> · <?= $e($h['who']) ?>: <?= $e($h['what']) ?></li>
<?php endforeach; ?>
    </ol>
  </details>
<?php endif; ?>
</section>

<div class="cols">
  <section class="card" aria-labelledby="id-h">
    <h2 id="id-h">Identity</h2>
    <dl>
      <dt>Brand</dt><dd><?= $e($sku['brand']) ?></dd>
      <dt>Strength (mg)</dt><dd><?= $e($sku['strength_mg']) ?></dd>
      <dt>Nicotine type</dt><dd><?= $e($sku['nic_type']) ?></dd>
      <dt>Range / line</dt><dd><?= $e($sku['line']) ?></dd>
      <dt>Form</dt><dd><?= $e($sku['form']) ?></dd>
      <dt>Flavour</dt><dd><?= $e($sku['flavour']) ?></dd>
      <dt>Volume (ml)</dt><dd><?= $e($sku['volume_ml']) ?></dd>
      <dt>Puffs</dt><dd><?= $e($sku['puffs']) ?></dd>
      <dt>Pack units</dt><dd><?= $e($sku['pack_units']) ?></dd>
      <dt>Sell policy</dt><dd><?= $e($sku['policy']) ?><?php if ($sku['policy'] !== 'legacy'): ?> <span class="tag">protected</span><?php endif; ?></dd>
      <dt>Origin</dt><dd><?= $e($sku['origin']) ?><?php if ($sku['origin_listing_id'] !== null): ?> <a href="/ui/review/listing/<?= $e($sku['origin_listing_id']) ?>">listing #<?= $e($sku['origin_listing_id']) ?></a><?php endif; ?><?php if ($sku['cwp'] !== null): ?> <span class="muted">(<?= $e($sku['cwp']) ?> in the first-match files)</span><?php endif; ?></dd>
      <dt>Created</dt><dd><?= $dt($sku['created_at']) ?></dd>
      <dt>Last counted</dt><dd><?php if ($sku['counted_at'] === null): ?><span class="muted">never</span><?php else: ?><?= $dt($sku['counted_at']) ?><?php endif; ?></dd>
    </dl>
  </section>

  <section class="card" id="barcodes" aria-labelledby="bc-h">
    <h2 id="bc-h">Barcodes</h2>
<?php if ($barcodes === []): ?>
    <p class="muted">No barcode is known for this item.</p>
<?php else: ?>
    <div class="scroll">
    <table class="stack barcodes">
      <thead><tr><th scope="col">Barcode</th><th scope="col" class="num">Units per scan</th><th scope="col">Source</th><th scope="col">Usable</th><?php if ($canEditBarcodes): ?><th scope="col"><span class="visually-hidden">Remove</span></th><?php endif; ?></tr></thead>
      <tbody>
<?php foreach ($barcodes as $b): ?>
        <tr>
          <td data-label="Barcode"><?= $e($b['barcode']) ?></td>
          <td data-label="Units per scan" class="num">
<?php if ($canEditBarcodes && $b['stored']): ?>
            <form class="inline" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes/units') ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="form_key" value="<?= $e($b['unitsKey']) ?>">
              <input type="hidden" name="barcode" value="<?= $e($b['barcode']) ?>">
              <input type="hidden" name="units_was" value="<?= $e($b['units']) ?>">
              <label class="visually-hidden" for="units-<?= $e($b['barcode']) ?>">Units per scan of <?= $e($b['barcode']) ?></label>
              <input class="units" id="units-<?= $e($b['barcode']) ?>" type="number" name="units" min="1" max="10000" step="1" inputmode="numeric" value="<?= $e($b['units']) ?>">
              <button type="submit">Save</button>
            </form>
<?php else: ?>
            <?= $e($b['units']) ?><?php if ($b['units'] > 1): ?> <span class="tag">outer case</span><?php endif; ?>
<?php endif; ?>
          </td>
          <td data-label="Source"><?= $e($b['source']) ?><?php if ($b['note'] !== null): ?> <span class="muted"><?= $e($b['note']) ?></span><?php endif; ?></td>
          <td data-label="Usable"><?php if ($b['usable']): ?>yes<?php else: ?><span class="tag bad">no</span><?php endif; ?></td>
<?php if ($canEditBarcodes): ?>
          <td>
<?php if ($b['stored']): ?>
            <details class="remove-barcode">
              <summary>Remove…</summary>
              <form method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes/remove') ?>">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="form_key" value="<?= $e($b['removeKey']) ?>">
                <input type="hidden" name="barcode" value="<?= $e($b['barcode']) ?>">
                <p class="muted">Removes <?= $e($b['barcode']) ?> from <?= $e($sku['code']) ?>. The barcode sync will not add it back to this item (adding it by hand stays possible).</p>
                <label for="why-<?= $e($b['barcode']) ?>">Why <span class="muted">(optional)</span></label>
                <input id="why-<?= $e($b['barcode']) ?>" type="text" name="reason" maxlength="500">
                <button type="submit">Remove this barcode</button>
              </form>
            </details>
<?php endif; ?>
          </td>
<?php endif; ?>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
<?php if ($barcodeReviews !== []): ?>
    <p class="note">Barcode review open:
<?php foreach ($barcodeReviews as $br): ?>
      <?php if ($canSeeReviews): ?><a href="<?= $u('/ui/items/barcodes', ['barcode' => $br['barcode']]) ?>"><?= $e($br['barcode']) ?></a><?php else: ?><?= $e($br['barcode']) ?><?php endif; ?> (<?= $e($br['reason']) ?>)
<?php endforeach; ?>
    </p>
<?php endif; ?>
<?php if ($canEditBarcodes): ?>
    <form class="add-barcode" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/barcodes') ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="form_key" value="<?= $e($barcodeAddKey) ?>">
      <label for="new-barcode">Add a barcode</label>
      <input id="new-barcode" type="text" name="barcode" inputmode="numeric" autocomplete="off" maxlength="40" value="<?= $e($typedBarcode) ?>" placeholder="scan or type">
      <label for="new-units">Units per scan</label>
      <input id="new-units" class="units" type="number" name="units" min="1" max="10000" step="1" inputmode="numeric" value="<?= $e($typedUnits) ?>">
      <button type="submit">Add barcode</button>
      <p class="muted">An outer case: its own barcode with the units it holds ("this barcode = 10 units"). A barcode belongs to one item.</p>
    </form>
<?php endif; ?>
  </section>
</div>

<?php if ($suppliers !== null): ?>
<section aria-labelledby="suppliers-h">
  <h2 id="suppliers-h">Suppliers</h2>
<?php if ($suppliers === []): ?>
  <p class="muted">No supplier sells us this item yet.</p>
<?php else: ?>
  <table class="item-suppliers">
    <thead>
      <tr>
        <th scope="col">Supplier</th>
        <th scope="col">Supplier's code</th>
        <th scope="col">Pack</th>
        <th scope="col">Preferred</th>
        <th scope="col" class="num">Last price (per pack)</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($suppliers as $r): ?>
      <tr<?php if (!$r['active']): ?> class="inactive"<?php endif; ?>>
        <td><a href="<?= $u('/ui/purchasing/supplier-items/' . $r['id']) ?>"><?= $e($r['supplier']) ?></a> <?= $e($r['name']) ?><?php if ($r['status'] !== 'active'): ?> <span class="tag"><?= $e(str_replace('_', ' ', $r['status'])) ?></span><?php endif; ?><?php if (!$r['active']): ?> <span class="tag">not used</span><?php endif; ?></td>
        <td><?= $e($r['code']) ?></td>
        <td><?= $e($r['pack']) ?></td>
        <td><?php if ($r['preferred']): ?><span class="tag ok">preferred</span><?php endif; ?></td>
        <td class="num"><?= $e($r['price']) ?><?php if ($r['price_on'] !== null): ?> <span class="muted"><?= $e($r['price_on']) ?></span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<?php endif; ?>

<section aria-labelledby="listings-h">
  <h2 id="listings-h">Listings on the sites</h2>
<?php if ($listings === []): ?>
  <p class="muted">No listing is linked to this item, and none ever was.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th scope="col">Listing</th>
        <th scope="col">Site</th>
        <th scope="col">Status</th>
        <th scope="col" class="num">Per item</th>
        <th scope="col" class="num">30 days</th>
        <th scope="col" class="num">365 days</th>
        <th scope="col">Link history</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($listings as $r): ?>
      <tr>
        <td>
          <a href="/ui/review/listing/<?= $e($r['id']) ?>"><?= $e($r['title'] ?? '(no title)') ?></a>
<?php if ($r['variant_title'] !== null): ?>
          <span class="muted">&middot; <?= $e($r['variant_title']) ?></span>
<?php endif; ?>
        </td>
        <td><?= $e($r['channel']) ?> <span class="muted"><?= $e($r['variant']) ?></span></td>
        <td>
          <span class="status status-<?= $e($r['status']) ?>"><?= $e($r['status']) ?></span>
<?php if ($r['linked']): ?>
          <span class="tag">linked now</span>
<?php elseif ($r['elsewhere']): ?>
          <span class="tag">linked elsewhere now</span>
<?php else: ?>
          <span class="tag">not linked now</span>
<?php endif; ?>
        </td>
        <td class="num"><?= $e($r['units_per_item']) ?></td>
        <td class="num"><?= $n($r['units_30d'] ?? 0) ?></td>
        <td class="num"><?= $n($r['units_365d'] ?? 0) ?></td>
        <td>
<?php if ($r['periods'] === []): ?>
          <span class="muted">none</span>
<?php else: ?>
          <ul class="plain">
<?php foreach ($r['periods'] as $p): ?>
            <li><?= $dt($p['from']) ?> to <?php if ($p['to'] === null): ?>now<?php else: ?><?= $dt($p['to']) ?><?php endif; ?>:
              <?php if ($p['this_item']): ?>this item<?php else: ?><?= $e($p['sku_code']) ?><?php endif; ?>
              x<?= $e($p['units']) ?>, <?= $e($p['action']) ?> by <?= $e($p['decider']) ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section class="selling-mode" id="selling-mode" aria-labelledby="selling-mode-h">
  <h2 id="selling-mode-h">Selling mode on the websites</h2>
<?php $sm = $sellingMode; ?>
<?php if (!$sm['legacy']): ?>
  <p class="muted">This item is counted and protected (policy <?= $e($sm['policy']) ?>): every website sells it by that policy
    (strict: From-Warehouse, sold while there is stock; backorder: From-Warehouse with back-orders; stopped: Out-Of-Stock).</p>
<?php else: ?>
  <p class="muted">Until the item is counted, each website can have its own selling mode. A website writes what CW says only once its site stock
    writer is on (I-Day); until then this is what it will get.</p>
<?php endif; ?>
<?php if ($sm['blocked']): ?>
  <p class="note">Blocked by its item card: every website whose writer is on gets it Out-Of-Stock, whatever the mode below.</p>
<?php endif; ?>
  <table class="stack">
    <thead>
      <tr>
        <th scope="col">Website</th>
        <th scope="col">Site stock writer</th>
        <th scope="col">Listings</th>
        <th scope="col">Selling mode CW writes</th>
        <th scope="col" class="num">Low-stock threshold</th>
        <th scope="col">Set by</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($sm['sites'] as $s): ?>
      <tr>
        <th scope="row" data-label="Website"><?= $e($s['name']) ?> <span class="muted"><?= $e($s['code']) ?></span><?php if ($s['receipts']): ?> <span class="tag">receipts set it</span><?php endif; ?></th>
        <td data-label="Site stock writer"><?php if ($s['writer']): ?><span class="tag ok">on</span><?php else: ?><span class="tag">off</span><?php endif; ?>
          <span class="muted">site <?= $e($s['channel_mode']) ?></span></td>
        <td data-label="Listings"><?php if ($s['listings'] === []): ?><span class="muted">none linked</span><?php else: ?><?php foreach ($s['listings'] as $l): ?><?= $e($l['variant']) ?><?php if ($l['units'] !== 1): ?> <span class="muted">x<?= $e($l['units']) ?></span><?php endif; ?><?php if ($l['quarantined']): ?> <span class="tag warn">quarantined</span><?php endif; ?> <?php endforeach; ?><?php endif; ?></td>
        <td data-label="Selling mode CW writes"><?php if ($s['mode'] === null): ?><span class="muted"><?= $e($s['why'] === 'unlinked' ? 'nothing (no listing linked)' : 'the site keeps its own') ?></span><?php else: ?><strong><?= $e($s['mode']) ?></strong><?php if ($s['backorders'] === 1): ?> + back-orders<?php endif; ?><?php endif; ?>
          <?php if ($s['meaning'] !== null && $s['mode'] !== null): ?><span class="muted">(<?= $e($s['meaning']) ?>)</span><?php endif; ?>
          <?php if ($s['previous'] !== null): ?><span class="muted">before: <?= $e($s['previous']) ?></span><?php endif; ?></td>
        <td class="num" data-label="Low-stock threshold"><?php if ($s['threshold'] === null): ?><span class="muted">the site's own</span><?php else: ?><?= $n($s['threshold']) ?><?php endif; ?></td>
        <td data-label="Set by"><?php if ($s['set_by'] === null): ?><span class="muted">never set in CW</span><?php else: ?><?= $e($s['set_source'] === 'receipt' ? 'a receipt' : 'the switch') ?>,
          <?= $e($s['set_by']) ?>, <?= $dt($s['set_at']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php if ($sm['canSet']): ?>
  <form class="selling-mode-form" method="post" action="<?= $u('/ui/items/' . $sku['id'] . '/selling-mode') ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($sm['formKey']) ?>">
    <input type="hidden" name="stamp" value="<?= $e($sm['stamp']) ?>">
    <fieldset>
      <legend>Change the selling mode</legend>
<?php foreach ($sm['modes'] as $m): ?>
      <label class="choice"><input type="radio" name="mode" value="<?= $e($m['value']) ?>"<?php if ($sm['typed']['mode'] === $m['value']): ?> checked<?php endif; ?> required>
        <?= $e($m['value']) ?> <span class="muted"><?= $e($m['meaning']) ?></span></label>
<?php endforeach; ?>
    </fieldset>
    <fieldset>
      <legend>On these websites</legend>
      <label class="choice"><input type="checkbox" name="all_sites" value="1"<?php if ($sm['typed']['all']): ?> checked<?php endif; ?>> All websites</label>
<?php foreach ($sm['sites'] as $s): ?>
      <label class="choice"><input type="checkbox" name="site_<?= $e($s['code']) ?>" value="1"<?php if ($s['checked']): ?> checked<?php endif; ?>> <?= $e($s['name']) ?></label>
<?php endforeach; ?>
    </fieldset>
    <p><label for="sm-threshold">Low-stock threshold <span class="muted">(optional: empty keeps it)</span></label>
      <input id="sm-threshold" name="threshold" inputmode="numeric" pattern="[0-9]*" maxlength="6" value="<?= $e($sm['typed']['threshold']) ?>"></p>
    <p><label for="sm-reason">Why</label>
      <input id="sm-reason" name="reason" required minlength="3" maxlength="500" value="<?= $e($sm['typed']['reason']) ?>"></p>
    <p class="actions"><button type="submit">Save the selling mode</button></p>
  </form>
<?php endif; ?>
</section>

<section aria-labelledby="stock-h">
  <h2 id="stock-h">Stock</h2>
<?php if ($stock === []): ?>
  <p class="muted">No stock has ever been recorded for this item.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th scope="col">Warehouse</th>
        <th scope="col" class="num">On hand</th>
        <th scope="col" class="num">Allocated</th>
        <th scope="col" class="num">Held</th>
        <th scope="col" class="num">Available</th>
        <th scope="col">Last counted</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($stock as $b): ?>
      <tr>
        <th scope="row"><?= $e($b['code']) ?> <span class="muted"><?= $e($b['name']) ?></span><?php if (!$b['sellable']): ?> <span class="tag">not sellable</span><?php endif; ?></th>
        <td class="num"><?= $n($b['on_hand']) ?></td>
        <td class="num"><?= $n($b['allocated']) ?></td>
        <td class="num"><?= $n($b['held']) ?></td>
        <td class="num"><?= $n($b['available']) ?></td>
        <td><?php if ($b['counted_at'] === null): ?><span class="muted">never</span><?php else: ?><?= $dt($b['counted_at']) ?><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
      <tr class="sub">
        <th scope="row">Sellable warehouses</th>
        <td class="num"><?= $n($totals['on_hand']) ?></td>
        <td class="num"><?= $n($totals['allocated']) ?></td>
        <td class="num"><?= $n($totals['held']) ?></td>
        <td class="num"><?= $n($totals['available']) ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>
<?php endif; ?>
</section>

<section aria-labelledby="ledger-h">
  <h2 id="ledger-h">Recent stock movements</h2>
<?php if ($ledger === []): ?>
  <p class="muted">No movements yet.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th scope="col">When</th>
        <th scope="col">Warehouse</th>
        <th scope="col">Bucket</th>
        <th scope="col" class="num">Change</th>
        <th scope="col" class="num">Balance</th>
        <th scope="col">Type</th>
        <th scope="col">Reference</th>
        <th scope="col">Who</th>
        <th scope="col">Note</th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($ledger as $r): ?>
      <tr>
        <td><?= $dt($r['at']) ?></td>
        <td><?= $e($r['warehouse']) ?></td>
        <td><?= $e($r['bucket']) ?></td>
        <td class="num"><?= $e($r['delta']) ?></td>
        <td class="num"><?= $e($r['after']) ?></td>
        <td><?= $e($r['type']) ?></td>
        <td><?= $e($r['ref']) ?></td>
        <td><?= $e($r['actor']) ?></td>
        <td><?= $e($r['note']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
