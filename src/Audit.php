<?php

declare(strict_types=1);

namespace CW;

/** Appends to audit_log (append-only; no FKs, so an audit write never fails on a reference). */
final class Audit
{
    /** @param array<string, mixed> $detail never secrets */
    public static function write(
        Db $db,
        Caller $caller,
        string $action,
        ?string $entityType,
        ?string $entityId,
        ?string $idemKey,
        array $detail = [],
    ): void {
        $db->exec(
            'INSERT INTO audit_log (actor, staff_user_id, channel_id, action, entity_type, entity_id, idem_key, ip, detail) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $caller->actor,
                $caller->staffUserId,
                $caller->channelId,
                substr($action, 0, 64),
                $entityType,
                // mb_strcut: at most 64 bytes without splitting a UTF-8 character (a split one
                // is an invalid string, which strict mode refuses with 1366).
                $entityId === null ? null : mb_strcut($entityId, 0, 64, 'UTF-8'),
                $idemKey,
                $caller->ip,
                $detail === [] ? null : Idempotency::json($detail),
            ],
        );
    }
}
