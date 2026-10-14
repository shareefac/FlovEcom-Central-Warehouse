<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Db;

/**
 * The one lock every opening tool takes for a real run (pack I, review of 14 Oct 2026, docs/decisions.md PI17): the stores' estimate and
 * its T0 rebase (bin/import_opening_estimate.php) and the ERPNext opening (bin/import_erp_opening.php). Each job also has its own job lock
 * (Cli::tryLock), but those are per job, so the two tools could plan the same item at once and both book it (an estimate of 8 and an
 * ERPNext figure of 11 made 19). Held from before the plan to the end of the booking; a dry run reads only and does not take it. A named
 * MySQL lock per schema (released with the connection, so a killed run frees it).
 */
final class OpeningLock
{
    public static function name(string $schema): string
    {
        $name = 'cw_opening:' . $schema;
        return strlen($name) > 64 ? 'cw_opening:' . sha1($schema) : $name;
    }

    /** Takes the lock without waiting: false when another opening run holds it. */
    public static function take(Db $db, string $schema): bool
    {
        return (int) $db->value('SELECT GET_LOCK(?, 0)', [self::name($schema)]) === 1;
    }

    public static function release(Db $db, string $schema): void
    {
        try {
            $db->value('SELECT RELEASE_LOCK(?)', [self::name($schema)]);
        } catch (\Throwable) {
            // the connection is gone, and the lock with it
        }
    }

    /** The refusal every tool prints. */
    public const BUSY = 'another opening run (the stores\' estimate, its T0 rebase or the ERPNext opening) holds the opening lock: nothing booked; '
        . 'run this again when it has finished';
}
