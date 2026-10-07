<?php
$title = static fn (array $r): string => trim(($r['title'] ?? '') . ' ' . ($r['variant_title'] ?? ''));
$byVariant = [];
foreach ($rows as $r) {
    $byVariant[$r['variant']] = $r;
}
$several = count($open_rows) > 1;
// The comparison shows only the rows some page states (F136).
$shownRows = array_filter($compare, static fn (array $f): bool => array_filter($f['values'], static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== '') !== []);
?>
<p class="crumbs">
  <a href="/ui/review/duplicates"><?= $word('MENU', 'duplicates') ?></a>
<?php if ($next_link !== null): ?>
  <a class="next-link" href="<?= $e($next_link) ?>"><?= $word('DUPS', 'skip') ?> &rarr;</a>
<?php endif; ?>
</p>

<div class="head-help">
  <h1><?= $e($heading) ?></h1>
  <?= $explain('join_undo', \CW\Ui\Words::DUPS['undo_button']) ?>
</div>
<p class="eyebrow"><?= $say('DUPS', 'group', (string) ($g['group'] ?? $g['id'])) ?><?php if ($kind !== null): ?> · <?= $e($kind) ?><?php endif; ?><?php if ($g['open'] > 0 && $g['decided'] > 0): ?> · <?= $word('DUPS', 'partly_decided') ?><?php endif; ?></p>
<?= $intro('duplicate_group', $lookOnly) ?>

<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($note): ?>
<p class="note"><?= $word('DUPS', 'joined_note') ?></p>
<?php endif; ?>

<?php if ($keeper !== null && $open_rows !== []): ?>
<section class="dup-rules<?php if ($against !== []): ?> doubt<?php endif; ?>" aria-labelledby="dup-rules-h">
  <h2 id="dup-rules-h"><?= $word('DUPS', 'rules') ?></h2>
<?php if ($against === []): ?>
  <p><?= $word('DUPS', 'rules_ok') ?></p>
<?php else: ?>
  <p><strong><?= $word('DUPS', 'rules_doubt') ?></strong> <?= $word('DUPS', 'rules_doubt_text') ?></p>
  <ul class="plain dup-reasons">
<?php foreach ($against as $r): ?>
    <li><a href="#dup-<?= $e($r['id']) ?>-h"><?= $say('DUPS', 'compared', $title($r) !== '' ? $title($r) : '#' . $r['id'], $title($keeper) !== '' ? $title($keeper) : '#' . $keeper['id']) ?></a>
<?php if (!$r['verdict']['checked']): ?>
      <span class="muted"><?= $word('DUPS', 'not_checked_page') ?></span>
<?php else: ?>
      <ul>
<?php foreach ($r['verdict']['reasons'] as $why): ?>
        <li<?php if ($why['strong']): ?> class="strong"<?php endif; ?>><?= $e($why['text']) ?><?php if ($why['shown'] !== ''): ?>: <span class="detail"><?= $e($why['shown']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if ($no_form !== null): ?>
<p class="note read-only"><?= $e($no_form) ?></p>
<?php elseif ($can_decide): ?>
<section class="card decide-box" aria-labelledby="dup-decide-h">
  <h2 id="dup-decide-h"><?= $word('DUPS', 'decide') ?></h2>
  <form class="decide dup-decide" id="dup-decide" method="post" action="/ui/review/duplicates/<?= $e($g['id']) ?>/decide">
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
    <p><?= $say('DUPS', 'keep_line', $keeper['sku']['code'], $title($keeper)) ?>
<?php if ($suggested !== null && $suggested['id'] !== $keeper['id']): ?> <span class="muted"><?= $word('DUPS', 'keep_not_suggested') ?></span><?php endif; ?></p>
<?php if ($several): ?>
    <fieldset>
      <legend><?= $word('DUPS', 'each_page') ?></legend>
<?php foreach ($open_rows as $r): ?>
      <fieldset class="dup-choice">
        <legend><?= $e($title($r)) ?><?php if ($r['verdict'] !== null && $r['verdict']['checked'] && !$r['verdict']['ok']): ?> <span class="tag bad"><?= $word('DUPS', 'state_doubt') ?></span><?php endif; ?></legend>
        <label class="choice"><input type="radio" name="c_<?= $e($r['id']) ?>" value="merge"<?php if ($r['choice'] === 'merge'): ?> checked<?php endif; ?>> <?= $word('DUPS', 'c_same') ?></label>
        <label class="choice"><input type="radio" name="c_<?= $e($r['id']) ?>" value="separate"<?php if ($r['choice'] === 'separate'): ?> checked<?php endif; ?>> <?= $word('DUPS', 'c_apart') ?></label>
        <label class="choice"><input type="radio" name="c_<?= $e($r['id']) ?>" value="later"<?php if ($r['choice'] === '' || $r['choice'] === 'later'): ?> checked<?php endif; ?>> <?= $word('DUPS', 'c_later') ?></label>
      </fieldset>
<?php endforeach; ?>
    </fieldset>
<?php endif; ?>
    <div class="answers">
      <h3 class="section-title"><?= $word('UI', 'what_each_answer_does') ?></h3>
      <dl class="answer-list">
<?php if ($several): ?>
        <div class="answer info"><dt><?= $word('DUPS', 'save') ?></dt><dd><?= $word('DUPS', 'does_save') ?></dd></div>
<?php endif; ?>
        <div class="answer done"><dt><?php if ($several): ?><?= $say('DUPS', 'join_all', $keeper['sku']['code']) ?><?php else: ?><?= $say('DUPS', 'join', $keeper['sku']['code']) ?><?php endif; ?></dt>
          <dd><?= $say('DUPS', 'does_join', $keeper['sku']['code'], \CW\Ui\Words::say('DUPS', 'free_short', $merge_units)) ?><?php if ($against !== []): ?> <strong><?= $word('DUPS', 'does_join_tick') ?></strong><?php endif; ?></dd></div>
        <div class="answer blocked safe"><dt><?php if ($several): ?><?= $word('DUPS', 'apart_all') ?><?php else: ?><?= $word('DUPS', 'apart') ?><?php endif; ?> <span class="safe-tag"><?= $word('UI', 'safer') ?></span></dt>
          <dd><?= $word('DUPS', 'does_apart') ?></dd></div>
        <div class="answer waiting"><dt><?= $word('DUPS', 'later') ?></dt><dd><?= $word('DUPS', 'does_later') ?> <?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>"><?= $word('DUPS', 'skip') ?></a><?php else: ?><a href="/ui/review/duplicates"><?= $say('LISTING', 'skip_back', \CW\Ui\Words::MENU['duplicates']) ?></a><?php endif; ?></dd></div>
      </dl>
    </div>
<?php if ($against !== []): ?>
    <label class="choice dup-confirm"><input type="checkbox" name="confirm" value="1" required<?php if ($confirmed): ?> checked<?php endif; ?>> <?= $word('DUPS', 'confirm') ?></label>
<?php endif; ?>
    <div class="actions">
<?php if ($several): ?>
      <button type="submit" name="do" value="save" class="primary" formnovalidate><?= $word('DUPS', 'save') ?></button>
      <button type="submit" name="do" value="separate_all" class="btn secondary" formnovalidate><?= $word('DUPS', 'apart_all') ?></button>
      <button type="submit" name="do" value="merge_all" class="btn secondary"><?= $say('DUPS', 'join_all', $keeper['sku']['code']) ?></button>
<?php elseif ($against !== []): ?>
      <button type="submit" name="do" value="separate_all" class="primary" formnovalidate><?= $word('DUPS', 'apart') ?></button>
      <button type="submit" name="do" value="merge_all"><?= $say('DUPS', 'join', $keeper['sku']['code']) ?></button>
<?php else: ?>
      <button type="submit" name="do" value="merge_all" class="primary"><?= $say('DUPS', 'join', $keeper['sku']['code']) ?></button>
      <button type="submit" name="do" value="separate_all" formnovalidate><?= $word('DUPS', 'apart') ?></button>
<?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<div class="dup-cards">
<?php foreach ($rows as $r): ?>
  <section class="card dup-card<?php if ($r['state'] === 'keeper'): ?> keeper<?php endif; ?>" aria-labelledby="dup-<?= $e($r['id']) ?>-h">
    <p class="dup-state">
<?php if ($r['state'] === 'keeper'): ?>
      <?= $chip('done', \CW\Ui\Words::DUPS['state_keeper']) ?>
<?php elseif ($r['state'] === 'same'): ?>
      <?= $chip('done', \CW\Ui\Words::DUPS['state_same']) ?>
<?php elseif ($r['state'] === 'separate'): ?>
      <?= $chip('off', \CW\Ui\Words::DUPS['state_separate']) ?>
<?php elseif ($r['state'] === 'waiting'): ?>
      <?= $chip('waiting', \CW\Ui\Words::DUPS['state_waiting']) ?> <a href="/ui/review?queue=pending"><?= $word('MENU', 'pending') ?></a>
<?php elseif ($r['state'] === 'elsewhere'): ?>
      <?= $chip('needs', \CW\Ui\Words::DUPS['state_elsewhere']) ?> <a href="/ui/review/listing/<?= $e($r['id']) ?>"><?= $word('DUPS', 'open_page') ?></a>
<?php elseif ($r['state'] === 'not_linked'): ?>
      <?= $chip('off', \CW\Ui\Words::DUPS['state_not_linked']) ?>
<?php elseif ($r['verdict'] !== null && $r['verdict']['checked'] && !$r['verdict']['ok']): ?>
      <?= $chip('blocked', \CW\Ui\Words::DUPS['state_doubt']) ?>
<?php else: ?>
      <?= $chip('needs', \CW\Ui\Words::DUPS['state_open']) ?>
<?php endif; ?>
<?php if ($r['is_suggested'] && $r['state'] !== 'keeper'): ?> <span class="muted"><?= $word('DUPS', 'suggested_keeper') ?></span><?php endif; ?>
    </p>
    <h2 id="dup-<?= $e($r['id']) ?>-h"><?= $e($r['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></h2>
<?php if ($r['variant_title'] !== null): ?>
    <p class="title"><?= $e($r['variant_title']) ?></p>
<?php endif; ?>
    <dl>
      <dt><?= $word('LISTING', 'website') ?></dt><dd><a href="/ui/review/listing/<?= $e($r['id']) ?>"><?= $say('DUPS', 'page_on', $names[$r['channel']] ?? $r['channel'], $r['variant']) ?></a> <?= $stateChip('LISTING_STATUS', $r['status']) ?></dd>
<?php if ($r['brand'] !== null): ?>
      <dt><?= $word('DUPS', 'brand') ?></dt><dd><?= $e($r['brand']) ?></dd>
<?php endif; ?>
      <dt><?= $word('DUPS', 'price') ?></dt><dd><?php if ($r['price'] === null): ?><span class="muted"><?= $word('DUPS', 'not_known') ?></span><?php elseif (is_numeric($r['price'])): ?><?= $money($r['price']) ?><?php else: ?><?= $e($r['price']) ?><?php endif; ?></dd>
      <dt><?= $word('DUPS', 'sold') ?></dt><dd><?= $say('DUPS', 'sold_line', $r['units_30d'], $r['units_365d']) ?> <span class="muted"><?php if ($r['units_to'] !== null): ?><?= $say('DUPS', 'from_history', \CW\Ui\Html::day($r['units_to'])) ?><?php else: ?><?= $word('DUPS', 'from_profile') ?><?php endif; ?></span></dd>
      <dt><?= $word('DUPS', 'site_stock') ?></dt><dd><?php if ($r['site_stock'] === null): ?><span class="muted"><?= $word('DUPS', 'not_known') ?></span><?php else: ?><?= $n($r['site_stock']) ?><?php if ($r['site_mode'] !== null): ?> (<?= $e($r['site_mode']) ?>)<?php endif; ?><?php if ($r['site_sellable'] === false): ?>, <?= $word('DUPS', 'not_for_sale') ?><?php endif; ?> <span class="muted"><?= $say('DUPS', 'on', \CW\Ui\Html::day($r['site_date'])) ?></span><?php endif; ?></dd>
      <dt><?= $word('DUPS', 'live_page') ?></dt><dd><?php if ($r['page'] === null): ?><span class="muted"><?= $word('DUPS', 'no_link') ?></span><?php else: ?><a href="<?= $e($r['page']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= $word('DUPS', 'live') ?></a><?php endif; ?></dd>
      <dt><?= $word('DUPS', 'barcodes') ?></dt><dd><?php if ($r['barcodes'] === []): ?><span class="muted"><?= $word('DUPS', 'none_value') ?></span><?php else: ?><?= $e(implode(', ', $r['barcodes'])) ?><?php endif; ?></dd>
      <dt><?= $word('DUPS', 'product') ?></dt><dd>
<?php if ($r['sku'] === null): ?>
        <span class="muted"><?= $word('DUPS', 'none_value') ?></span>
<?php else: ?>
        <a href="/ui/items/<?= $e($r['sku']['id']) ?>"><?= $e($r['sku']['code']) ?></a> <?= $e($r['sku']['name']) ?>
        <br><span class="muted"><?= $e($r['sku']['counted'] ? \CW\Ui\Words::DUPS['counted'] : \CW\Ui\Words::DUPS['not_counted']) ?> · <?= $word('POLICY', $r['sku']['policy']) ?> · <?= $say('DUPS', 'free', $r['sku']['available']) ?></span>
<?php if ($r['sku']['policy'] !== 'legacy'): ?> <span class="tag warn"><?= $word('DUPS', 'protected') ?></span><?php elseif ($r['sku']['counted'] || $r['units_per_item'] !== 1): ?> <span class="tag warn"><?= $word('DUPS', 'needs_second') ?></span><?php endif; ?>
<?php if ($r['sku']['others'] !== []): ?>
        <br><span class="muted"><?= $word('DUPS', 'also_on') ?>
<?php foreach ($r['sku']['others'] as $o): ?>
          <a href="/ui/review/listing/<?= $e($o['id']) ?>"><?= $say('DUPS', 'page_on', $names[$o['channel']] ?? $o['channel'], $o['variant']) ?></a>
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
      <summary><?= $say('DUPS', 'all_attrs', count($r['attributes'])) ?></summary>
      <dl>
<?php foreach ($r['attributes'] as $a): ?>
        <dt><?= $e($a['name']) ?></dt><dd><?= $e($a['value']) ?></dd>
<?php endforeach; ?>
      </dl>
    </details>
<?php endif; ?>
<?php if ($r['keeper_link'] !== null && $g['open'] > 0): ?>
    <p><a href="<?= $e($r['keeper_link']) ?>"><?= $word('DUPS', 'keep_instead') ?></a></p>
<?php endif; ?>
  </section>
<?php endforeach; ?>
</div>

<?php if ($shownRows !== []): ?>
<section aria-labelledby="dup-compare-h">
  <h2 id="dup-compare-h"><?= $word('DUPS', 'compare') ?></h2>
  <p class="muted"><?= $word('DUPS', 'compare_text') ?></p>
  <div class="scroll">
  <table class="compare dup-compare">
    <thead>
      <tr>
        <th scope="col"><?= $word('DUPS', 'what') ?></th>
<?php foreach ($rows as $r): ?>
        <th scope="col"><?= $e($r['short']) ?><?php if ($r['state'] === 'keeper'): ?> <?= $word('DUPS', 'kept') ?><?php endif; ?></th>
<?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($shownRows as $key => $f): ?>
      <tr<?php if ($f['differs']): ?> class="differs"<?php elseif ($f['partial']): ?> class="partial"<?php endif; ?>>
        <th scope="row"><?= $e($f['label']) ?><?php if ($f['differs']): ?> <span class="state"><?= $word('DUPS', 'differs') ?></span><?php elseif ($f['partial']): ?> <span class="state"><?= $word('DUPS', 'partial') ?></span><?php endif; ?></th>
<?php foreach ($rows as $r): ?>
<?php $v = $f['values'][$r['id']] ?? null; ?>
        <td><?php if ($v === null || $v === []): ?><span class="muted">–</span><?php elseif ($f['set']): ?><?php foreach ($v as $w): ?><span class="word<?php if ($w['odd']): ?> odd<?php endif; ?>"><?= $e($w['word']) ?></span> <?php endforeach; ?><?php elseif ($key === 'form' && \CW\Ui\Words::has('FORM_VALUE', (string) $v)): ?><?= $word('FORM_VALUE', (string) $v) ?><?php elseif ($key === 'nic_type' && \CW\Ui\Words::has('NIC_TYPE', (string) $v)): ?><?= $word('NIC_TYPE', (string) $v) ?><?php else: ?><?= $e($v) ?><?php endif; ?></td>
<?php endforeach; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<?php if (($g['sweep'] ?? null) !== null && $g['sweep']['pairs'] !== []): ?>
<section aria-labelledby="dup-sweep-h">
  <h2 id="dup-sweep-h"><?= $word('DUPS', 'sweep') ?></h2>
  <p class="muted"><?= $word('DUPS', 'sweep_text') ?><?php if ($g['sweep']['score'] !== null): ?> <?= $say('DUPS', 'sweep_score', $g['sweep']['score']) ?><?php endif; ?></p>
  <ul class="plain dup-sweep">
<?php foreach ($g['sweep']['pairs'] as $p): ?>
<?php $pa = $byVariant[$p['a']] ?? null; $pb = $byVariant[$p['b']] ?? null; ?>
    <li><?php foreach ([[$p['a'], $pa], [$p['b'], $pb]] as $i => [$variant, $row]): ?><?php if ($i === 1): ?> <?= $word('DUPS', 'and') ?> <?php endif; ?><?php if ($row !== null): ?><a href="/ui/review/listing/<?= $e($row['id']) ?>"><?= $e(\CW\Ui\Words::quoted($title($row), '#' . $row['id'])) ?></a><?php else: ?><?= $say('DUPS', 'sweep_option', $variant) ?><?php endif; ?><?php endforeach; ?>:
      <?php if ($p['score'] !== null): ?><?= $say('DUPS', 'sweep_score_pair', $p['score']) ?>; <?php endif; ?><?= $say('DUPS', 'sweep_same', $p['agree'] === [] ? '–' : implode(', ', $p['agree'])) ?><?php if ($p['unknown'] !== []): ?>; <?= $say('DUPS', 'sweep_unknown', implode(', ', $p['unknown'])) ?><?php endif; ?><?php if ($p['barcode'] !== null): ?>; <?= $e($p['barcode']) ?><?php endif; ?><?php if ($pa !== null && $pb !== null && is_numeric($pa['price']) && is_numeric($pb['price'])): ?>; <?= $say('DUPS', 'sweep_prices', \CW\Ui\Html::money($pa['price']), \CW\Ui\Html::money($pb['price'])) ?><?php elseif ($p['price_ratio'] !== null): ?>; <?= $word('DUPS', 'sweep_close') ?><?php endif; ?><?php if ($p['same_product_page']): ?>; <?= $word('DUPS', 'sweep_one_page') ?><?php endif; ?>.</li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($merged_rows !== [] && $can_split): ?>
<section aria-labelledby="dup-undo-h">
  <div class="head-help">
    <h2 id="dup-undo-h"><?= $word('DUPS', 'undo') ?></h2>
    <?= $explain('join_undo', \CW\Ui\Words::DUPS['undo_button']) ?>
  </div>
  <p class="muted"><?= $word('DUPS', 'undo_text') ?></p>
<?php foreach ($merged_rows as $r): ?>
<?php if ($r['undo'] !== null): ?>
<?php $uu = $r['undo']; ?>
  <form class="dup-undo" method="post" action="/ui/review/duplicates/<?= $e($g['id']) ?>/split">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="form_key" value="<?= $e($r['undo_key']) ?>">
    <input type="hidden" name="listing" value="<?= $e($r['id']) ?>">
    <input type="hidden" name="v" value="<?= $e($r['map_version']) ?>">
<?php if ($r['proposal'] !== null || $r['other_proposal'] !== null): ?>
    <input type="hidden" name="proposal" value="<?= $e($r['proposal']['id'] ?? $r['other_proposal']) ?>">
<?php endif; ?>
    <p class="title"><?= $e($title($r)) ?></p>
<?php if ($uu['former_ok']): ?>
    <label class="choice"><input type="radio" name="to" value="former" checked> <span><?= $say('DUPS', 'undo_back', $uu['from_code'], $uu['former_units']) ?><?php if (count($uu['by_warehouse']) > 1): ?> (<?php foreach ($uu['by_warehouse'] as $wh => $q): ?><?= $e(\CW\Ui\Words::STOCK['warehouse_' . $wh] ?? $wh) ?> <?= $n($q) ?> <?php endforeach; ?>)<?php endif; ?><?php if ($uu['with'] !== []): ?>, <?= $word('DUPS', 'undo_with') ?> <?php foreach ($uu['with'] as $w): ?><a href="/ui/review/listing/<?= $e($w) ?>">#<?= $e($w) ?></a> <?php endforeach; ?><?php endif; ?></span></label>
    <label class="choice"><input type="radio" name="to" value="new"> <span><?php if ($uu['new_exact']): ?><?= $say('DUPS', 'undo_new_units', $uu['new_units']) ?><?php else: ?><?= $word('DUPS', 'undo_new_none') ?><?php endif; ?></span></label>
<?php else: ?>
    <input type="hidden" name="to" value="new">
    <p class="muted"><?php if ($uu['new_exact']): ?><?= $say('DUPS', 'undo_only_new', $uu['new_units'], $uu['from_code']) ?><?php elseif ($uu['chain']): ?><?= $say('DUPS', 'undo_only_new_chain', (string) ($r['sku']['code'] ?? '')) ?><?php else: ?><?= $say('DUPS', 'undo_only_new_none', (string) ($r['sku']['code'] ?? '')) ?><?php endif; ?></p>
<?php endif; ?>
    <button type="submit"><?= $word('DUPS', 'undo_button') ?></button>
  </form>
<?php endif; ?>
<?php endforeach; ?>
</section>
<?php endif; ?>
