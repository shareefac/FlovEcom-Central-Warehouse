<?php
// Approvals › Waiting for you (docs/decisions.md AV1-AV14; the approved mockups AP_Inbox, AP_Phone): every record the person may answer,
// grouped by kind (a coloured dot, the kind's name and count, the link to its own page), each row a link that opens the record; the open
// record on the right (first on a phone: approvals_record), with its facts and its one decision. No help paragraphs. The open record's row is
// the page shown: no link (AV19, as a tab of the page shown, CV13). The approved Approvals artboard of 30 Oct 2026 (docs/decisions.md RD-C13):
// the Show beside the heading (everything waiting, or one kind, each with its count; a GET form, its button for a browser without the script);
// a row keeps the kind shown. ERPNext corrections shown on their own (ConfirmAll.dc.html, docs/decisions.md CA1): two groups, Ready to
// confirm and Needs a look (a Needs a look row's line is why, in amber), the green "Confirm all ready (N)" beside the Show, and the board's
// "The list is split in two" on the right until a row is opened (approvals_split).
$bar = $choices === [] ? '' : $partial('approvals_show', ['choices' => $choices, 'everything' => $everything, 'confirmAll' => $confirmAll]);
?>
<?= $head(\CW\Ui\Words::MENU['inbox'], intro: 'inbox', side: $bar) ?>
<?php if ($error !== null): ?>
<p class="error" role="alert" data-code="<?= $e($errorCode) ?>"><?= $e($error) ?></p>
<?php endif; ?>
<?php if ($groups === [] && $show !== null && $everything > 0): ?>
<?= $empty(\CW\Ui\Words::say('RD_INBOX', 'none_kind', \CW\Ui\Words::INBOX['kind_' . $show]), \CW\Ui\Words::say('RD_INBOX', 'others_wait', $everything), '/ui/approvals', \CW\Ui\Words::RD_INBOX['see_all']) ?>
<?php elseif ($groups === [] && $show !== null): ?>
<?= $empty(\CW\Ui\Words::say('RD_INBOX', 'none_kind', \CW\Ui\Words::INBOX['kind_' . $show]), \CW\Ui\Words::INBOX['none_text'], '/ui/approvals', \CW\Ui\Words::RD_INBOX['see_all']) ?>
<?php elseif ($groups === []): ?>
<?= $empty(\CW\Ui\Words::INBOX['none'], \CW\Ui\Words::INBOX['none_text']) ?>
<?php else: ?>
<div class="ap-inbox rd-inbox">
  <section class="card ap-list" aria-label="<?= $word('INBOX', 'list') ?>">
<?php foreach ($groups as $g): ?>
    <div class="ap-group <?= $e($g['tone']) ?>">
      <h2 class="ap-gh" id="ap-g-<?= $e($g['id']) ?>"><span class="ap-dot" aria-hidden="true"></span><span class="ap-gname"><?= $e($g['name']) ?></span> <span class="ap-gcount"><?= $n($g['count']) ?></span><?php if ($g['href'] !== null): ?>
        <a class="ap-all" href="<?= $u($g['href']) ?>" aria-label="<?= $say('INBOX', 'all_named', \CW\Ui\Words::INBOX['kind_' . $g['kind']]) ?>"><?= $word('INBOX', 'all') ?></a><?php endif; ?></h2>
      <ul class="ap-rows">
<?php foreach ($g['rows'] as $r): ?>
        <li><a class="ap-row<?php if ($r['open']): ?> sel<?php endif; ?>"<?php if ($r['open']): ?> aria-current="true"<?php else: ?> href="<?= $u('/ui/approvals', ['open' => $r['key'], 'show' => $show]) ?>"<?php endif; ?>><span class="ap-t"><?= $e($r['title']) ?></span><span class="ap-m<?php if ($r['look'] ?? false): ?> ap-why<?php endif; ?>"><?= $e($r['meta']) ?></span></a></li>
<?php endforeach; ?>
      </ul>
<?php if ($g['more']): ?>
      <p class="ap-more"><?= $say('INBOX', 'more_rows', $g['shown'] ?? count($g['rows'])) ?></p>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </section>
<?php if ($record !== null): ?>
<?= $partial('approvals_record', ['record' => $record, 'show' => $show]) ?>
<?php elseif ($split !== null): ?>
<?= $partial('approvals_split', ['rules' => $split]) ?>
<?php endif; ?>
</div>
<?php endif; ?>
