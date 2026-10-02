<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Files;

use CW\Caller;
use CW\Config;
use CW\CwException;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Output\PdfWriter;
use CW\Tests\Support\IntegrationTestCase;

/**
 * I23, the document store: the type is sniffed from the bytes (finfo) and must be on the allow-list, sizes are capped,
 * the same content is kept once, every file is kept at least 7 years, every store is audited, reads are re-hashed
 * (a changed file is never served), files are attached to documents for good, and an unconfigured server answers 503.
 */
final class FileStoreTest extends IntegrationTestCase
{
    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $root;
    private string $src;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cw_fst_' . bin2hex(random_bytes(5));
        $this->src = $this->root . '.src';
        mkdir($this->root, 0700);
        mkdir($this->src, 0700);
        $this->log = [];
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->src] as $d) {
            if (is_dir($d) && str_starts_with(basename($d), 'cw_fst_')) {
                exec('rm -rf ' . escapeshellarg($d));
            }
        }
    }

    private function store(): FileStore
    {
        return new FileStore(self::$db, new LocalFileStorage($this->root), function (string $m): void {
            $this->log[] = $m;
        });
    }

    private function file(string $bytes, string $name = 'f'): string
    {
        $p = $this->src . '/' . bin2hex(random_bytes(3)) . '-' . $name;
        file_put_contents($p, $bytes);
        return $p;
    }

    private function stored(string $sha): string
    {
        return $this->root . '/' . substr($sha, 0, 2) . '/' . $sha;
    }

    public function testTheTypeIsSniffedFromTheBytes(): void
    {
        $fs = $this->store();
        $who = Caller::system('file_test');
        $pdf = (new PdfWriter('Duty stamp evidence'))->output();
        $r = $fs->store($who, $this->file($pdf, 'evidence.pdf'), 'evidence.pdf', 'duty_evidence', 'pallet 3');
        self::assertSame(['sha256' => hash('sha256', $pdf), 'size' => strlen($pdf), 'mime' => 'application/pdf', 'deduped' => false],
            array_diff_key($r, ['id' => 1]));
        $png = (string) base64_decode(self::PNG, true);
        $p = $fs->store($who, $this->file($png, 'scan.pdf'), 'scan.pdf', 'photo', null);
        self::assertSame('image/png', $p['mime'], 'a .pdf name with PNG bytes is a PNG');
        self::assertSame('scan.pdf', self::$db->value('SELECT original_name FROM stored_file WHERE id = ?', [$p['id']]), 'the name is kept, not trusted');
        $c = $fs->store($who, $this->file("sku,qty\nCW-000001,4\nCW-000002,7\n", 'counts.csv'), 'counts.csv', 'other', null);
        self::assertContains($c['mime'], ['text/csv', 'text/plain']);

        foreach ([
            'an executable' => ["MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00" . str_repeat("\x00", 48) . 'This program cannot be run in DOS mode', 'invoice.pdf'],
            'HTML' => ["<!DOCTYPE html>\n<html><head><title>x</title></head><body><script>alert(1)</script></body></html>\n", 'invoice.csv'],
            'SVG' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'photo.png'],
        ] as $what => [$bytes, $name]) {
            try {
                $fs->store($who, $this->file($bytes, $name), $name, 'other', null);
                self::fail("stored {$what}");
            } catch (CwException $e) {
                self::assertSame(['type_not_allowed', 415], [$e->errorCode, $e->httpStatus], $what);
            }
        }
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'file.store'"));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'file.store' ORDER BY id LIMIT 1"), true);
        self::assertIsArray($audit);
        ksort($audit);
        self::assertSame(['deduped' => false, 'kind' => 'duty_evidence', 'mime' => 'application/pdf', 'name' => 'evidence.pdf', 'sha256' => hash('sha256', $pdf),
            'size' => strlen($pdf)], $audit);
    }

    public function testSizesKindsAndNames(): void
    {
        $fs = $this->store();
        $who = Caller::system('file_test');
        $refused = function (int $status, string $code, callable $fn): void {
            try {
                $fn();
                self::fail("expected {$status} {$code}");
            } catch (CwException $e) {
                self::assertSame([$code, $status], [$e->errorCode, $e->httpStatus], $e->getMessage());
            }
        };
        $refused(400, 'empty_file', fn () => $fs->store($who, $this->file(''), 'x.txt', 'other', null));
        $big = $this->src . '/big.pdf';
        $h = fopen($big, 'wb');
        self::assertIsResource($h);
        fwrite($h, '%PDF-1.4');
        ftruncate($h, FileStore::MAX_BYTES + 1);
        fclose($h);
        $refused(413, 'too_large', fn () => $fs->store($who, $big, 'big.pdf', 'other', null));
        $refused(400, 'bad_kind', fn () => $fs->store($who, $this->file('x'), 'x.txt', 'selfie', null));
        $refused(400, 'no_file', fn () => $fs->store($who, $this->src . '/nothing', 'x.txt', 'other', null));
        $refused(400, 'bad_field', fn () => $fs->store($who, $this->file('x'), 'x.txt', 'other', str_repeat('n', 256)));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));

        self::assertSame('passwd', FileStore::cleanName('../../etc/passwd'));
        self::assertSame('evil.pdf', FileStore::cleanName('C:\\Users\\x\\evil.pdf'));
        self::assertSame('abc.pdf', FileStore::cleanName("a\0b\nc.pdf"));
        self::assertSame('file', FileStore::cleanName(''));
        self::assertSame('file', FileStore::cleanName('..'));
        self::assertSame('Facture été.pdf', FileStore::cleanName('Facture été.pdf'));
        self::assertLessThanOrEqual(255, strlen(FileStore::cleanName(str_repeat('é', 300))));
        self::assertTrue(mb_check_encoding(FileStore::cleanName(str_repeat('é', 300)), 'UTF-8'), 'never a split character');
        // I36: Unicode format characters (bidi overrides and isolates, zero-width) never reach a name.
        self::assertSame('invoicefdp.bat', FileStore::cleanName("invoice\u{202E}fdp.bat"));
        self::assertSame('ab c.pdf', FileStore::cleanName("a\u{200B}b\u{2066} c\u{2069}.pdf\u{FEFF}"));
        // The download name carries the SNIFFED type's extension.
        self::assertSame('duty.hta.txt', FileStore::downloadName('duty.hta', 'text/plain'));
        self::assertSame('invoicefdp.bat.txt', FileStore::downloadName("invoice\u{202E}fdp.bat", 'text/plain'));
        self::assertSame('scan.PDF', FileStore::downloadName('scan.PDF', 'application/pdf'));
        self::assertSame('photo.jpeg', FileStore::downloadName('photo.jpeg', 'image/jpeg'));
        self::assertSame('photo.png.jpg', FileStore::downloadName('photo.png', 'image/jpeg'));
        self::assertSame('stock.xlsx', FileStore::downloadName('stock.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        self::assertSame('file.csv', FileStore::downloadName('', 'text/csv'));
    }

    /**
     * I36 (review finding): store() checks and keeps a private copy, so a source changed after the checks cannot slip
     * past them; an HTML or HTA payload behind 4 KB of text is kept as text and downloads as .txt.
     */
    public function testTheBytesCheckedAreTheBytesKept(): void
    {
        $fs = $this->store();
        $who = Caller::system('file_test');
        $payload = str_repeat("delivery note line\n", 250) . "<html><script>alert(1)</script></html>\n";
        $r = $fs->store($who, $this->file($payload), 'duty.hta', 'other', null);
        self::assertSame('text/plain', $r['mime']);
        self::assertSame('duty.hta', self::$db->value('SELECT original_name FROM stored_file WHERE id = ?', [$r['id']]));
        self::assertSame($payload, $fs->read($r['id'])['bytes']);
        // The same refusals as before, judged on the copy: an empty or HTML file is still refused, nothing is kept.
        foreach (['' => 'empty_file', "<!DOCTYPE html><html><body>x</body></html>\n" => 'type_not_allowed'] as $bytes => $code) {
            try {
                $fs->store($who, $this->file((string) $bytes), 'x.html', 'other', null);
                self::fail("stored {$code}");
            } catch (CwException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));
    }

    public function testTheSameContentIsKeptOnceForSevenYears(): void
    {
        $fs = $this->store();
        $staff = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('fs', 'FS', 'fs@test.invalid', 'x')");
        $a = $fs->store(Caller::staff($staff), $this->file('delivery note 42', 'dn.txt'), 'dn.txt', 'delivery_note', null);
        $b = $fs->store(Caller::system('file_test'), $this->file('delivery note 42', 'again.txt'), 'again.txt', 'other', null);
        self::assertSame([$a['id'], true, $a['sha256']], [$b['id'], $b['deduped'], $b['sha256']]);
        $row = self::$db->one('SELECT original_name, kind, stored_by, stored_actor, backend, storage_key, retain_until, DATE(created_at) AS day FROM stored_file');
        self::assertSame(['dn.txt', 'delivery_note', $staff, "staff:{$staff}", 'local', $a['sha256']],
            [$row['original_name'], $row['kind'], $row['stored_by'], $row['stored_actor'], $row['backend'], $row['storage_key']], 'the first store names the row');
        self::assertSame((new \DateTimeImmutable((string) $row['day']))->modify('+7 years')->format('Y-m-d'), $row['retain_until']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));
        self::assertSame([false, true], array_map(static fn (string $d): bool => (bool) json_decode($d, true)['deduped'],
            array_map('strval', self::$db->column("SELECT detail FROM audit_log WHERE action = 'file.store' ORDER BY id"))));

        // The storage lost the bytes (it never should): the next store of the same content puts them back.
        unlink($this->stored($a['sha256']));
        $c = $fs->store(Caller::system('file_test'), $this->file('delivery note 42'), 'x.txt', 'other', null);
        self::assertTrue($c['deduped']);
        self::assertSame('delivery note 42', file_get_contents($this->stored($a['sha256'])));
    }

    public function testReadsAreReHashedAndAChangedFileIsNeverServed(): void
    {
        $fs = $this->store();
        $r = $fs->store(Caller::system('file_test'), $this->file('supplier invoice 7'), 'inv.txt', 'supplier_invoice', null);
        $got = $fs->read($r['id']);
        self::assertSame('supplier invoice 7', $got['bytes']);
        self::assertSame($r['sha256'], $got['meta']['sha256']);
        $path = $this->stored($r['sha256']);
        chmod($path, 0640);
        file_put_contents($path, 'supplier invoice 8');
        try {
            $fs->read($r['id']);
            self::fail('served a changed file');
        } catch (CwException $e) {
            self::assertSame(['file_corrupt', 500], [$e->errorCode, $e->httpStatus]);
        }
        self::assertCount(1, $this->log);
        self::assertStringContainsString("file {$r['id']} ({$r['sha256']}) does not match its sha256", $this->log[0]);
        unlink($path);
        try {
            $fs->read($r['id']);
            self::fail('served a missing file');
        } catch (CwException $e) {
            self::assertSame(['file_missing', 500], [$e->errorCode, $e->httpStatus]);
        }
        try {
            $fs->read(999_999);
            self::fail('an unknown file');
        } catch (CwException $e) {
            self::assertSame(['unknown_file', 404], [$e->errorCode, $e->httpStatus]);
        }
    }

    public function testFilesAreAttachedToDocumentsForGood(): void
    {
        $fs = $this->store();
        $who = Caller::system('file_test');
        $f = $fs->store($who, $this->file('photo of the pallet'), 'pallet.txt', 'photo', null);
        $draft = self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('ADJ', 'staff:1')");
        $cancelled = self::$db->insert("INSERT INTO document (doc_type, status, created_actor, cancelled_at) VALUES ('ADJ', 'cancelled', 'staff:1', NOW(6))");
        self::assertTrue($fs->attach($who, $draft, $f['id'], 'photo'));
        self::assertFalse($fs->attach($who, $draft, $f['id'], 'photo'), 'already attached');
        self::assertTrue($fs->attach($who, $draft, $f['id'], 'evidence'), 'the same file under another role');
        foreach ([
            [409, 'document_cancelled', fn () => $fs->attach($who, $cancelled, $f['id'], 'photo')],
            [404, 'unknown_document', fn () => $fs->attach($who, 999_999, $f['id'], 'photo')],
            [404, 'unknown_file', fn () => $fs->attach($who, $draft, 999_999, 'photo')],
            [400, 'bad_file_role', fn () => $fs->attach($who, $draft, $f['id'], 'selfie')],
        ] as [$status, $code, $fn]) {
            try {
                $fn();
                self::fail("expected {$code}");
            } catch (CwException $e) {
                self::assertSame([$code, $status], [$e->errorCode, $e->httpStatus]);
            }
        }
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM document_file'));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'document.attach'"));
        // I36: each attachment is kept 7 years from when it was made, whatever the file's first store.
        $until = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+7 years')->format('Y-m-d');
        self::assertSame([$until], array_values(array_unique(array_map('strval', self::$db->column('SELECT retain_until FROM document_file')))));
        self::assertSame($until, json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'document.attach' ORDER BY id LIMIT 1"), true)['retain_until']);
        // The bytes are gone (they never should be): attaching is refused, 500 file_missing, logged.
        unlink($this->stored($f['sha256']));
        try {
            $fs->attach($who, $draft, $f['id'], 'delivery_note');
            self::fail('attached a file whose bytes are missing');
        } catch (CwException $e) {
            self::assertSame(['file_missing', 500], [$e->errorCode, $e->httpStatus]);
        }
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM document_file'), 'rolled back');
    }

    public function testAnUnconfiguredServerAnswers503(): void
    {
        $db = ['user' => 'x', 'password' => 'y', 'host' => 'db.invalid'];
        foreach ([Config::fromArrays($db), Config::fromArrays($db, ['file_store_dir' => '/nonexistent/cw-docs']),
            Config::fromArrays($db, [], ['CW_FILE_STORE_DIR' => '/var/www/cw-docs'])] as $config) {
            try {
                FileStore::fromConfig($config, self::$db, static function (): void {
                });
                self::fail('a store without a usable directory');
            } catch (CwException $e) {
                self::assertSame(['file_store_unconfigured', 503], [$e->errorCode, $e->httpStatus]);
            }
        }
        $fs = FileStore::fromConfig(Config::fromArrays($db, ['file_store_dir' => '/nonexistent'], ['CW_FILE_STORE_DIR' => $this->root]), self::$db);
        self::assertSame('local', $fs->storage()->name(), 'CW_FILE_STORE_DIR wins over app.env');
    }
}
