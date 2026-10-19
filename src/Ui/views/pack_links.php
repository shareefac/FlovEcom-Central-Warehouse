<?= $head(\CW\Ui\Words::PAGE_TITLE['pack_links'], intro: 'pack_links') ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::PACKS['links_none'], \CW\Ui\Words::PACKS['links_none_text']) ?>
<?php else: ?>
<p class="muted"><?php if ($total === 1): ?><?= $word('PACKS', 'links_count_one') ?><?php else: ?><?= $say('PACKS', 'links_count_many', $total) ?><?php endif; ?></p>
<div class="table-wrap">
<table class="stack list pack-links">
  <thead>
    <tr>
      <th scope="col"><?= $word('PACKS', 'variant') ?></th>
      <th scope="col"><?= $word('PACKS', 'store') ?></th>
      <th scope="col"><?= $word('PACKS', 'item') ?></th>
      <th scope="col" class="num"><?= $word('PACKS', 'per_sale') ?></th>
      <th scope="col"><?= $word('PACKS', 'checks') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head"><a class="o-name" href="<?= $u('/ui/review/listing/' . $r['id']) ?>"><?php if ($r['variant'] !== ''): ?><?= $e($r['variant']) ?><?php else: ?><?= $e($r['option']) ?><?php endif; ?></a></th>
      <td data-label="<?= $word('PACKS', 'store') ?>"><?= $e($r['store']) ?></td>
      <td data-label="<?= $word('PACKS', 'item') ?>"><a href="<?= $u('/ui/items/' . $r['item']['id']) ?>"><?= $e($r['item']['code']) ?></a> <?= $e($r['item']['name']) ?></td>
      <td class="num" data-label="<?= $word('PACKS', 'per_sale') ?>"><?= $n($r['units']) ?></td>
      <td class="c-wide" data-label="<?= $word('PACKS', 'checks') ?>">
<?php if ($r['flags'] === []): ?>
        <span class="muted"><?= $word('PACKS', 'nothing') ?></span>
<?php endif; ?>
<?php foreach ($r['flags'] as $fl): ?>
        <span class="pack-flag"><?= $chip($fl['tone'], $fl['text']) ?></span>
<?php endforeach; ?>
<?php if ($r['pack'] !== null): ?>
        <a href="<?= $u('/ui/items/' . $r['pack']['id']) ?>"><?= $e($r['pack']['code']) ?></a>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="<?= $say('PACKS', 'page', $page_no, $pages) ?>">
<?php if ($prev_link !== null): ?><a href="<?= $e($prev_link) ?>" rel="prev"><?= $word('PACKS', 'prev') ?></a><?php endif; ?>
  <span><?= $say('PACKS', 'page', $page_no, $pages) ?></span>
<?php if ($next_link !== null): ?><a href="<?= $e($next_link) ?>" rel="next"><?= $word('PACKS', 'next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
