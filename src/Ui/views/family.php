<?php
// One family, a record page (the approved design of 29 Oct 2026, PO_View; docs/decisions.md W11.5): the link back to Families, its name with
// its status block and its bar at the head's right (family_bar: Add items, More: rename, switch off), what a list put in or took out (with what
// was left out and why), the figures strip (its items, added, last changed), then the paper card: the family and its note beside the facts,
// and its items as the card's lines (each opens its item; whoever keeps the families ticks items and takes them out: the form is theirs).
$bar = $partial('family_bar', ['f' => $f, 'canManage' => $canManage, 'addKey' => $addKey, 'typed' => $typed, 'errorCode' => $errorCode]);
?>
<p class="crumbs"><a href="/ui/items/families"><?= $word('FAMILY', 'back') ?></a></p>
<div class="doc-head">
<?= $head((string) $f['name'], intro: 'family', look: $lookOnly, chips: $f['active'] ? $chip('done', \CW\Ui\Words::FAMILY['on']) : $chip('off', \CW\Ui\Words::FAMILY['off']), side: $bar) ?>
</div>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>

<?php if ($result !== null): ?>
<section class="card bulk-result" aria-labelledby="result-h">
  <h2 id="result-h" class="visually-hidden"><?= $word('FAMILY', 'bulk') ?></h2>
<?php if ($result['added'] !== []): ?>
  <p><strong><?= $say('FAMILY', 'result_added', count($result['added'])) ?></strong></p>
  <ul class="plain result-added">
<?php foreach ($result['added'] as $a): ?>
    <li><a href="<?= $u('/ui/items/' . $a['sku_id']) ?>"><?= $e($a['code']) ?></a> <?= $e($a['name']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($result['removed'] !== []): ?>
  <p><strong><?= $say('FAMILY', 'result_removed', count($result['removed'])) ?></strong></p>
  <ul class="plain result-removed">
<?php foreach ($result['removed'] as $a): ?>
    <li><a href="<?= $u('/ui/items/' . $a['sku_id']) ?>"><?= $e($a['code']) ?></a> <?= $e($a['name']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($result['added'] === [] && $result['removed'] === []): ?>
  <p><?= $word('FAMILY', 'result_none') ?></p>
<?php endif; ?>
<?php if ($result['skipped'] !== []): ?>
  <p><strong><?= $say('FAMILY', 'result_skipped', count($result['skipped'])) ?></strong></p>
  <ul class="plain result-skipped">
<?php foreach ($result['skipped'] as $s): ?>
    <li><?php if ($s['sku_id'] !== null): ?><a href="<?= $u('/ui/items/' . $s['sku_id']) ?>"><?= $e($s['text']) ?></a><?php else: ?><code><?= $e($s['text']) ?></code><?php endif; ?> · <?= $e($s['why']) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="doc-progress rp-figs" aria-label="<?= $word('FAMILY', 'figures') ?>">
  <div class="doc-step"><p class="doc-step-label"><?= $word('FAMILY', 'fig_items') ?></p><p class="doc-step-big"><?= $n($total) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('FAMILY', 'fig_made') ?></p><p class="doc-step-big"><?= $day($madeOn) ?></p></div>
  <div class="doc-step"><p class="doc-step-label"><?= $word('FAMILY', 'fig_changed') ?></p><p class="doc-step-big"><?php if ($changedOn !== null): ?><?= $day($changedOn) ?><?php endif; ?></p></div>
</section>

<article class="doc-view" aria-label="<?= $word('FAMILY', 'members') ?>">
<?= $partial('doc_party', ['party' => $party, 'facts' => $docFacts]) ?>
<?php if ($members === []): ?>
  <?= $empty(\CW\Ui\Words::FAMILY['members_none'], \CW\Ui\Words::FAMILY['members_none_text']) ?>
<?php else: ?>
<?php if ($canManage): ?>
  <form class="family-members" method="post" action="<?= $u('/ui/items/families/' . $f['id'] . '/remove') ?>" data-unsaved="1" data-unsaved-text="<?= $word('DOC', 'unsaved') ?>" data-leaves="1">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
<?php endif; ?>
    <div class="table-wrap doc-board <?php if ($f['active']): ?>done<?php else: ?>off<?php endif; ?>">
    <table class="stack list board doc-list family-items">
      <thead>
        <tr>
<?php if ($canManage): ?>
          <th scope="col" class="c-pick"><span class="visually-hidden"><?= $word('FAMILY', 'remove_button') ?></span></th>
<?php endif; ?>
          <th scope="col" class="c-item"><?= $word('FAMILY', 'item') ?></th>
          <th scope="col"><?= $word('FAMILY', 'brand') ?></th>
          <th scope="col"><?= $word('FAMILY', 'flavour') ?></th>
          <th scope="col"><?= $word('FAMILY', 'strength') ?></th>
          <th scope="col" class="num"><?= $word('FAMILY', 'on_hand') ?></th>
          <th scope="col"><?= $word('FAMILY', 'since') ?></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($members as $m): ?>
        <tr>
<?php if ($canManage): ?>
          <td class="c-pick" data-label=""><label class="pick"><input type="checkbox" name="pick_<?= $e($m['id']) ?>" value="1"><span class="visually-hidden"><?= $say('FAMILY', 'tick', $m['code']) ?></span></label></td>
<?php endif; ?>
          <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $m['id']) ?>"><?= $e($m['name']) ?></a><span class="o-sub"><?= $e($m['code']) ?></span></th>
          <td data-label="<?= $word('FAMILY', 'brand') ?>"><?= $e($m['brand']) ?></td>
          <td data-label="<?= $word('FAMILY', 'flavour') ?>"><?= $e($m['flavour']) ?></td>
          <td data-label="<?= $word('FAMILY', 'strength') ?>"><?= $e($m['strength']) ?></td>
          <td class="num" data-label="<?= $word('FAMILY', 'on_hand') ?>"><?= $n($m['on_hand']) ?></td>
          <td data-label="<?= $word('FAMILY', 'since') ?>"><?= $day($m['since']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php if ($canManage): ?>
    <p class="actions"><button type="submit" class="btn"><?= $word('FAMILY', 'remove_button') ?></button></p>
  </form>
<?php endif; ?>
<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="<?= $say('FAMILY', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('FAMILY', 'prev') ?></a><?php endif; ?>
    <span><?= $say('FAMILY', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('FAMILY', 'next') ?></a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</article>
