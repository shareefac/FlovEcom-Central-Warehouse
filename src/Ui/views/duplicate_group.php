<p class="crumbs">
  <a href="/ui/review/duplicates">&larr; Duplicates</a>
<?php if ($next_link !== null): ?>
  <a class="skip" href="<?= $e($next_link) ?>">Skip to the next group &rarr;</a>
<?php endif; ?>
</p>

<h1>Same product on <?= $n(count($rows)) ?> pages?</h1>
<p class="muted">Group <?= $e($g['group'] ?? $g['id']) ?> of <?= $e($g['run']) ?><?php if ($g['kind'] !== null): ?>, suggested because <?= $e(match ($g['kind']) {
    'shared_gtin' => 'the pages share a barcode',
    'sweep' => 'the duplicate sweep found no difference (same brand, form, size, strength and flavour; the titles may be worded differently)',
    default => 'the names match',
}) ?><?php endif; ?>. Check the differences below before you decide.
<?php if ($g['open'] === 0): ?> Decided.<?php elseif ($g['decided'] > 0): ?> Partly decided.<?php endif; ?></p>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($note): ?>
<p class="note">Both pages now share one warehouse item. On the website they stay separate pages with their own price and reviews until Vape and Go switches to the warehouse system.</p>
<?php endif; ?>

<?php if ($keeper !== null && $open_rows !== []): ?>
<section class="dup-rules<?php if ($against !== []): ?> doubt<?php endif; ?>" aria-labelledby="dup-rules-h">
  <h2 id="dup-rules-h">What the rules say</h2>
<?php if ($against === []): ?>
  <p>The rules found nothing that speaks against merging <?= $e(count($open_rows) === 1 ? 'this page' : 'these pages') ?> into
    #<?= $e($keeper['id']) ?> (same brand, form, strength, size, flavour and words; no different barcodes). They cannot see the
    packaging: open the live pages before you merge.</p>
<?php else: ?>
  <p><strong>The rules found reasons these may be different products.</strong> Keep them separate unless the live pages show the same
    product.</p>
  <ul class="plain dup-reasons">
<?php foreach ($against as $r): ?>
    <li><a href="#dup-<?= $e($r['id']) ?>-h">#<?= $e($r['id']) ?> <?= $e($r['short']) ?></a> against the kept #<?= $e($keeper['id']) ?>:
<?php if (!$r['verdict']['checked']): ?>
      <span class="muted">not checked (a page has no listing profile)</span>
<?php else: ?>
      <ul>
<?php foreach ($r['verdict']['reasons'] as $why): ?>
        <li<?php if ($why['strong']): ?> class="strong"<?php endif; ?>><?= $e($why['text']) ?><?php if ($why['detail'] !== ''): ?>: <span class="detail"><?= $e($why['detail']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<div class="dup-cards">
<?php foreach ($rows as $r): ?>
  <section class="card dup-card<?php if ($r['state'] === 'keeper'): ?> keeper<?php endif; ?>" aria-labelledby="dup-<?= $e($r['id']) ?>-h">
    <p class="dup-state">
<?php if ($r['state'] === 'keeper'): ?>
      <span class="tag ok">Kept: the others merge into this one's item</span>
<?php elseif ($r['state'] === 'same'): ?>
      <span class="tag ok">Same warehouse item as the kept one</span>
<?php elseif ($r['state'] === 'separate'): ?>
      <span class="tag">Kept separate</span>
<?php elseif ($r['state'] === 'waiting'): ?>
      <span class="tag warn">Waiting for a second person</span> <a href="/ui/review?queue=pending">Second approval</a>
<?php elseif ($r['state'] === 'elsewhere'): ?>
      <span class="tag warn">Has an open suggestion in another group or queue: decide it there first</span> <a href="/ui/review/listing/<?= $e($r['id']) ?>">Listing</a>
<?php elseif ($r['state'] === 'not_linked'): ?>
      <span class="tag warn">Not linked to an item</span>
<?php elseif ($r['verdict'] !== null && $r['verdict']['checked'] && !$r['verdict']['ok']): ?>
      <span class="tag bad">To decide: the rules found differences</span>
<?php else: ?>
      <span class="tag warn">To decide</span>
<?php endif; ?>
<?php if ($r['is_suggested'] && $r['state'] !== 'keeper'): ?> <span class="muted">(suggested keeper)</span><?php endif; ?>
    </p>
    <h2 id="dup-<?= $e($r['id']) ?>-h"><?= $e($r['title'] ?? '(no title)') ?></h2>
<?php if ($r['variant_title'] !== null): ?>
    <p class="title"><?= $e($r['variant_title']) ?></p>
<?php endif; ?>
    <dl>
      <dt>Listing</dt><dd><a href="/ui/review/listing/<?= $e($r['id']) ?>">#<?= $e($r['id']) ?></a> <?= $e($r['channel']) ?> <span class="muted">variant <?= $e($r['variant']) ?>, <?= $e($r['status']) ?></span></dd>
<?php if ($r['brand'] !== null): ?>
      <dt>Brand</dt><dd><?= $e($r['brand']) ?></dd>
<?php endif; ?>
      <dt>Price</dt><dd><?php if ($r['price'] === null): ?><span class="muted">not known</span><?php else: ?><?= $e($r['price']) ?><?php endif; ?></dd>
      <dt>Sold</dt><dd><?= $n($r['units_30d']) ?> in 30 days, <?= $n($r['units_365d']) ?> in 365 days <span class="muted">(<?= $e($r['units_from']) ?>)</span></dd>
      <dt>Site stock</dt><dd><?php if ($r['site_stock'] === null): ?><span class="muted">no snapshot</span><?php else: ?><?= $n($r['site_stock']) ?><?php if ($r['site_mode'] !== null): ?>, <?= $e($r['site_mode']) ?><?php endif; ?><?php if ($r['site_sellable'] === false): ?>, not for sale<?php endif; ?> <span class="muted">on <?= $e($r['site_date']) ?></span><?php endif; ?></dd>
      <dt>Page</dt><dd><?php if ($r['page'] === null): ?><span class="muted">no link</span><?php else: ?><a href="<?= $e($r['page']) ?>" rel="noopener noreferrer nofollow" target="_blank">open the live page</a><?php endif; ?></dd>
      <dt>Barcodes</dt><dd><?php if ($r['barcodes'] === []): ?><span class="muted">none</span><?php else: ?><?= $e(implode(', ', $r['barcodes'])) ?><?php endif; ?></dd>
      <dt>CW item</dt><dd>
<?php if ($r['sku'] === null): ?>
        <span class="muted">none</span>
<?php else: ?>
        <a href="/ui/items/<?= $e($r['sku']['id']) ?>"><?= $e($r['sku']['code']) ?></a> <?= $e($r['sku']['name']) ?><?php if ($r['sku']['cwp'] !== null): ?> <span class="muted"><?= $e($r['sku']['cwp']) ?></span><?php endif; ?>
        <br><span class="muted"><?= $e($r['sku']['counted'] ? 'counted' : 'not counted yet') ?>, policy <?= $e($r['sku']['policy']) ?>, CW holds <?= $n($r['sku']['available']) ?> available</span>
<?php if ($r['sku']['counted'] || $r['sku']['policy'] !== 'legacy' || $r['units_per_item'] !== 1): ?> <span class="tag warn"><?= $e($r['sku']['policy'] !== 'legacy' ? 'protected: cannot be merged' : 'a merge needs two mapping leads') ?></span><?php endif; ?>
<?php if ($r['sku']['others'] !== []): ?>
        <br><span class="muted">Also on this item (moves with it):
<?php foreach ($r['sku']['others'] as $o): ?>
          <a href="/ui/review/listing/<?= $e($o['id']) ?>"><?= $e($o['channel']) ?> <?= $e($o['variant']) ?></a>
<?php endforeach; ?>
        </span>
<?php endif; ?>
<?php endif; ?>
      </dd>
<?php if (count($r['attributes']) <= 6): ?>
<?php foreach ($r['attributes'] as $a): ?>
      <dt><?= $e($a['name']) ?></dt><dd><?= $e($a['value']) ?></dd>
<?php endforeach; ?>
<?php endif; ?>
    </dl>
<?php if (count($r['attributes']) > 6): ?>
    <details class="dup-attrs">
      <summary>All <?= $n(count($r['attributes'])) ?> options and attributes</summary>
      <dl>
<?php foreach ($r['attributes'] as $a): ?>
        <dt><?= $e($a['name']) ?></dt><dd><?= $e($a['value']) ?></dd>
<?php endforeach; ?>
      </dl>
    </details>
<?php endif; ?>
<?php if ($r['keeper_link'] !== null && $g['open'] > 0): ?>
    <p><a href="<?= $e($r['keeper_link']) ?>">Keep this one instead</a></p>
<?php endif; ?>
  </section>
<?php endforeach; ?>
</div>

<section aria-labelledby="dup-compare-h">
  <h2 id="dup-compare-h">What the titles and options say</h2>
  <p class="muted">Rows in colour differ between the pages; words in bold are not on every page. Check them on the live pages before you merge.</p>
  <div class="scroll">
  <table class="compare dup-compare">
    <thead>
      <tr>
        <th scope="col">Field</th>
<?php foreach ($rows as $r): ?>
        <th scope="col">#<?= $e($r['id']) ?><?php if ($r['state'] === 'keeper'): ?> (kept)<?php endif; ?> <span class="muted"><?= $e($r['short']) ?></span></th>
<?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($compare as $f): ?>
      <tr<?php if ($f['differs']): ?> class="differs"<?php elseif ($f['partial']): ?> class="partial"<?php endif; ?>>
        <th scope="row"><?= $e($f['label']) ?><?php if ($f['differs']): ?> <span class="state">differs</span><?php elseif ($f['partial']): ?> <span class="state">not on every page</span><?php endif; ?></th>
<?php foreach ($rows as $r): ?>
<?php $v = $f['values'][$r['id']] ?? null; ?>
        <td><?php if ($v === null || $v === []): ?><span class="muted">-</span><?php elseif ($f['set']): ?><?php foreach ($v as $w): ?><span class="word<?php if ($w['odd']): ?> odd<?php endif; ?>"><?= $e($w['word']) ?></span> <?php endforeach; ?><?php else: ?><?= $e($v) ?><?php endif; ?></td>
<?php endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if (($g['sweep'] ?? null) !== null && $g['sweep']['pairs'] !== []): ?>
<?php $byVariant = []; foreach ($rows as $r) { $byVariant[$r['variant']] = $r['id']; } ?>
<section aria-labelledby="dup-sweep-h">
  <h2 id="dup-sweep-h">Why the sweep suggested this</h2>
  <p class="muted">Rules only: every hard check passed (strength, nicotine type, ml, puffs, pack, ohm, model numbers, form, colour, flavour,
    VG/PG), the brand is the same, the prices are close and every word of each title is on the other page. It cannot see the
    packaging: check the live pages.<?php if ($g['sweep']['score'] !== null): ?> Score <?= $n($g['sweep']['score']) ?> of 100.<?php endif; ?></p>
  <ul class="plain dup-sweep">
<?php foreach ($g['sweep']['pairs'] as $p): ?>
    <li><?php foreach ([$p['a'], $p['b']] as $i => $v): ?><?php if ($i === 1): ?> and <?php endif; ?><?php if (isset($byVariant[$v])): ?><a href="/ui/review/listing/<?= $e($byVariant[$v]) ?>">#<?= $e($byVariant[$v]) ?></a><?php else: ?>variant <?= $e($v) ?><?php endif; ?><?php endforeach; ?>:
      <?php if ($p['score'] !== null): ?>score <?= $n($p['score']) ?>; <?php endif; ?>the same <?= $e($p['agree'] === [] ? '-' : implode(', ', $p['agree'])) ?><?php if ($p['unknown'] !== []): ?>; neither page states <?= $e(implode(', ', $p['unknown'])) ?><?php endif; ?><?php if ($p['barcode'] !== null): ?>; <?= $e($p['barcode']) ?><?php endif; ?><?php if ($p['price_ratio'] !== null): ?>; price ratio <?= $e(number_format($p['price_ratio'], 2)) ?><?php endif; ?><?php if ($p['same_product_page']): ?>; two options of one product page<?php endif; ?>.</li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($no_form !== null): ?>
<p class="muted"><?= $e($no_form) ?></p>
<?php elseif ($can_decide): ?>
<section aria-labelledby="dup-decide-h">
  <h2 id="dup-decide-h">Decide</h2>
  <form class="decide dup-decide" method="post" action="/ui/review/duplicates/<?= $e($g['id']) ?>/decide">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($form_key) ?>">
    <input type="hidden" name="keeper" value="<?= $e($keeper['id']) ?>">
    <input type="hidden" name="keep_sku" value="<?= $e($keeper['sku']['id']) ?>">
    <input type="hidden" name="v_<?= $e($keeper['id']) ?>" value="<?= $e($keeper['map_version']) ?>">
<?php if ($keeper['proposal'] !== null && in_array($keeper['proposal']['id'], array_column($g['proposals'], 'id'), true)): ?>
    <input type="hidden" name="p_<?= $e($keeper['id']) ?>" value="<?= $e($keeper['proposal']['id']) ?>">
<?php endif; ?>
<?php foreach ($open_rows as $r): ?>
    <input type="hidden" name="v_<?= $e($r['id']) ?>" value="<?= $e($r['map_version']) ?>">
<?php if ($r['proposal'] !== null): ?>
    <input type="hidden" name="p_<?= $e($r['id']) ?>" value="<?= $e($r['proposal']['id']) ?>">
<?php endif; ?>
<?php endforeach; ?>
    <p>Kept: <strong><?= $e($keeper['sku']['code']) ?></strong>, the item of listing #<?= $e($keeper['id']) ?> <?= $e($keeper['title'] ?? '') ?>.
<?php if ($suggested !== null && $suggested['id'] !== $keeper['id']): ?> <span class="muted">(not the suggestion, #<?= $e($suggested['id']) ?>: you picked it, or nothing was left to decide against it)</span><?php endif; ?></p>
<?php if (count($open_rows) > 1): ?>
    <fieldset>
      <legend>Each page against the kept one</legend>
<?php foreach ($open_rows as $r): ?>
      <fieldset class="dup-choice">
        <legend>#<?= $e($r['id']) ?> <?= $e($r['title'] ?? '') ?> <?= $e($r['variant_title'] ?? '') ?><?php if ($r['verdict'] !== null && $r['verdict']['checked'] && !$r['verdict']['ok']): ?> <span class="tag bad">the rules found differences</span><?php endif; ?></legend>
        <label class="choice"><input type="radio" name="c_<?= $e($r['id']) ?>" value="merge"<?php if ($r['choice'] === 'merge'): ?> checked<?php endif; ?>> Same product</label>
        <label class="choice"><input type="radio" name="c_<?= $e($r['id']) ?>" value="separate"<?php if ($r['choice'] === 'separate'): ?> checked<?php endif; ?>> Different product</label>
        <label class="choice"><input type="radio" name="c_<?= $e($r['id']) ?>" value="later"<?php if ($r['choice'] === '' || $r['choice'] === 'later'): ?> checked<?php endif; ?>> Not sure yet</label>
      </fieldset>
<?php endforeach; ?>
    </fieldset>
<?php endif; ?>
<?php if ($against !== []): ?>
    <label class="choice dup-confirm"><input type="checkbox" name="confirm" value="1"<?php if ($confirmed): ?> checked<?php endif; ?>> I checked the live pages:
      the pages I mark as the same product are the same product, whatever the rules say.</label>
<?php endif; ?>
    <p class="muted">A merge moves the stock CW holds for the other pages' items onto <?= $e($keeper['sku']['code']) ?> (<?= $n($merge_units) ?> units
      available on <?= $n($merge_items) ?> item<?php if ($merge_items !== 1): ?>s<?php endif; ?> if all are merged) and counts their sales there. A page
      that is a different product is never suggested as a duplicate of this item again.</p>
    <div class="actions">
<?php if ($against !== []): ?>
      <button type="submit" name="do" value="separate_all" class="primary">Different products - keep separate</button>
<?php if (count($open_rows) > 1): ?>
      <button type="submit" name="do" value="save">Save these choices</button>
<?php endif; ?>
      <button type="submit" name="do" value="merge_all">Same product - merge into <?= $e($keeper['sku']['code']) ?></button>
<?php else: ?>
<?php if (count($open_rows) > 1): ?>
      <button type="submit" name="do" value="save" class="primary">Save these choices</button>
<?php endif; ?>
      <button type="submit" name="do" value="merge_all"<?php if (count($open_rows) === 1): ?> class="primary"<?php endif; ?>>Same product - merge into <?= $e($keeper['sku']['code']) ?></button>
      <button type="submit" name="do" value="separate_all">Different products - keep separate</button>
<?php endif; ?>
    </div>
<?php if (count($open_rows) > 1): ?>
    <p class="muted">"Save these choices" takes the choice of each page; the other two buttons give all of them the same answer.</p>
<?php endif; ?>
  </form>
</section>
<?php endif; ?>

<?php if ($merged_rows !== [] && $can_split): ?>
<section aria-labelledby="dup-undo-h">
  <h2 id="dup-undo-h">Undo a wrong merge</h2>
  <p class="muted">If a page turns out to be a different product, split it off. Back to the item it had before undoes that merge: the
    item comes back with every page the merge moved and the stock that came with it, less what those pages sold since. To a new item
    takes this page alone. It is then never suggested as a duplicate of this item again. Once an item has been counted, a split needs a
    second mapping lead.</p>
<?php foreach ($merged_rows as $r): ?>
<?php if ($r['undo'] !== null): ?>
  <form class="inline dup-undo" method="post" action="/ui/review/duplicates/<?= $e($g['id']) ?>/split">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($r['undo_key']) ?>">
    <input type="hidden" name="listing" value="<?= $e($r['id']) ?>">
    <input type="hidden" name="v" value="<?= $e($r['map_version']) ?>">
<?php if ($r['proposal'] !== null || $r['other_proposal'] !== null): ?>
    <input type="hidden" name="proposal" value="<?= $e($r['proposal']['id'] ?? $r['other_proposal']) ?>">
<?php endif; ?>
    <span>#<?= $e($r['id']) ?> <?= $e($r['title'] ?? '') ?></span>
<?php $u = $r['undo']; ?>
<?php if ($u['former_ok']): ?>
    <label class="choice"><input type="radio" name="to" value="former" checked> back to <?= $e($u['from_code']) ?>, with <?= $n($u['former_units']) ?> unit<?php if ($u['former_units'] !== 1): ?>s<?php endif; ?> of stock<?php if ($u['by_warehouse'] !== []): ?> (<?php foreach ($u['by_warehouse'] as $wh => $q): ?><?= $e($wh) ?> <?= $n($q) ?><?php endforeach; ?>)<?php endif; ?><?php if ($u['with'] !== []): ?> and the other page<?php if (count($u['with']) !== 1): ?>s<?php endif; ?> the merge moved: <?php foreach ($u['with'] as $w): ?><a href="/ui/review/listing/<?= $e($w) ?>">#<?= $e($w) ?></a> <?php endforeach; ?><?php endif; ?></label>
    <label class="choice"><input type="radio" name="to" value="new"> to a new item<?php if ($u['new_exact']): ?>, with <?= $n($u['new_units']) ?> unit<?php if ($u['new_units'] !== 1): ?>s<?php endif; ?><?php else: ?>, no stock moves (it came with other pages: its own share cannot be told apart)<?php endif; ?></label>
<?php else: ?>
    <input type="hidden" name="to" value="new">
    <span class="muted">to a new item<?php if ($u['new_exact']): ?>, with <?= $n($u['new_units']) ?> unit<?php if ($u['new_units'] !== 1): ?>s<?php endif; ?> (<?= $e($u['from_code']) ?> was merged into another item since)<?php else: ?>, no stock moves: it came onto <?= $e($r['sku']['code'] ?? 'its item') ?><?php if ($u['chain']): ?> through two merges<?php else: ?> with other pages<?php endif; ?>, so its own stock cannot be told apart (the next count settles it)<?php endif; ?></span>
<?php endif; ?>
    <button type="submit">Split it off</button>
  </form>
<?php endif; ?>
<?php endforeach; ?>
</section>
<?php endif; ?>
