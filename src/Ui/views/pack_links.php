<?php
// Items › Multipacks › Pack links, a simple list (the approved redesign of 30 Oct 2026, the Items artboard's look; docs/decisions.md RD-A7;
// W11.7 before): Multipacks' ONE Show choice; every variant matched with more than 1 unit a sale, the ones something needs a look at first (a
// pack item of that many exists, or the item is a pack itself), then the rest; at most five columns: the variant (it opens its page, where
// its match is changed), the store, its item, units a sale and, for the first group, what to check. Read only.
?>
<?= $head(\CW\Ui\Words::INV_LIST['packs_on'], intro: 'pack_links') ?>
<div class="toolbar">
  <?= $partial('rda_show', ['choices' => $show, 'label' => \CW\Ui\Words::INV_LIST['show']]) ?>
</div>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PACKS['links_none'], \CW\Ui\Words::PACKS['links_none_text']) ?>
<?php else: ?>
<?php foreach ($groups as $g): ?>
<div class="grp-block doc-group <?= $e($g['tone']) ?>">
  <h3 class="grp-title"><button class="grp-toggle" type="button" aria-expanded="true" aria-controls="g-<?= $e($g['key']) ?>"><?= $e($g['name']) ?></button> <span class="grp-count"><?= $n(count($g['rows'])) ?></span></h3>
  <div class="table-wrap doc-board" id="g-<?= $e($g['key']) ?>">
  <table class="stack list board doc-list pack-links">
    <thead>
      <tr>
        <th scope="col" class="c-item"><?= $word('PACKS', 'variant') ?></th>
        <th scope="col"><?= $word('PACKS', 'store') ?></th>
        <th scope="col"><?= $word('PACKS', 'item') ?></th>
        <th scope="col" class="num"><?= $word('PACKS', 'per_sale') ?></th>
<?php if ($g['key'] === 'check'): ?>
        <th scope="col"><?= $word('PACKS', 'checks') ?></th>
<?php endif; ?>
      </tr>
    </thead>
    <tbody>
<?php foreach ($g['rows'] as $r): ?>
      <tr>
        <th scope="row" class="c-head c-item"><a class="o-name row-link" href="<?= $u('/ui/review/listing/' . $r['id']) ?>"><?php if ($r['variant'] !== ''): ?><?= $e($r['variant']) ?><?php else: ?><?= $e($r['option']) ?><?php endif; ?></a></th>
        <td data-label="<?= $word('PACKS', 'store') ?>"><?= $e($r['store']) ?></td>
        <td class="c-next" data-label="<?= $word('PACKS', 'item') ?>"><a href="<?= $u('/ui/items/' . $r['item']['id']) ?>"><?= $e($r['item']['code']) ?></a> <?= $e($r['item']['name']) ?></td>
        <td class="num" data-label="<?= $word('PACKS', 'per_sale') ?>"><?= $n($r['units']) ?></td>
<?php if ($g['key'] === 'check'): ?>
        <td class="c-next c-wide" data-label="<?= $word('PACKS', 'checks') ?>">
<?php foreach ($r['flags'] as $fl): ?>
          <span class="tag <?= $e($fl['tone']) ?>"><?= $e($fl['text']) ?></span>
<?php endforeach; ?>
<?php if ($r['pack'] !== null): ?>
          <a href="<?= $u('/ui/items/' . $r['pack']['id']) ?>"><?= $e($r['pack']['code']) ?></a>
<?php endif; ?>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('PACKS', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('PACKS', 'prev') ?></a><?php endif; ?>
  <span><?= $say('PACKS', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('PACKS', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
