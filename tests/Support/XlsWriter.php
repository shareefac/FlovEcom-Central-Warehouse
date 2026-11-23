<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * A minimal writer of old Excel files (BIFF8 inside an OLE2 compound file) for SYNTHETIC test files only (docs/decisions.md DL48: the
 * Click & Drop report reader is tested with made-up rows; a real report holds customer names and addresses and is never read by a test).
 *
 * One worksheet by default (a second one with 'second_sheet'). A cell is a string (through the shared string table, LABELSST), an int or
 * a float (NUMBER), a bool (BOOLERR) or null (no cell); or a marker to write it another way: ['rk' => n], ['mulrk' => [n, ...]] (from this
 * column on), ['label' => s] (LABEL), ['formula' => n|s|bool] (FORMULA with its cached result; a string one followed by STRING),
 * ['error' => code] (BOOLERR error).
 *
 * Options: 'max_record' (the most bytes of one SST / CONTINUE record, 8224 as Excel; smaller splits the strings more), 'switch_wide' (a
 * string split across a CONTINUE is written on in the other encoding when it can be), 'rich' (every string with a rich-text run and an
 * extended block), 'difat_sector' (the FAT sectors named in a DIFAT sector instead of the header), 'stream_name' ('Workbook'), 'chart_first'
 * (a chart substream before the worksheet), 'second_sheet' (rows of a second worksheet). A workbook stream under 4,096 bytes goes into
 * the mini stream, as the format says.
 */
final class XlsWriter
{
    private const SECTOR = 512;
    private const MINI = 64;
    private const CUTOFF = 4096;
    private const END = 0xFFFFFFFE;
    private const FREE = 0xFFFFFFFF;
    private const FATSECT = 0xFFFFFFFD;
    private const DIFSECT = 0xFFFFFFFC;

    /**
     * @param list<list<mixed>> $rows row => its cells by column
     * @param array<string, mixed> $opt
     */
    public static function file(array $rows, array $opt = []): string
    {
        return self::ole(self::workbook($rows, $opt), $opt);
    }

    /**
     * The BIFF8 workbook stream.
     *
     * @param list<list<mixed>> $rows
     * @param array<string, mixed> $opt
     */
    public static function workbook(array $rows, array $opt = []): string
    {
        $sheets = [$rows];
        if (isset($opt['second_sheet'])) {
            $sheets[] = $opt['second_sheet'];
        }
        $strings = [];
        $index = [];
        foreach ($sheets as $sheet) {
            foreach ($sheet as $cells) {
                foreach ($cells as $v) {
                    if (is_string($v) && !isset($index[$v])) {
                        $index[$v] = count($strings);
                        $strings[] = $v;
                    }
                }
            }
        }
        $globals = self::rec(0x0809, pack('vvvvVV', 0x0600, 0x0005, 0x0DBB, 0x07CC, 0, 6));
        $globals .= self::sst($strings, (int) ($opt['max_record'] ?? 8224), (bool) ($opt['switch_wide'] ?? false), (bool) ($opt['rich'] ?? false));
        $bodies = [];
        if ($opt['chart_first'] ?? false) {
            $bodies[] = ['Chart1', 0x0020, self::rec(0x0809, pack('vvvvVV', 0x0600, 0x0020, 0x0DBB, 0x07CC, 0, 6))
                . self::rec(0x0203, pack('vvv', 0, 0, 0) . pack('e', 99.0)) . self::rec(0x000A, '')];
        }
        foreach ($sheets as $i => $sheet) {
            $bodies[] = ['Sheet' . ($i + 1), 0x0010, self::sheet($sheet, $index)];
        }
        $boundLen = 0;
        foreach ($bodies as [$name]) {
            $boundLen += 4 + 6 + 2 + strlen($name);
        }
        $eof = self::rec(0x000A, '');
        $offset = strlen($globals) + $boundLen + strlen($eof);
        $bound = '';
        foreach ($bodies as [$name, $dt, $body]) {
            $bound .= self::rec(0x0085, pack('VCC', $offset, 0, $dt === 0x0020 ? 2 : 0) . pack('CC', strlen($name), 0) . $name);
            $offset += strlen($body);
        }
        $out = $globals . $bound . $eof;
        foreach ($bodies as [, , $body]) {
            $out .= $body;
        }
        return $out;
    }

    /** @param array<string, int> $index */
    private static function sheet(array $rows, array $index): string
    {
        $out = self::rec(0x0809, pack('vvvvVV', 0x0600, 0x0010, 0x0DBB, 0x07CC, 0, 6));
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $v) {
                $rc = pack('vvv', $r, $c, 15);
                if ($v === null) {
                    continue;
                }
                if (is_string($v)) {
                    $out .= self::rec(0x00FD, $rc . pack('V', $index[$v]));
                } elseif (is_bool($v)) {
                    $out .= self::rec(0x0205, $rc . pack('CC', $v ? 1 : 0, 0));
                } elseif (is_int($v) || is_float($v)) {
                    $out .= self::rec(0x0203, $rc . pack('e', (float) $v));
                } elseif (is_array($v) && array_key_exists('rk', $v)) {
                    $out .= self::rec(0x027E, $rc . pack('V', self::rk($v['rk'])));
                } elseif (is_array($v) && array_key_exists('mulrk', $v)) {
                    $body = pack('vv', $r, $c);
                    foreach ($v['mulrk'] as $n) {
                        $body .= pack('vV', 15, self::rk($n));
                    }
                    $out .= self::rec(0x00BD, $body . pack('v', $c + count($v['mulrk']) - 1));
                } elseif (is_array($v) && array_key_exists('label', $v)) {
                    [$flag, $chars] = self::chars((string) $v['label'], false);
                    $out .= self::rec(0x0204, $rc . pack('vC', mb_strlen((string) $v['label'], 'UTF-8'), $flag) . $chars);
                } elseif (is_array($v) && array_key_exists('formula', $v)) {
                    $f = $v['formula'];
                    $tail = pack('vVv', 0, 0, 3) . "\x1E\x01\x00"; // grbit, chn, cce = 3, ptgInt 1
                    if (is_string($f)) {
                        $out .= self::rec(0x0006, $rc . "\x00\x00\x00\x00\x00\x00\xFF\xFF" . $tail);
                        [$flag, $chars] = self::chars($f, false);
                        $out .= self::rec(0x0207, pack('vC', mb_strlen($f, 'UTF-8'), $flag) . $chars);
                    } elseif (is_bool($f)) {
                        $out .= self::rec(0x0006, $rc . "\x01\x00" . chr($f ? 1 : 0) . "\x00\x00\x00\xFF\xFF" . $tail);
                    } else {
                        $out .= self::rec(0x0006, $rc . pack('e', (float) $f) . $tail);
                    }
                } elseif (is_array($v) && array_key_exists('error', $v)) {
                    $out .= self::rec(0x0205, $rc . pack('CC', (int) $v['error'], 1));
                }
            }
        }
        return $out . self::rec(0x000A, '');
    }

    /** An RK value: an int that fits 30 bits as an int, a whole number of hundredths as int / 100, else the float's top 30 bits. */
    public static function rk(int|float $n): int
    {
        if (is_int($n) && $n >= -(1 << 29) && $n < (1 << 29)) {
            return (($n << 2) | 2) & 0xFFFFFFFF;
        }
        $h = (int) round($n * 100);
        if (abs($n * 100 - $h) < 1e-9 && $h >= -(1 << 29) && $h < (1 << 29)) {
            return (($h << 2) | 3) & 0xFFFFFFFF;
        }
        $hi = unpack('V2', pack('e', (float) $n));
        return $hi[2] & 0xFFFFFFFC;
    }

    /**
     * The SST and its CONTINUE records: a string's header never split; its characters split across a CONTINUE start again with a flag
     * byte (switch_wide: in the other encoding when the rest allows it); the rich runs and extended block split anywhere.
     *
     * @param list<string> $strings
     */
    private static function sst(array $strings, int $max, bool $switch, bool $rich): string
    {
        $records = [];
        $cur = pack('VV', count($strings), count($strings));
        foreach ($strings as $s) {
            $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $mustWide = static fn (array $cs): bool => preg_match('/[^\x{0}-\x{FF}]/u', implode('', $cs)) === 1;
            $enc = $mustWide($chars);
            $head = pack('vC', count($chars), ($enc ? 1 : 0) | ($rich ? 0x0C : 0)) . ($rich ? pack('vV', 1, 6) : '');
            if (strlen($cur) + strlen($head) > $max) {
                $records[] = $cur;
                $cur = '';
            }
            $cur .= $head;
            $left = $chars;
            while ($left !== []) {
                $room = intdiv($max - strlen($cur), $enc ? 2 : 1);
                if ($room > 0) {
                    $take = array_splice($left, 0, $room);
                    $cur .= self::chars(implode('', $take), $enc)[1];
                    if ($left === []) {
                        break;
                    }
                }
                // the rest of the string in a CONTINUE, which starts with its own encoding flag
                $records[] = $cur;
                $enc = $switch ? (!$enc || $mustWide($left)) : ($enc || $mustWide($left));
                $cur = chr($enc ? 1 : 0);
            }
            if ($rich) {
                $tail = pack('vv', 0, 0) . pack('vvv', 1, 2, 0); // one formatting run, then a 6-byte extended block
                foreach (str_split($tail) as $b) {
                    if (strlen($cur) >= $max) {
                        $records[] = $cur;
                        $cur = '';
                    }
                    $cur .= $b;
                }
            }
        }
        $records[] = $cur;
        $out = self::rec(0x00FC, array_shift($records));
        foreach ($records as $r) {
            $out .= self::rec(0x003C, $r);
        }
        return $out;
    }

    /** @return array{0: int, 1: string} the flag and the characters, compressed (Latin-1) or wide (UTF-16LE) */
    private static function chars(string $s, bool $wide): array
    {
        if (!$wide && preg_match('/[^\x00-\xFF]/u', $s) !== 1) {
            return [0, (string) mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8')];
        }
        return [1, (string) mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')];
    }

    private static function rec(int $id, string $data): string
    {
        return pack('vv', $id, strlen($data)) . $data;
    }

    /**
     * The OLE2 compound file holding the stream (version 3, 512-byte sectors): the stream (or the mini stream), the mini FAT, the directory,
     * the FAT (and a DIFAT sector when asked).
     *
     * @param array<string, mixed> $opt
     */
    public static function ole(string $stream, array $opt = []): string
    {
        $name = (string) ($opt['stream_name'] ?? 'Workbook');
        $mini = strlen($stream) < self::CUTOFF;
        $sectors = [];
        $fat = [];
        $chain = static function (string $data) use (&$sectors, &$fat): int {
            if ($data === '') {
                return self::END;
            }
            $start = count($sectors);
            foreach (str_split($data, self::SECTOR) as $i => $part) {
                $sectors[] = str_pad($part, self::SECTOR, "\0");
                $fat[] = self::END;
                if ($i > 0) {
                    $fat[$start + $i - 1] = $start + $i;
                }
            }
            return $start;
        };
        $rootStart = self::END;
        $rootSize = 0;
        $miniFatStart = self::END;
        $miniFatCount = 0;
        if ($mini) {
            $ministream = '';
            $minifat = [];
            foreach (str_split($stream, self::MINI) as $i => $part) {
                $ministream .= str_pad($part, self::MINI, "\0");
                $minifat[] = self::END;
                if ($i > 0) {
                    $minifat[$i - 1] = $i;
                }
            }
            $rootSize = strlen($ministream);
            $rootStart = $chain($ministream);
            $mf = '';
            foreach ($minifat as $v) {
                $mf .= pack('V', $v);
            }
            $miniFatCount = (int) ceil(strlen($mf) / self::SECTOR);
            $miniFatStart = $chain(str_pad($mf, $miniFatCount * self::SECTOR, pack('V', self::FREE)));
            $streamStart = 0;
        } else {
            $streamStart = $chain($stream);
        }
        $dir = self::entry('Root Entry', 5, 1, $rootStart, $rootSize) . self::entry($name, 2, -1, $streamStart, strlen($stream))
            . str_repeat(self::entry('', 0, -1, 0, 0), 2);
        $dirStart = $chain($dir);
        // The FAT covers every sector, its own included (and the DIFAT sector when asked).
        $difat = (bool) ($opt['difat_sector'] ?? false);
        $n = count($sectors);
        $fatCount = 1;
        while ($fatCount * (self::SECTOR / 4) < $n + $fatCount + ($difat ? 1 : 0)) {
            $fatCount++;
        }
        $fatStart = $n;
        for ($i = 0; $i < $fatCount; $i++) {
            $fat[$fatStart + $i] = self::FATSECT;
        }
        $difatAt = self::END;
        if ($difat) {
            $difatAt = $fatStart + $fatCount;
            $fat[$difatAt] = self::DIFSECT;
        }
        $total = $fatCount * (self::SECTOR / 4);
        $fatBytes = '';
        for ($i = 0; $i < $total; $i++) {
            $fatBytes .= pack('V', $fat[$i] ?? self::FREE);
        }
        foreach (str_split($fatBytes, self::SECTOR) as $part) {
            $sectors[] = $part;
        }
        $headerDifat = '';
        if ($difat) {
            $entries = '';
            for ($i = 0; $i < 127; $i++) {
                $entries .= pack('V', $i < $fatCount ? $fatStart + $i : self::FREE);
            }
            $sectors[] = $entries . pack('V', self::END);
            $headerDifat = str_repeat(pack('V', self::FREE), 109);
        } else {
            for ($i = 0; $i < 109; $i++) {
                $headerDifat .= pack('V', $i < $fatCount ? $fatStart + $i : self::FREE);
            }
        }
        $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16) . pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
            . pack('VVVVVVVVV', 0, $fatCount, $dirStart, 0, self::CUTOFF, $miniFatStart, $miniFatCount, $difat ? $difatAt : self::END, $difat ? 1 : 0)
            . $headerDifat;
        return $header . implode('', $sectors);
    }

    private static function entry(string $name, int $type, int $child, int $start, int $size): string
    {
        $n = $name === '' ? '' : (string) mb_convert_encoding($name . "\0", 'UTF-16LE', 'UTF-8');
        return str_pad($n, 64, "\0") . pack('vCC', strlen($n), $type, 1) . pack('VVV', self::FREE, self::FREE, $child < 0 ? self::FREE : $child)
            . str_repeat("\0", 16) . pack('V', 0) . str_repeat("\0", 16) . pack('VVV', $start, $size, 0);
    }
}
