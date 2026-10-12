<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ops\Cli;
use PHPUnit\Framework\TestCase;

/**
 * A command line read whole or not at all (Ops\Cli::unreadable, docs/decisions.md M64): getopt() drops a word it does not know and
 * goes on, reads `--apply=false` as `--apply`, and stops reading at the first word that is no option. A tool that writes must
 * not guess (bin/check_arrivals.php asks for this check), so each of those is named here and refused there. Pure: no database.
 */
final class CliOptionsTest extends TestCase
{
    /** The options of bin/check_arrivals.php, with the three every job has. */
    private const OPTIONS = ['db:', 'admin', 'help', 'apply', 'channel:', 'backfill', 'limit:', 'max-seconds:', 'after-id:', 'list', 'listing:', 'score', 'run:'];

    public function testALineOfTheJobsOwnOptionsIsReadWhole(): void
    {
        foreach ([[], ['--apply'], ['--db=cw_staging', '--apply'], ['--apply', '--channel=electrofag', '--backfill', '--limit=10', '--max-seconds=600', '--after-id=0', '--list'],
            ['--listing=12'], ['--score', '--channel=electrofag', '--run=run3-sold'], ['--db=cw_test_x', '--admin', '--help'], ['--run=a=b']] as $line) {
            self::assertNull(Cli::unreadable($line, self::OPTIONS), implode(' ', $line));
        }
    }

    public function testAWordGetoptWouldDropOrMisreadIsNamed(): void
    {
        $cases = [
            // a value on a flag: getopt() reads each of these as the flag itself
            [['--apply=false'], '--apply takes no value'],
            [['--apply=0', '--channel=electrofag'], '--apply takes no value'],
            [['--apply='], '--apply takes no value'],
            [['--apply', '--channel=electrofag', '--backfill=0'], '--backfill takes no value'],
            // an option the job does not have (a misspelt one): getopt() drops it and runs without it
            [['--apply', '--chanel=electrofag'], 'unknown option --chanel'],
            [['--channel=electrofag', '--backfil', '--apply'], 'unknown option --backfil'],
            [['--dB=cw_test', '--apply'], 'not an option: --dB=cw_test'],
            [['--apply', '--max-second=5'], 'unknown option --max-second'],
            // no value, or an empty one (a shell variable that is not set)
            [['--apply', '--channel='], '--channel needs a value: --channel=<value>'],
            [['--limit=', '--apply'], '--limit needs a value: --limit=<value>'],
            [['--apply', '--limit'], '--limit needs a value: --limit=<value>'],
            // the value as a word of its own: written with "=" or not at all
            [['--limit', '5', '--apply'], '--limit needs a value: --limit=<value>'],
            // twice: getopt() gives a list, and the common --db then falls back to the default schema
            [['--db=x', '--db=y', '--apply'], '--db is given twice'],
            [['--apply', '--apply'], '--apply is given twice'],
            // a word that is no option: getopt() stops reading there
            [['--apply', 'x', '--channel=electrofag', '--backfill'], 'not an option: x'],
            [['apply'], 'not an option: apply'],
            [['-apply'], 'not an option: -apply'],
            [['--'], 'not an option: --'],
            [[''], 'not an option: '],
        ];
        foreach ($cases as [$line, $why]) {
            self::assertSame($why, Cli::unreadable($line, self::OPTIONS), implode(' ', $line));
        }
    }
}
