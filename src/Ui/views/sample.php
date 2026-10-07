<p class="crumbs"><a href="/ui/review/samples"><?= $word('PAGE_TITLE', 'samples') ?></a></p>

<div class="head-help">
  <h1><?= $say('SAMPLE', 'title', $s['name']) ?></h1>
  <?= $explain('spot_check', \CW\Ui\Words::MENU['samples']) ?>
</div>
<?= $intro('sample') ?>

<section class="card spot-box" aria-labelledby="progress-h">
  <div class="progress-line">
    <h2 id="progress-h" class="section-title"><?= $say('SPOT', 'progress', $s['decided'], $s['size']) ?></h2>
    <?= $stateChip('SAMPLE_RESULT', $result) ?>
  </div>
  <ol class="segments" aria-hidden="true">
<?php foreach ($blocks as $b): ?>
    <li class="<?= $e($b['state']) ?>" title="<?= $e($b['title']) ?>"></li>
<?php endforeach; ?>
  </ol>
  <ul class="segments-legend">
    <li><span class="key yes"></span><?= $say('SPOT', 'legend_yes', $counts['yes']) ?></li>
<?php if ($counts['wrong'] > 0): ?>
    <li><span class="key no"></span><?= $say('SPOT', 'legend_wrong', $counts['wrong']) ?></li>
<?php endif; ?>
    <li><span class="key"></span><?= $say('SPOT', 'legend_todo', $counts['todo']) ?></li>
  </ul>
<?php if ($next !== null): ?>
  <p class="actions"><a class="btn primary" href="<?= $e($next['link']) ?>"><?= $say('SPOT', 'next', $next['position'], $s['size']) ?> &rarr;</a></p>
<?php endif; ?>
<?php if ($fit !== []): ?>
  <p class="error" role="alert"><?= $word('SAMPLE', 'unusable') ?></p>
<?php endif; ?>
<?php if ($result === 'passed'): ?>
  <p class="notice"><?= $say('SAMPLE', 'passed', $s['size']) ?></p>
<?php elseif ($result === 'failed'): ?>
  <p class="error" role="alert"><?php if ($s['failed'] === 1): ?><?= $say('SAMPLE', 'failed_one', $s['size']) ?><?php else: ?><?= $say('SAMPLE', 'failed_many', $s['failed'], $s['size']) ?><?php endif; ?></p>
<?php elseif ($result === 'waiting'): ?>
  <p class="note"><?= $say('SAMPLE', 'owner_note', $s['created_by'] ?? \CW\Ui\Words::ROLE['mapping_lead'], $s['size']) ?></p>
<?php endif; ?>
</section>

<div class="table-wrap">
<table class="stack list sample">
  <thead>
    <tr>
      <th scope="col"><?= $word('SAMPLE', 'product') ?></th>
      <th scope="col"><?= $word('SAMPLE', 'result') ?></th>
      <th scope="col" class="num"><?= $word('SAMPLE', 'no') ?></th>
      <th scope="col"><?= $word('SAMPLE', 'suggested') ?></th>
      <th scope="col" class="num"><?= $word('SAMPLE', 'ai') ?></th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($members as $m): ?>
    <tr<?php if ($m['bad']): ?> class="differs"<?php endif; ?>>
      <th scope="row" class="c-head">
        <a class="o-name" href="<?= $e($m['link']) ?>"><?= $e($m['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($m['variant_title'] !== null): ?>
        <span class="o-sub"><?= $e($m['variant_title']) ?></span>
<?php endif; ?>
        <span class="o-sub"><?= $e($m['website']) ?></span>
      </th>
      <td class="c-status"><?= $stateChip('SAMPLE_STATE', $m['state']) ?><?php if ($m['by'] !== null): ?><div class="muted small"><?= $say('SAMPLE', 'by_on', $m['by'], \CW\Ui\Html::when($m['at'])) ?></div><?php endif; ?></td>
      <td class="num" data-label="<?= $word('SAMPLE', 'no') ?>"><?= $n($m['position']) ?></td>
      <td data-label="<?= $word('SAMPLE', 'suggested') ?>"><?= $e($m['sku_code'] ?? '') ?> <span class="muted"><?= $e($m['sku_name'] ?? '') ?></span></td>
      <td class="num" data-label="<?= $word('SAMPLE', 'ai') ?>"><?php if ($m['confidence'] !== null): ?><?= $say('SAMPLE', 'ai_sure', $m['confidence']) ?><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>

<section id="held" aria-labelledby="held-h">
  <div class="head-help">
    <h2 id="held-h"><?= $word('SAMPLE', 'held') ?></h2>
    <?= $explain('set_aside', \CW\Ui\Words::SAMPLE['held']) ?>
  </div>
<?php if ($holds === []): ?>
  <p class="muted"><?= $word('SAMPLE', 'held_none') ?></p>
<?php else: ?>
  <p><strong><?php if ($held_open === 1): ?><?= $word('SAMPLE', 'held_one') ?><?php else: ?><?= $say('SAMPLE', 'held_many', $held_open) ?><?php endif; ?></strong>
<?php if (count($holds) > $held_open): ?>
    <?php if (count($holds) - $held_open === 1): ?><?= $word('SAMPLE', 'held_decided_one') ?><?php else: ?><?= $say('SAMPLE', 'held_decided_many', count($holds) - $held_open) ?><?php endif; ?>
<?php endif; ?>
  </p>
  <div class="table-wrap">
  <table class="stack list held">
    <thead>
      <tr>
        <th scope="col"><?= $word('SAMPLE', 'product') ?></th>
        <th scope="col"><?= $word('SAMPLE', 'now') ?></th>
        <th scope="col"><?= $word('SAMPLE', 'why') ?></th>
        <th scope="col"><?= $word('SAMPLE', 'set_by') ?></th>
      </tr>
    </thead>
    <tbody>
<?php foreach ($holds as $h): ?>
      <tr<?php if ($h['linked_by_batch'] !== null): ?> class="differs"<?php endif; ?>>
        <th scope="row" class="c-head">
          <a class="o-name" href="<?= $e($h['link']) ?>"><?= $e($h['title'] ?? \CW\Ui\Words::LISTING['no_title']) ?></a>
<?php if ($h['variant_title'] !== null): ?>
          <span class="o-sub"><?= $e($h['variant_title']) ?></span>
<?php endif; ?>
          <span class="o-sub"><?= $e($h['website']) ?></span>
        </th>
<?php if ($h['linked_by_batch'] !== null): ?>
        <td class="c-status"><span class="tag bad"><?php if (str_starts_with($h['linked_by_batch'], 'key_bulk:')): ?><?= $word('SAMPLE', 'held_bulk') ?><?php else: ?><?= $word('SAMPLE', 'held_batch') ?><?php endif; ?></span></td>
<?php elseif ($h['open']): ?>
        <td class="c-status"><?= $chip('needs', \CW\Ui\Words::SAMPLE['held_waiting']) ?><?php if ($h['newer']): ?> <span class="muted"><?= $word('SAMPLE', 'held_newer') ?></span><?php endif; ?></td>
<?php else: ?>
        <td class="c-status"><?= $chip('done', \CW\Ui\Words::say('SAMPLE', 'held_done', \CW\Ui\Words::of('LISTING_STATUS', $h['listing_status']))) ?></td>
<?php endif; ?>
        <td data-label="<?= $word('SAMPLE', 'why') ?>"><?= $e($h['reason']) ?></td>
        <td data-label="<?= $word('SAMPLE', 'set_by') ?>"><?= $e($h['by'] ?? '') ?> <span class="muted"><?= $day($h['at']) ?></span></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</section>

<p class="muted"><?= $say('SAMPLE', 'started', $s['created_by'] ?? \CW\Ui\Words::ROLE['mapping_lead'], \CW\Ui\Html::day($s['created_at']), $s['population']) ?></p>
<details class="fold tech-details">
  <summary><?= $word('SAMPLE', 'tech') ?></summary>
  <dl class="tech">
    <dt><?= $word('SAMPLE', 'seed') ?></dt><dd><code><?= $e($s['seed']) ?></code> <span class="muted"><?= $word('SAMPLE', 'seed_text') ?></span></dd>
    <dt><?= $word('SAMPLE', 'method') ?></dt><dd><code><?= $e($s['method']) ?></code></dd>
    <dt><?= $word('SAMPLE', 'rules') ?></dt><dd><code><?= $e($s['band_version']) ?></code></dd>
<?php foreach ($s['strata'] as $st): ?>
    <dt><?= $say('SAMPLE', 'conf', (string) ($st['min_confidence'] ?? ''), (string) ($st['max_confidence'] ?? '')) ?></dt><dd><?= $say('SAMPLE', 'of', (int) ($st['sample'] ?? 0), (int) ($st['population'] ?? 0)) ?></dd>
<?php endforeach; ?>
<?php foreach ($s['overrides'] as $o): ?>
    <dt><?= $word('SAMPLE', 'override') ?></dt><dd><?= $say('SAMPLE', 'override_text', (string) ($o['name'] ?? ''), (string) ($o['why'] ?? '')) ?></dd>
<?php endforeach; ?>
<?php if ($s['excluded'] !== []): ?>
    <dt><?= $word('SAMPLE', 'left_out') ?></dt><dd><?php foreach ($s['excluded'] as $why => $count): ?><span class="tag"><code><?= $e($why) ?></code>: <?= $n($count) ?></span> <?php endforeach; ?></dd>
<?php endif; ?>
<?php if ($fit !== []): ?>
    <dt><?= $word('SAMPLE', 'unfit') ?></dt><dd><?= $e(implode('; ', $fit)) ?></dd>
<?php endif; ?>
    <dt><?= $word('SAMPLE', 'bulk') ?></dt><dd><?php if ($s['bulk_linked'] > 0): ?><?= $say('SAMPLE', 'bulk_run', $s['bulk_linked'], (string) $s['batch']) ?><?php if ($s['bulk_undone'] > 0): ?> <?= $say('SAMPLE', 'undone', $s['bulk_undone']) ?><?php endif; ?><?php else: ?><?= $word('SAMPLE', 'bulk_not') ?><?php endif; ?></dd>
  </dl>
</details>
