<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Schema\Migrator;
use CW\Schema\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnSemicolonsOutsideQuotesAndComments(): void
    {
        $sql = <<<'SQL'
            -- leading comment; ignored
            CREATE TABLE a (id INT COMMENT 'x;y', note VARCHAR(10) DEFAULT 'it''s');
            # hash comment; ignored
            INSERT INTO a VALUES (1, "q\";uote");  /* block ; comment */
            SELECT `we;ird` FROM a;;
            SQL;
        self::assertSame([
            "CREATE TABLE a (id INT COMMENT 'x;y', note VARCHAR(10) DEFAULT 'it''s')",
            'INSERT INTO a VALUES (1, "q\";uote")',
            'SELECT `we;ird` FROM a',
        ], SqlSplitter::split($sql));
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        self::assertSame(['SELECT 1--1'], SqlSplitter::split('SELECT 1--1'));
    }

    public function testUnterminatedStringIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlSplitter::split("SELECT 'oops;");
    }

    public function testCoreMigrationSplitsIntoCreatesAndOneSeedInsert(): void
    {
        $sql = (string) file_get_contents(Migrator::defaultDir() . '/0001_core.sql');
        $statements = SqlSplitter::split($sql);
        $creates = array_values(array_filter($statements, static fn (string $s) => str_starts_with($s, 'CREATE TABLE ')));
        $inserts = array_values(array_filter($statements, static fn (string $s) => str_starts_with($s, 'INSERT INTO warehouse')));
        self::assertCount(21, $creates);
        self::assertCount(1, $inserts);
        self::assertCount(22, $statements);
    }

    public function testChecksumIgnoresLineEndingStyle(): void
    {
        self::assertSame(Migrator::checksum("a\nb\n"), Migrator::checksum("a\r\nb\r\n"));
    }
}
