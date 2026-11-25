<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ai;

use CW\Ai\DocumentReader;
use CW\Clock;
use CW\Ops\AlertDispatch;
use CW\Ops\Alerts;
use CW\Settings;
use CW\Tests\Support\AiFixtures;
use CW\Tests\Support\IntegrationTestCase;
use CW\Ui\Words;

/**
 * CW-1182 (docs/decisions.md AI17): bin/health_alert.php says when Claude's reading is stuck, as it says the other problems: a document
 * waiting more than 10 minutes to be read (the reader is not running, or is stuck), or the reader's last run failed (it ended with an error,
 * or never ended: the next run finds it and records it). Quiet while reading is switched off (ai.read_uploads); back to normal once a run
 * ends well and nothing waits that long.
 */
final class AiReaderHealthTest extends IntegrationTestCase
{
    public function testAReadingThatWaitsOrAReaderThatFailedIsAnAlertWhileReadingIsOn(): void
    {
        $staff = self::$db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('ah1', 'ah1@example.com', 'Ah', 'x')");
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'expenses')", [$staff]);
        $alerts = new Alerts(self::$db, static fn (): float => 1.0);
        $ai = static fn (): array => array_values(array_filter($alerts->problems(new Settings(self::$db)), static fn (array $p): bool => str_starts_with((string) $p['kind'], 'ai_')));
        $job = self::$db->insert("INSERT INTO ai_read (staff_user_id, place, kind, context, media_type, file_bytes, file_sha256, state, created_at) VALUES "
            . "(?, 'expense_new', 'expense_invoice', '{}', 'application/pdf', 9, ?, 'waiting', ?)", [$staff, str_repeat('0', 64), Clock::db(Clock::now()->modify('-11 minutes'))]);
        $reader = new DocumentReader(self::$db, sys_get_temp_dir() . '/cw_ai_health_no_spool');
        self::assertFalse($reader->runBegins(), 'the first run: nothing before it');
        $reader->runEnds('PDOException');
        self::assertSame([], $ai(), 'switched off: quiet');

        AiFixtures::on(self::$db);
        $p = $ai();
        self::assertSame(['ai_waiting', 'ai_reader_failed'], array_column($p, 'key'));
        self::assertSame([null, null, null], [$p[0]['channel'], $p[0]['channel_id'], $p[0]['mode']], 'CW\'s own, as the others');
        self::assertSame([1, 10], [$p[0]['data']['count'], $p[0]['data']['limit']]);
        self::assertSame(Words::say('ALERT', 'ai_waiting', 1, 10, AlertDispatch::duration((int) $p[0]['data']['age'])), AlertDispatch::line($p[0]));
        self::assertGreaterThanOrEqual(660, (int) $p[0]['data']['age']);
        self::assertSame('PDOException', $p[1]['data']['error']);
        self::assertSame(Words::say('ALERT', 'ai_reader_failed', AlertDispatch::duration((int) $p[1]['data']['age']), 'PDOException'), AlertDispatch::line($p[1]));

        // a run that ends well clears the reader's alert; a document waiting 9 minutes is none
        self::assertFalse($reader->runBegins());
        $reader->runEnds(null);
        self::$db->exec('UPDATE ai_read SET created_at = ? WHERE id = ?', [Clock::db(Clock::now()->modify('-9 minutes')), $job]);
        self::assertSame([], $ai());

        // a run that never ended (its process died): the next run finds it; an alert until a run ends well
        $reader->runBegins();
        self::assertTrue($reader->runBegins(), 'the next run finds the last one did not end');
        $p = $ai();
        self::assertSame(['ai_reader_failed'], array_column($p, 'key'));
        self::assertSame('did not end', $p[0]['data']['error']);
        $reader->runEnds(null);
        self::assertSame([], $ai(), 'back to normal');
    }
}
