<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Ai\AiSettings;
use CW\Ai\Claude;
use CW\Ai\DocumentReader;
use CW\Db;

/**
 * "Claude reads the upload" in the tests (CW-1182): its settings switched on with a MADE-UP key (never a real one: the fake HTTP client
 * answers, the network is never reached), small made-up documents of each type, and the reader wired to a FakeClaude.
 */
final class AiFixtures
{
    /** A key of the right form; it opens nothing. */
    public const KEY = 'sk-ant-api03-MADEUPKEYFORTESTSONLY0000000000000000';

    /** @param array<string, string> $over setting key => JSON text */
    public static function on(Db $db, array $over = []): void
    {
        $values = $over + [AiSettings::ON => 'true', AiSettings::KEY => json_encode(self::KEY, JSON_THROW_ON_ERROR)];
        foreach ($values as $k => $json) {
            $db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $k]);
        }
    }

    /** The reader with the fake client (no retries) and, optionally, a clock. */
    public static function reader(Db $db, string $spool, FakeClaude $fake, ?\Closure $clock = null): DocumentReader
    {
        return new DocumentReader($db, $spool, static fn (AiSettings $s): Claude => new Claude($s->key, $s->model, $s->effort, $fake, 0), $clock);
    }

    /** A tiny PDF of one page (what finfo calls application/pdf), with $text in it so two files differ. */
    public static function pdf(string $dir, string $text = 'made up', string $name = 'doc.pdf'): string
    {
        $body = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n2 0 obj << /Type /Page >> endobj\n% {$text}\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        file_put_contents($dir . '/' . $name, $body);
        return $dir . '/' . $name;
    }

    /** A 1x1 PNG. */
    public static function png(string $dir, string $name = 'photo.png'): string
    {
        file_put_contents($dir . '/' . $name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));
        return $dir . '/' . $name;
    }

    /** A 1x1 GIF (a photo Claude reads, but not a file an expense keeps). */
    public static function gif(string $dir, string $name = 'photo.gif'): string
    {
        file_put_contents($dir . '/' . $name, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
        return $dir . '/' . $name;
    }

    /** The first bytes of an iPhone's HEIC photo. */
    public static function heic(string $dir, string $name = 'photo.heic'): string
    {
        file_put_contents($dir . '/' . $name, "\0\0\0\x18ftypheic\0\0\0\0mif1heic" . str_repeat("\0", 64));
        return $dir . '/' . $name;
    }

    /** A scratch folder (0700), removed by the caller. */
    public static function dir(string $what): string
    {
        $d = sys_get_temp_dir() . '/cw_ai_' . $what . '_' . bin2hex(random_bytes(5));
        mkdir($d, 0700, true);
        return $d;
    }
}
