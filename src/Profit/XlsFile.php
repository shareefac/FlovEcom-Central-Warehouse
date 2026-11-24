<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;

/**
 * Reads the cells of the first worksheet of an old Excel file (BIFF8 inside an OLE2 compound file: the ".xls" Click & Drop gives for its
 * "Manifested Orders" report; docs/decisions.md DL48). Pure: no database, no file kept, stdlib only (OpenSpout, CW's reader, reads .xlsx,
 * not .xls).
 *
 * The compound file: its header, the DIFAT (in the header and in DIFAT sectors), the FAT, the directory, then the stream named "Workbook"
 * (or "Book"), from the FAT's sectors or, under 4,096 bytes, from the mini stream. The workbook stream's records: the shared strings (SST
 * and its CONTINUE records: a string's characters may go on in the next record, which then starts with its own compressed / wide flag; its
 * rich-text runs and extended block are skipped), then each worksheet between its BOF and EOF; only the FIRST worksheet's cells are read:
 * LABELSST, NUMBER, RK, MULRK, LABEL, FORMULA (its cached number, or its string from the STRING record after it), BOOLERR. Every other record
 * is skipped. Each cell goes to the caller's closure as it is met (row, column, value), so a caller keeps only the columns it wants.
 *
 * Every offset and length is checked: a file that is not such a file, cut short, or whose chains loop or point outside it is refused whole
 * (CwException bad_file, 422), never half read.
 */
final class XlsFile
{
    /** What an Excel error cell (#N/A, #VALUE!, ...) reads as: a value given that cannot be read, never a blank (no text cell holds a NUL byte). */
    public const ERROR_CELL = "\0#error";

    /** The largest file read. */
    public const MAX_BYTES = 32 * 1_048_576;
    /** The most rows a worksheet may have (BIFF8 itself stops at 65,536). */
    public const MAX_ROWS = 200_000;
    private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    /** Sector numbers from this one up are marks (end of chain, free, FAT, DIFAT), not sectors. */
    private const MARKS = 0xFFFFFFFA;
    private const END = 0xFFFFFFFE;

    /**
     * Every cell of the first worksheet, in the file's order.
     *
     * @param \Closure(int, int, string|float|int|bool|null): void $cell
     */
    public static function firstSheet(string $bytes, \Closure $cell): void
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw self::refuse();
        }
        self::biff(self::workbook($bytes), $cell);
    }

    /** The workbook stream out of the compound file. */
    private static function workbook(string $data): string
    {
        $len = strlen($data);
        if ($len < 512 || !str_starts_with($data, self::SIGNATURE)) {
            throw self::refuse();
        }
        $h = unpack('vminor/vmajor/vorder/vsecShift/vminiShift', $data, 24);
        $f = unpack('VnDir/VnFat/VdirStart/Vtx/Vcutoff/VminiStart/VnMini/VdifatStart/VnDifat', $data, 40);
        if ($h === false || $f === false || $h['order'] !== 0xFFFE || !in_array($h['secShift'], [9, 12], true) || $h['miniShift'] !== 6) {
            throw self::refuse();
        }
        $ssz = 1 << $h['secShift'];
        $nsec = intdiv($len - 1, $ssz); // sector s lies at (s + 1) * ssz: the header takes the first sector's room
        // the FAT and DIFAT sizes bound by the file itself before anything is expanded (the final review, F9): each FAT sector maps ssz / 4 sectors,
        // so a whole file needs at most nsec / (ssz / 4) + 1 of them, and each DIFAT sector names ssz / 4 - 1 FAT sectors
        $per = intdiv($ssz, 4);
        if ($f['nFat'] < 1 || $f['nFat'] > intdiv($nsec, $per) + 1 || $f['nDifat'] > intdiv(max(0, $f['nFat'] - 109), $per - 1) + 1) {
            throw self::refuse();
        }
        $sector = static function (int $s) use ($data, $ssz, $nsec): string {
            if ($s < 0 || $s >= $nsec) {
                throw self::refuse();
            }
            return str_pad(substr($data, ($s + 1) * $ssz, $ssz), $ssz, "\0");
        };
        // The FAT's sectors: the header's 109 entries, then the DIFAT sectors' (each ssz / 4 - 1 entries and the next DIFAT sector).
        $difat = array_values((array) unpack('V109', $data, 76));
        $next = $f['difatStart'];
        $seenDifat = [];
        for ($i = 0; $i < $f['nDifat'] && $next < self::MARKS; $i++) {
            if (isset($seenDifat[$next])) {
                throw self::refuse(); // a DIFAT sector named twice (a loop)
            }
            $seenDifat[$next] = true;
            $vals = array_values((array) unpack('V' . intdiv($ssz, 4), $sector($next)));
            $next = (int) array_pop($vals);
            array_push($difat, ...$vals);
        }
        $fatSectors = array_slice(array_values(array_filter($difat, static fn (int $v): bool => $v < self::MARKS)), 0, $f['nFat']);
        if (count($fatSectors) !== $f['nFat'] || count(array_unique($fatSectors)) !== count($fatSectors)) {
            throw self::refuse(); // too few, or one FAT sector named twice (F9)
        }
        $fat = [];
        foreach ($fatSectors as $s) {
            array_push($fat, ...array_values((array) unpack('V' . intdiv($ssz, 4), $sector($s))));
        }
        $chain = static function (int $start) use ($fat): array {
            $out = [];
            $seen = [];
            for ($s = $start; $s < self::MARKS; $s = $fat[$s]) {
                if (!isset($fat[$s]) || isset($seen[$s])) {
                    throw self::refuse();
                }
                $seen[$s] = true;
                $out[] = $s;
            }
            if ($s !== self::END) {
                throw self::refuse();
            }
            return $out;
        };
        $read = static function (int $start) use ($chain, $sector): string {
            $parts = [];
            foreach ($chain($start) as $s) {
                $parts[] = $sector($s);
            }
            return implode('', $parts);
        };
        $dir = $read($f['dirStart']);
        $root = null;
        $stream = null;
        for ($i = 0; $i + 128 <= strlen($dir); $i += 128) {
            $e = unpack('vnlen/Ctype', $dir, $i + 64);
            $at = unpack('Vstart/Vsize', $dir, $i + 116);
            if ($e === false || $at === false || $e['nlen'] > 64 || $e['nlen'] % 2 !== 0) {
                throw self::refuse();
            }
            $name = $e['nlen'] >= 2 ? (string) mb_convert_encoding(substr($dir, $i, $e['nlen'] - 2), 'UTF-8', 'UTF-16LE') : '';
            if ($e['type'] === 5 && $root === null) {
                $root = $at;
            } elseif ($e['type'] === 2 && $stream === null && ($name === 'Workbook' || $name === 'Book')) {
                $stream = $at;
            }
        }
        if ($root === null || $stream === null || $stream['size'] > $len) {
            throw self::refuse();
        }
        $size = $stream['size'];
        if ($size < $f['cutoff']) {
            // In the mini stream (64-byte sectors in the root entry's chain), followed through the mini FAT.
            // the mini FAT bound before it is expanded (the astra re-check of b581e70, F9): the root's mini stream no larger than the file, the
            // header's mini FAT sector count = its chain's length, enough of it for the mini stream's sectors, and only those entries read
            if ($root['size'] > $len || $f['miniStart'] >= self::MARKS || $f['nMini'] < 1 || $f['nMini'] > $nsec) {
                throw self::refuse();
            }
            $need = intdiv($root['size'] + 63, 64);
            $miniChain = $chain($f['miniStart']);
            $use = intdiv($need + $per - 1, $per);
            if (count($miniChain) !== $f['nMini'] || $use > count($miniChain) || $need < 1) {
                throw self::refuse();
            }
            $mini = substr($read($root['start']), 0, $root['size']);
            $minifat = array_values((array) unpack('V' . $need, implode('', array_map($sector, array_slice($miniChain, 0, $use)))));
            $parts = [];
            $seen = [];
            for ($s = $stream['start']; $s < self::MARKS; $s = $minifat[$s]) {
                if (!isset($minifat[$s]) || isset($seen[$s]) || ($s + 1) * 64 > strlen($mini)) {
                    throw self::refuse();
                }
                $seen[$s] = true;
                $parts[] = substr($mini, $s * 64, 64);
            }
            $out = implode('', $parts);
        } else {
            $out = $read($stream['start']);
        }
        if (strlen($out) < $size) {
            throw self::refuse();
        }
        return substr($out, 0, $size);
    }

    /**
     * The workbook's records: its shared strings, then the cells of its first worksheet.
     *
     * @param \Closure(int, int, string|float|int|bool|null): void $cell
     */
    private static function biff(string $wb, \Closure $cell): void
    {
        $len = strlen($wb);
        $pos = 0;
        $sst = [];
        $depth = 0;          // substreams open (a worksheet may hold a chart's BOF ... EOF)
        $globals = false;    // the workbook globals' BOF seen
        $inSheet = false;    // inside the first worksheet
        $pending = null;     // a FORMULA whose string result comes in the next STRING record
        $first = true;
        while ($pos + 4 <= $len) {
            $r = unpack('vid/vlen', $wb, $pos);
            if ($r === false || $pos + 4 + $r['len'] > $len) {
                throw self::refuse();
            }
            $id = $r['id'];
            $d = substr($wb, $pos + 4, $r['len']);
            $pos += 4 + $r['len'];
            if ($first) {
                // BIFF8 only: the globals' BOF of version 0x0600
                if ($id !== 0x0809 || strlen($d) < 4 || unpack('v', $d)[1] !== 0x0600 || unpack('v', $d, 2)[1] !== 0x0005) {
                    throw self::refuse();
                }
                $first = false;
            }
            if ($id === 0x0809) {
                if (strlen($d) < 4) {
                    throw self::refuse();
                }
                $dt = unpack('v', $d, 2)[1];
                if ($depth === 0 && $dt === 0x0005) {
                    $globals = true;
                } elseif ($depth === 0 && $dt === 0x0010 && $globals) {
                    $inSheet = true;
                }
                $depth++;
                continue;
            }
            if ($id === 0x000A) {
                $depth--;
                if ($depth < 0) {
                    throw self::refuse();
                }
                if ($inSheet && $depth === 0) {
                    // the first worksheet ends: nothing after it is read, but a second data worksheet refuses the file (only one is read and
                    // checked: the final review, F5)
                    self::noOtherSheet($wb, $pos);
                    return;
                }
                continue;
            }
            if ($id === 0x00FC && $depth === 1 && !$inSheet) {
                $parts = [$d];
                while ($pos + 4 <= $len && unpack('v', $wb, $pos)[1] === 0x003C) {
                    $c = unpack('vid/vlen', $wb, $pos);
                    if ($pos + 4 + $c['len'] > $len) {
                        throw self::refuse();
                    }
                    $parts[] = substr($wb, $pos + 4, $c['len']);
                    $pos += 4 + $c['len'];
                }
                $sst = self::sst($parts);
                continue;
            }
            if (!$inSheet || $depth !== 1) {
                continue;
            }
            if ($pending !== null && !in_array($id, [0x0207, 0x04BC, 0x0221, 0x0236], true)) {
                $pending = null; // a string formula with no STRING record after it (shared formula, array, table records may come between)
            }
            switch ($id) {
                case 0x00FD: // LABELSST
                    $v = self::need($d, 10, 'vrow/vcol/vxf/Vi');
                    if (!array_key_exists($v['i'], $sst)) {
                        throw self::refuse();
                    }
                    self::emit($cell, $v['row'], $v['col'], $sst[$v['i']]);
                    break;
                case 0x0203: // NUMBER
                    $v = self::need($d, 14, 'vrow/vcol/vxf/enum');
                    self::emit($cell, $v['row'], $v['col'], $v['num']);
                    break;
                case 0x027E: // RK
                    $v = self::need($d, 10, 'vrow/vcol/vxf/Vrk');
                    self::emit($cell, $v['row'], $v['col'], self::rk($v['rk']));
                    break;
                case 0x00BD: // MULRK: row, first column, (xf, rk) per cell, last column
                    $v = self::need($d, 6, 'vrow/vcol');
                    $n = intdiv(strlen($d) - 6, 6);
                    if ((strlen($d) - 6) % 6 !== 0 || $n < 1) {
                        throw self::refuse();
                    }
                    for ($k = 0; $k < $n; $k++) {
                        self::emit($cell, $v['row'], $v['col'] + $k, self::rk(unpack('V', $d, 4 + $k * 6 + 2)[1]));
                    }
                    break;
                case 0x0204: // LABEL (BIFF8: an XLUnicodeString)
                    $v = self::need($d, 9, 'vrow/vcol/vxf/vcch/Cflag');
                    self::emit($cell, $v['row'], $v['col'], self::plain($d, 9, $v['cch'], $v['flag']));
                    break;
                case 0x0006: // FORMULA: its cached result
                    $v = self::need($d, 20, 'vrow/vcol/vxf');
                    if (substr($d, 12, 2) === "\xFF\xFF") {
                        $kind = ord($d[6]);
                        if ($kind === 0) {
                            $pending = [$v['row'], $v['col']];
                        } elseif ($kind === 1) {
                            self::emit($cell, $v['row'], $v['col'], ord($d[8]) !== 0);
                        } elseif ($kind === 3) {
                            self::emit($cell, $v['row'], $v['col'], '');
                        } else {
                            self::emit($cell, $v['row'], $v['col'], self::ERROR_CELL); // an error: a value given that cannot be read (F6)
                        }
                    } else {
                        self::emit($cell, $v['row'], $v['col'], unpack('e', $d, 6)[1]);
                    }
                    break;
                case 0x0207: // STRING: the result of the FORMULA before it
                    if ($pending !== null) {
                        $v = self::need($d, 3, 'vcch/Cflag');
                        self::emit($cell, $pending[0], $pending[1], self::plain($d, 3, $v['cch'], $v['flag']));
                        $pending = null;
                    }
                    break;
                case 0x0205: // BOOLERR
                    $v = self::need($d, 8, 'vrow/vcol/vxf/Cval/Cerr');
                    // an error cell is a value given that cannot be read, never a blank cell (the astra re-check of b581e70, F6)
                    self::emit($cell, $v['row'], $v['col'], $v['err'] === 0 ? $v['val'] !== 0 : self::ERROR_CELL);
                    break;
            }
        }
        // no worksheet, or the first one has no EOF: the file is cut short
        throw self::refuse();
    }

    /** @param \Closure(int, int, string|float|int|bool|null): void $cell */
    private static function emit(\Closure $cell, int $row, int $col, string|float|int|bool|null $v): void
    {
        if ($row >= self::MAX_ROWS) {
            throw new CwException('bad_file', 'Click & Drop report not read: it has more than ' . self::MAX_ROWS . ' rows. Nothing was saved.', 422);
        }
        $cell($row, $col, $v);
    }

    /** @return array<string, mixed> */
    private static function need(string $d, int $bytes, string $format): array
    {
        if (strlen($d) < $bytes) {
            throw self::refuse();
        }
        $v = unpack($format, $d);
        if ($v === false) {
            throw self::refuse();
        }
        return $v;
    }

    /** A string of $cch characters at $at, compressed (Latin-1) or wide (UTF-16LE) by $flag's first bit. */
    private static function plain(string $d, int $at, int $cch, int $flag): string
    {
        $n = ($flag & 1) === 1 ? $cch * 2 : $cch;
        if ($at + $n > strlen($d)) {
            throw self::refuse();
        }
        $raw = substr($d, $at, $n);
        return (string) mb_convert_encoding($raw, 'UTF-8', ($flag & 1) === 1 ? 'UTF-16LE' : 'ISO-8859-1');
    }

    /** An RK number: an int (30 bits) or a float's top 30 bits, divided by 100 when its first bit says so. */
    private static function rk(int $v): int|float
    {
        if (($v & 2) === 2) {
            $x = $v >> 2;
            if (($x & (1 << 29)) !== 0) {
                $x -= 1 << 30;
            }
        } else {
            $x = (float) unpack('e', pack('VV', 0, $v & 0xFFFFFFFC))[1];
        }
        return ($v & 1) === 1 ? $x / 100 : $x;
    }

    /**
     * The shared strings: from the SST record and the CONTINUE records after it.
     *
     * @param list<string> $parts
     * @return list<string>
     */
    private static function sst(array $parts): array
    {
        $cur = $parts[0];
        if (strlen($cur) < 8) {
            throw self::refuse();
        }
        $total = unpack('V', $cur, 4)[1];
        $bytes = array_sum(array_map('strlen', $parts));
        if ($total > $bytes) {
            throw self::refuse(); // every string takes at least 3 bytes
        }
        $out = [];
        $pi = 0;
        $pos = 8;
        $nextPart = static function () use (&$pi, &$cur, &$pos, $parts): void {
            $pi++;
            if (!isset($parts[$pi])) {
                throw self::refuse();
            }
            $cur = $parts[$pi];
            $pos = 0;
        };
        $take = static function (int $n) use (&$cur, &$pos): string {
            if ($pos + $n > strlen($cur)) {
                throw self::refuse(); // a string's header is never split
            }
            $s = substr($cur, $pos, $n);
            $pos += $n;
            return $s;
        };
        while (count($out) < $total) {
            if ($pos >= strlen($cur)) {
                $nextPart();
            }
            $hd = unpack('vcch/Cflags', $take(3));
            $flags = $hd['flags'];
            $runs = ($flags & 8) !== 0 ? unpack('v', $take(2))[1] : 0;
            $ext = ($flags & 4) !== 0 ? unpack('V', $take(4))[1] : 0;
            $wide = ($flags & 1) === 1;
            $left = $hd['cch'];
            $chars = [];
            while ($left > 0) {
                if ($pos >= strlen($cur)) {
                    $nextPart();
                    $wide = (ord($take(1)) & 1) === 1; // the rest of the characters: its own flag
                }
                $per = $wide ? 2 : 1;
                $n = min($left, intdiv(strlen($cur) - $pos, $per));
                if ($n < 1) {
                    throw self::refuse();
                }
                $raw = $take($n * $per);
                $chars[] = (string) mb_convert_encoding($raw, 'UTF-8', $wide ? 'UTF-16LE' : 'ISO-8859-1');
                $left -= $n;
            }
            // the rich-text runs and the extended block: skipped (they may go on in the next record, with no flag)
            $skip = $runs * 4 + $ext;
            while ($skip > 0) {
                if ($pos >= strlen($cur)) {
                    $nextPart();
                }
                $t = min($skip, strlen($cur) - $pos);
                $pos += $t;
                $skip -= $t;
            }
            $out[] = implode('', $chars);
        }
        return $out;
    }

    /** The records after the first worksheet: another worksheet (BOF of type 0x0010 at the top level) refuses the workbook. */
    private static function noOtherSheet(string $wb, int $pos): void
    {
        $len = strlen($wb);
        $depth = 0;
        while ($pos + 4 <= $len) {
            $r = unpack('vid/vlen', $wb, $pos);
            if ($r === false || $pos + 4 + $r['len'] > $len) {
                throw self::refuse();
            }
            if ($r['id'] === 0x0809) {
                if ($r['len'] < 4) {
                    throw self::refuse();
                }
                if ($depth === 0 && unpack('v', $wb, $pos + 6)[1] === 0x0010) {
                    throw new CwException('bad_file', 'Click & Drop report not read: it has more than one worksheet (CW reads one only). Download the report again '
                        . 'from Click & Drop and upload that file as it is. Nothing was saved.', 422);
                }
                $depth++;
            } elseif ($r['id'] === 0x000A) {
                $depth = max(0, $depth - 1);
            }
            $pos += 4 + $r['len'];
        }
    }

    private static function refuse(): CwException
    {
        return new CwException('bad_file', 'Click & Drop report not read: it is not an Excel file CW can read (.xls). Nothing was saved.', 422);
    }
}
