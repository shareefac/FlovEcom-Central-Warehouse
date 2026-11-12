<?php
// Inventory › Doubtful stock › Checked (0066; docs/decisions.md DS1-): the doubtful items of one store already answered, newest first, 50 a page:
// the item, the answer (checked on the shelf, or not needed), what was on the shelf and what it changed (with its count record), or why it was
// not needed; who and when. The ONE Show (To check / Checked, with the store in its panel when several stores have items). A phone gets cards.
?>
<?= $head(\CW\Ui\Words::MENU['doubtful'], intro: 'doubtful_checked') ?>
<?php if (count($stores) > 1): ?>
<form class="toolbar" method="get" action="/ui/stock/doubtful/checked" aria-label="<?= $word('UI', 'filter') ?>">
  <?= $showStart() ?>
  <div class="tb-state">
    <label for="tb-store"><?= $word('DOUBT', 'store') ?></label>
    <select id="tb-store" name="store">
<?php foreach ($stores as $s): ?>
      <option value="<?= $e($s['code']) ?>"<?php if ($store !== null && $s['id'] === $store['id']): ?> selected<?php endif; ?>><?= $e($s['name']) ?></option>
<?php endforeach; ?>
    </select>
    <button class="btn sm" type="submit"><?= $word('DOC_LIST', 'show') ?></button>
  </div>
  <?= $showEnd() ?>
</form>
<?php endif; ?>
<?php if ($rows === []): ?>
<?= $empty(\CW\Ui\Words::DOUBT['checked_none'], \CW\Ui\Words::DOUBT['checked_none_text'], '/ui/stock/doubtful', \CW\Ui\Words::SEGMENT['doubtful']) ?>
<?php else: ?>
<?php if ($shown !== null): ?>
<p class="muted"><?= $e($shown) ?></p>
<?php endif; ?>
<div class="table-wrap doc-board">
<table class="stack list board doc-list doubt-checked">
  <thead>
    <tr>
      <th scope="col" class="c-item"><?= $word('DOUBT', 'col_item') ?></th>
      <th scope="col"><?= $word('DOUBT', 'col_answered') ?></th>
      <th scope="col" class="num"><?= $word('DOUBT', 'col_counted') ?></th>
      <th scope="col" class="num"><?= $word('DOUBT', 'col_booked') ?></th>
      <th scope="col"><?= $word('DOUBT', 'col_who') ?></th>
      <th scope="col"><?= $word('DOUBT', 'col_when') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row" class="c-head c-item"><a class="o-name" href="<?= $u('/ui/items/' . $r['sku_id']) ?>"><?= $e($r['name']) ?></a><span class="o-sub"><?= $e($r['code']) ?> · <?= $word('DOUBT_WHY', $r['reason']) ?></span></th>
      <td data-label="<?= $word('DOUBT', 'col_answered') ?>"><span class="tag <?= $e($r['tone']) ?>"><?= $e($r['state']) ?></span><?php if ($r['resolved_note'] !== null): ?><span class="o-sub"><?= $e($r['resolved_note']) ?></span><?php endif; ?><?php if ($r['record'] !== null && $r['record_href'] !== null): ?><span class="o-sub"><a href="<?= $u($r['record_href']) ?>"><?= $e($r['record']) ?></a></span><?php endif; ?></td>
      <td class="num" data-label="<?= $word('DOUBT', 'col_counted') ?>"><?php if ($r['counted_qty'] !== null): ?><?= $n($r['counted_qty']) ?><?php endif; ?></td>
      <td class="num" data-label="<?= $word('DOUBT', 'col_booked') ?>"><?= $e($r['booked']) ?></td>
      <td data-label="<?= $word('DOUBT', 'col_who') ?>"><?= $e($r['resolved_by_name']) ?></td>
      <td data-label="<?= $word('DOUBT', 'col_when') ?>"><?= $when($r['resolved_at']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($prev !== null || $next !== null): ?>
<p class="pager"><?php if ($prev !== null): ?><a href="<?= $e($prev) ?>"><?= $word('DOUBT', 'prev') ?></a><?php endif; ?><?php if ($next !== null): ?><a href="<?= $e($next) ?>"><?= $word('DOUBT', 'next') ?></a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
