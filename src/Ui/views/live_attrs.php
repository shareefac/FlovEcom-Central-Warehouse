<?php
// Who is here on a draft (the live drafts plan, step 3b; docs/decisions.md LV18-LV25): what app.js's block "live" reads from the element it
// starts on (the order's editor form, or a draft's paper for a person who may only look). $live: Ui\Controller\LiveController::pageData():
// the poll's address (data-live), this page's own tab (data-live-tab, new on every page drawn), the version the page shows (data-live-v), the
// page and the sign-in page with the way back to it, the rhythm (data-live-*-ms: Presence's numbers and the settings, so the script holds
// none); then every word the script shows (Words::LIVE, data-live-<code>: dev.md rule 17).
?> data-live="<?= $e($live['url']) ?>" data-live-tab="<?= $e($live['tab']) ?>" data-live-v="<?= $e($live['v']) ?>" data-live-page="<?= $e($live['page']) ?>" data-live-login="<?= $e($live['login']) ?>" data-live-ms="<?= $e($live['ms']) ?>" data-live-away-ms="<?= $e($live['away_ms']) ?>" data-live-stop-ms="<?= $e($live['stop_ms']) ?>" data-live-max-ms="<?= $e($live['max_ms']) ?>" data-live-slow-ms="<?= $e($live['slow_ms']) ?>" data-live-active-ms="<?= $e($live['active_ms']) ?>" data-live-jitter-ms="<?= $e($live['jitter_ms']) ?>" data-live-say-ms="<?= $e($live['say_ms']) ?>"<?php foreach (array_keys(\CW\Ui\Words::LIVE) as $code): ?> data-live-<?= $e(str_replace('_', '-', $code)) ?>="<?= $word('LIVE', $code) ?>"<?php endforeach; ?>
