<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Queues\Corrections;
use CW\Queues\NightlyConfirm;
use CW\Settings;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The nightly Confirm all (the owner, 1 Nov 2026: "Make it automatic"; docs/decisions.md NC1-NC12), pure: the night's window is UK time,
 * [the time it runs, an hour later), across the clock changes of 2026 (29 Mar, 25 Oct) and midnight; the settings' rules and words as the
 * board writes them.
 */
final class NightlyConfirmUnitTest extends TestCase
{
    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function testTheWindowIsAnHourFromTheTimeItRunsInUkTime(): void
    {
        // Summer time (BST, UTC+1): 04:30 UK is 03:30 UTC.
        self::assertSame('2026-10-24', NightlyConfirm::night(self::at('2026-10-24 03:30:00'), '04:30'));
        self::assertSame('2026-10-24', NightlyConfirm::night(self::at('2026-10-24 04:29:59'), '04:30'));
        self::assertNull(NightlyConfirm::night(self::at('2026-10-24 04:30:00'), '04:30'), 'an hour later: the window has closed');
        self::assertNull(NightlyConfirm::night(self::at('2026-10-24 03:29:59'), '04:30'), 'a second before');
        // 25 Oct 2026, the clocks go back at 02:00 BST: 04:30 UK is now 04:30 UTC. A fixed summer offset would open the window at 03:30.
        self::assertNull(NightlyConfirm::night(self::at('2026-10-25 03:30:00'), '04:30'), '03:30 GMT is not 04:30 UK');
        self::assertSame('2026-10-25', NightlyConfirm::night(self::at('2026-10-25 04:30:00'), '04:30'));
        self::assertSame('2026-10-25', NightlyConfirm::night(self::at('2026-10-25 05:29:00'), '04:30'));
        self::assertNull(NightlyConfirm::night(self::at('2026-10-25 05:30:00'), '04:30'));
        // The hour that happens twice (01:00-02:00 UK on 25 Oct): one window that night, not two.
        $twice = array_filter(['2026-10-25 00:45:00', '2026-10-25 01:45:00'], static fn (string $t): bool => NightlyConfirm::night(self::at($t), '01:30') !== null);
        self::assertCount(1, $twice, 'one of the two 01:45s is in the window');
        // 29 Mar 2026, the clocks go forward at 01:00 GMT: 01:30 UK does not happen; the window still opens once that night.
        $spring = array_filter(['2026-03-29 00:45:00', '2026-03-29 01:15:00', '2026-03-29 01:45:00', '2026-03-29 02:15:00'],
            static fn (string $t): bool => NightlyConfirm::night(self::at($t), '01:30') === '2026-03-29');
        self::assertNotSame([], $spring);
        // A time near midnight: the window crosses it and belongs to the night it started (its UK date).
        self::assertSame('2026-10-31', NightlyConfirm::night(self::at('2026-11-01 00:10:00'), '23:30'));
        self::assertSame('2026-10-31', NightlyConfirm::night(self::at('2026-10-31 23:30:00'), '23:30'));
        self::assertNull(NightlyConfirm::night(self::at('2026-11-01 00:30:00'), '23:30'));
        self::assertSame('2026-11-01', NightlyConfirm::night(self::at('2026-11-01 00:00:00'), '00:00'));
        // Not a time: never inside.
        foreach (['4:30', '24:00', '04:60', '', '04:30:00', 'abc'] as $bad) {
            self::assertNull(NightlyConfirm::night(self::at('2026-10-25 04:40:00'), $bad), $bad);
        }
    }

    public function testTheSettingsRulesAndWordsAreTheBoards(): void
    {
        self::assertSame(['relay.nightly_confirm', 'relay.nightly_confirm_at', 'relay.nightly_confirm_max'], [NightlyConfirm::SWITCH, NightlyConfirm::AT, NightlyConfirm::MAX]);
        $rule = Settings::RULES[NightlyConfirm::AT];
        foreach (['00:00', '04:30', '23:59', '12:05'] as $ok) {
            self::assertSame(1, preg_match($rule['pattern'], $ok), $ok);
        }
        foreach (['24:00', '4:30', '04:5', '04:60', '04:30:00', ' 04:30', '04.30'] as $bad) {
            self::assertSame(0, preg_match($rule['pattern'], $bad), $bad);
        }
        self::assertSame(['min' => 1, 'max' => 5000], Settings::RULES[NightlyConfirm::MAX]);
        // The card "Messages from ERPNext", in the board's order, its words.
        $names = ['Confirm ready ERPNext corrections every night', 'Time it runs', 'Big change: leave for a person above', 'At most per night'];
        $keys = [NightlyConfirm::SWITCH, NightlyConfirm::AT, Corrections::BIG_SETTING, NightlyConfirm::MAX];
        self::assertSame($names, array_map(static fn (string $k): string => Words::SETTING[$k], $keys));
        self::assertSame(['On: CW confirms the ready ones by itself, the same way as Confirm all ready. The doubtful ones (Needs a look) stay in Approvals for a person.',
            'UK time. Before the 05:10 morning report, after the shop\'s night jobs.',
            'A correction that moves an item by more than this waits for a person, at night and when pressed.',
            'If more are ready, the rest wait for the next night or for Confirm all ready.'], array_map(static fn (string $k): string => Words::SETTING_HELP[$k], $keys));
        $order = array_keys(Words::SETTING);
        $at = array_map(static fn (string $k): int|false => array_search($k, $order, true), $keys);
        self::assertSame($at, array_values(array_unique($at)));
        $sorted = $at;
        sort($sorted);
        self::assertSame($sorted, $at, 'the rows in the board\'s order');
        self::assertSame(Words::SETTING_TOPIC['relay'], Words::settingTopic(NightlyConfirm::SWITCH));
        self::assertSame(['On', 'Off'], [Words::settingBool(NightlyConfirm::SWITCH, true), Words::settingBool(NightlyConfirm::SWITCH, false)], 'the switch reads On / Off');
        self::assertSame([Words::SETTINGS_PAGE['yes'], Words::SETTINGS_PAGE['no']], [Words::settingBool('relay.correction_is_for_sale', true),
            Words::settingBool('relay.correction_is_for_sale', false)], 'the other switches keep Yes / No');
        // The Last night line.
        self::assertSame('Last night, %s:', Words::NIGHTLY['last']);
        self::assertSame('%s ERPNext corrections confirmed, %s left for you in Approvals.', Words::NIGHTLY['counts']);
        self::assertSame('Open last night\'s run ›', Words::NIGHTLY['open']);
        self::assertSame('Next run: tonight at %s. It confirms only the ready ones, exactly like pressing Confirm all ready; the doubtful ones wait for a person.',
            Words::NIGHTLY['next']);
        // Its window and its budget: an hour; a few minutes for the press (no web page waits for it), under the five-minute cron.
        self::assertSame(60, NightlyConfirm::WINDOW_MINUTES);
        self::assertLessThan(300, NightlyConfirm::SECONDS);
        self::assertSame('confirm_nightly', Corrections::NIGHTLY_JOB);
    }

    /** NC6: ONE cron line on each server, every five minutes, in the same words; the installers never run it as a smoke check (it books). */
    public function testTheJobHasOneCronLineOnEachServer(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['deploy/staging/cw-staging.cron' => ['cw-staging', 'cw_staging'], 'deploy/production/cw-prod.cron' => ['cw-prod', 'cw_prod']] as $file => [$dir, $schema]) {
            $cron = (string) file_get_contents("{$root}/{$file}");
            self::assertMatchesRegularExpression('#^\*/5 \* \* \* \*  root  cd /opt/' . $dir . ' && php bin/confirm_corrections_nightly\.php --db=' . $schema
                . ' >> /var/log/cw/confirm_corrections_nightly\.log 2>&1$#m', $cron, $file);
            self::assertSame(1, preg_match_all('#^[^\#\n]*confirm_corrections_nightly\.php#m', $cron), "{$file}: one line");
        }
        foreach (['deploy/staging/install_cron.sh', 'deploy/production/install_cron.sh'] as $file) {
            self::assertStringNotContainsString('confirm_corrections_nightly', (string) file_get_contents("{$root}/{$file}"), "{$file}: no smoke run of a job that books");
        }
        self::assertFileExists("{$root}/bin/confirm_corrections_nightly.php");
    }
}
