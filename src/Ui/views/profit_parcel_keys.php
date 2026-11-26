<?php
// Profit › Delivery bills › Parcel2Go › Keys (CW-1160; docs/decisions.md PGL6; the owner, 26 Nov 2026, Q3): for each Parcel2Go account, the
// Client ID and the secret the owner made on Parcel2Go (My Account > API Clients, Client Credentials) pasted here. Write-only: nothing typed is
// ever shown back; the page says only whether a key was set, when and from whose paste, and whether a paste waits for the job (a minute).
$D = \CW\Ui\Words::DELIVERY;
?>
<?= $head($D['p2g_key_title'], back: ['/ui/profit/delivery/parcel2go', $D['p2g_title']]) ?>
<?php if ($notice !== null): ?>
<p class="notice" role="status"><?= $e((string) $notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="error" role="alert"><?= $e((string) $error) ?></p>
<?php endif; ?>
<p class="hint"><?= $word('DELIVERY', 'p2g_key_hint') ?></p>
<?php foreach ($accounts as $a): ?>
<form class="record p2g-key" method="post" action="/ui/profit/delivery/parcel2go/key" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="account" value="<?= $e((string) $a['key']) ?>">
  <fieldset>
    <legend><?= $e((string) $a['name']) ?></legend>
    <p class="muted"><?php if ($a['state']['waiting']): ?><?= $word('DELIVERY', 'p2g_key_waiting') ?><?php elseif ($a['state']['not_taken']): ?><?= $word('DELIVERY', 'p2g_key_not_taken') ?><?php elseif ($a['state']['set_at'] !== null): ?><?= $say('DELIVERY', 'p2g_key_set', \CW\Ui\Html::when((string) $a['state']['set_at']), (string) ($a['state']['set_by'] ?? '')) ?><?php else: ?><?= $word('DELIVERY', 'p2g_key_none') ?><?php endif; ?></p>
    <div class="field"><label for="p2g-id-<?= $e((string) $a['key']) ?>"><?= $word('DELIVERY', 'p2g_client_id') ?></label><input id="p2g-id-<?= $e((string) $a['key']) ?>" type="text" name="client_id" value="" autocomplete="off" spellcheck="false" required></div>
    <div class="field"><label for="p2g-secret-<?= $e((string) $a['key']) ?>"><?= $word('DELIVERY', 'p2g_client_secret') ?></label><input id="p2g-secret-<?= $e((string) $a['key']) ?>" type="password" name="client_secret" value="" autocomplete="new-password" required></div>
    <p class="actions"><button type="submit" class="btn"><?= $word('DELIVERY', 'p2g_key_save') ?></button></p>
  </fieldset>
</form>
<?php endforeach; ?>
