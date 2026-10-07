<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\OpResult;

/**
 * One effect per form (docs/decisions.md I46): every POST that CREATES something (a supplier, a supplier item, a manual
 * price; later a PO, a copy, an amendment, reorder drafts, an anomaly) carries a hidden `form_key` drawn when the form
 * was drawn (newKey(): 32 hex characters from random_bytes(16)), and runs through Idempotency::run under the key
 * `ui:<staff id>:<form_key>`. The idempotency scope of staff callers is source 'staff', shared by every person, hence
 * the staff id in the key.
 *
 *  - a missing or malformed key: 400 bad_form_key (the words: Words::ERROR);
 *  - the same form sent again (a double click, a browser retry, the back button): the stored result is replayed, so the
 *    person lands on the same page and nothing is created twice;
 *  - the same key with other values: 422 idempotency_key_reused ("this form was already sent with other values: reload");
 *  - a refusal (CwException) stores nothing: the person corrects the form and sends the same key again.
 *
 * The effect runs inside Idempotency's transaction (services join it) and returns an OpResult whose body carries
 * `redirect`, the local path the person is sent to (303).
 */
final class FormOnce
{
    public const FIELD = 'form_key';

    public static function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $request what the form sent (without csrf and form_key): its hash is stored with the key
     * @param \Closure(Db): OpResult $effect
     */
    public static function run(Context $ctx, string $action, array $request, \Closure $effect): OpResult
    {
        $key = $ctx->req->field(self::FIELD);
        if ($key === null || preg_match('/^[0-9a-f]{32}$/D', $key) !== 1) {
            throw new CwException('bad_form_key', Words::error('bad_form_key'), 400);
        }
        $me = $ctx->me();
        $r = (new Idempotency($ctx->db))->run(Caller::staff($me->id, $ctx->req->ip), "ui:{$me->id}:{$key}", $action, $ctx->req->path, $request, null, null,
            static fn (Db $db): OpResult => $effect($db));
        if (($r->body['error'] ?? null) === 'idempotency_key_reused') {
            throw new CwException('idempotency_key_reused', Words::error('idempotency_key_reused'), 422);
        }
        return $r;
    }

    /** The 303 of a FormOnce result (its `redirect`). */
    public static function redirect(OpResult $r): HtmlResponse
    {
        $to = $r->body['redirect'] ?? null;
        if (!is_string($to)) {
            throw new \LogicException('a FormOnce effect returns a redirect');
        }
        return HtmlResponse::redirect($to);
    }
}
