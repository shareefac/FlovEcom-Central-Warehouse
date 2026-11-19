<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\CwException;
use CW\Db;

/**
 * Who started a record and who changed it while it was a draft, and when (docs/decisions.md DA1-DA4). A draft is changed by anyone whose job
 * keeps that kind of record, not only by the person who started it (the owner, 18 Nov 2026: "anyone who have access should be able to edit
 * it"), so the record tells them apart from the audit rows every save already writes under the person who saved (Audit::write: staff_user_id
 * and actor). created_by stays the person who started it. Read only; every query uses ix_audit_entity.
 *
 * Used for a record's history of changes (list()) and for a save refused because someone saved the draft after the page was drawn (409
 * version_conflict: conflict() names who and when).
 */
final class DraftChanges
{
    /** The audit actions that start a draft (the document base's and each module's, written in the same transaction). */
    public const STARTED = ['document.create', 'po.create', 'po.copy', 'grn.create', 'stockop.create', 'handover.create', 'handover.copy', 'return.create',
        'invoice.create', 'credit_note.create', 'count.open'];
    /**
     * The audit actions that change a draft: its header and lines (the document base's and each module's save), a file attached, a delivery's
     * bench check and supplier invoice, an invoice's supplier, receiving and timed posting, a count sheet's quantities, items and choices.
     */
    public const CHANGED = ['document.update', 'document.lines', 'document.attach', 'po.save', 'po.import_lines', 'po.new_item', 'grn.save', 'grn.import_lines',
        'grn.bench', 'grn.invoice', 'stockop.header', 'handover.header', 'return.header', 'invoice.header', 'credit_note.header', 'invoice.supplier',
        'invoice.receiving', 'invoice.add_order', 'invoice.add_delivery', 'invoice.schedule', 'count.upload', 'count.enter', 'count.add', 'count.as_counted'];
    /** The most entries the history lists (the newest); the rest are counted. */
    public const SHOWN = 30;
    /** The most audit rows read for one record's history (a count sheet saves each quantity as it is entered). */
    private const READ = 1000;

    /**
     * The newest change of a record by a person (its start included), or null when CW knows none.
     *
     * @return array{staff_user_id: int, name: string, at: string}|null at: the audit row's time (UTC DATETIME(6))
     */
    public static function last(Db $db, int $documentId): ?array
    {
        $r = $db->one('SELECT a.staff_user_id, a.created_at, u.display_name FROM audit_log a LEFT JOIN staff_user u ON u.id = a.staff_user_id '
            . "WHERE a.entity_type = 'document' AND a.entity_id = ? AND a.staff_user_id IS NOT NULL AND a.action IN (" . self::actionList() . ') '
            . 'ORDER BY a.id DESC LIMIT 1', [(string) $documentId, ...self::STARTED, ...self::CHANGED]);
        // A box saved by itself (docs/decisions.md LS6) is audited once an hour a person: its own row in draft_change names who and when. It is
        // the newest change while the draft is still at the version it made (a whole save since moved the version on: the audit row is newer).
        $live = self::lastLive($db, $documentId);
        if ($live !== null && ($r === null || (int) $live['doc_version'] === (int) $db->value('SELECT version FROM document WHERE id = ?', [$documentId]))) {
            $r = $live;
        }
        if ($r === null) {
            return null;
        }
        return ['staff_user_id' => (int) $r['staff_user_id'], 'name' => (string) ($r['display_name'] ?? '#' . $r['staff_user_id']), 'at' => (string) $r['created_at']];
    }

    /** @return array<string, mixed>|null the newest change of a draft saved box by box (draft_change, 0072), or null (none; no table yet) */
    private static function lastLive(Db $db, int $documentId): ?array
    {
        try {
            return $db->one('SELECT c.staff_user_id, c.created_at, c.doc_version, u.display_name FROM draft_change c LEFT JOIN staff_user u ON u.id = c.staff_user_id '
                . "WHERE c.kind IN ('" . implode("', '", array_keys(\CW\Live\Presence::KINDS)) . "') AND c.record_id = ? AND c.op <> 'conflict' ORDER BY c.id DESC LIMIT 1",
                [$documentId]);
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1146) {
                return null; // the code a moment before 0072
            }
            throw $e;
        }
    }

    /**
     * A record's history of changes, oldest first: one entry per run of one person's audit rows in the same minute (a save writes several
     * rows: the module's and the document base's), its time the run's last row's, "started" when a row of it started the record. At most
     * $shown entries (the newest); `more` counts the older ones left out (a record with more than READ rows counts only those).
     *
     * @return array{entries: list<array{kind: string, staff_user_id: int, name: string, at: string}>, more: int} kind: started | changed
     */
    public static function list(Db $db, int $documentId, int $shown = self::SHOWN): array
    {
        $rows = array_reverse($db->all('SELECT a.action, a.staff_user_id, a.created_at, u.display_name FROM audit_log a LEFT JOIN staff_user u ON u.id = a.staff_user_id '
            . "WHERE a.entity_type = 'document' AND a.entity_id = ? AND a.staff_user_id IS NOT NULL AND a.action IN (" . self::actionList() . ') '
            . 'ORDER BY a.id DESC LIMIT ' . self::READ, [(string) $documentId, ...self::STARTED, ...self::CHANGED]));
        $entries = [];
        foreach ($rows as $r) {
            $kind = in_array($r['action'], self::STARTED, true) ? 'started' : 'changed';
            $who = (int) $r['staff_user_id'];
            $at = (string) $r['created_at'];
            $n = count($entries) - 1;
            if ($n >= 0 && $entries[$n]['staff_user_id'] === $who && substr($entries[$n]['at'], 0, 16) === substr($at, 0, 16)) {
                // The same save (several rows), or the same person's saves in the same minute; started it, if any of them started it.
                $entries[$n]['at'] = $at;
                $entries[$n]['kind'] = $kind === 'started' ? 'started' : $entries[$n]['kind'];
                continue;
            }
            $entries[] = ['kind' => $kind, 'staff_user_id' => $who, 'name' => (string) ($r['display_name'] ?? '#' . $who), 'at' => $at];
        }
        $more = max(0, count($entries) - $shown);
        return ['entries' => array_slice($entries, $more), 'more' => $more];
    }

    /**
     * The refusal of a save drawn on an older version of a record (409 version_conflict): the version now and the one the page was drawn
     * with, and who saved it last and when when CW knows (changed_by_id, changed_by, changed_at: UTC), for the screens to say in words.
     */
    public static function conflict(Db $db, int $documentId, string $what, int $version, int $expected): CwException
    {
        $last = self::last($db, $documentId);
        $detail = ['version' => $version, 'expected_version' => $expected];
        if ($last === null) {
            return new CwException('version_conflict', "the {$what} changed since this page was drawn: reload it and try again", 409, $detail);
        }
        return new CwException('version_conflict', "the {$what} changed since this page was drawn (last saved by {$last['name']} at {$last['at']} UTC): "
            . 'reload it and try again', 409, $detail + ['changed_by_id' => $last['staff_user_id'], 'changed_by' => $last['name'], 'changed_at' => $last['at']]);
    }

    private static function actionList(): string
    {
        return implode(', ', array_fill(0, count(self::STARTED) + count(self::CHANGED), '?'));
    }
}
