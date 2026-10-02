<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * One private scratch directory per judge chunk (run3 follow-up (c), docs/decisions.md M29).
 *
 * run3's 98 judges ran in parallel and all wrote their working files into the one session scratchpad, under the same
 * names ("view.txt" 38 times, "compact.txt" 14 times): a judge could overwrite, or read, another judge's notes on another
 * chunk. tools/first_match now gives every chunk its own directory, created empty with mode 0700 when the chunks are
 * built, named in the chunk file (`scratch_dir`) and in the orchestrator's chunk list, so a judge is told where its files
 * go and no two judges share one.
 *
 * The directories live outside the run folder and the private folder (answer keys and ref maps), and neither of those
 * may sit inside the scratch root.
 */
final class JudgeScratch
{
    public const DIR_MODE = 0700;

    /** What a chunk file tells its judge (the payload's `purpose`, next to `scratch_dir`). */
    public const INSTRUCTION = 'Your own scratch directory is scratch_dir: write any working file there and nowhere else '
        . '(other judges run at the same time, each with its own directory).';

    /** <parent of the run folder>/judge_scratch/<run name>, e.g. /root/cw_work/first_match/judge_scratch/run4. */
    public static function defaultRoot(string $runDir): string
    {
        $runDir = rtrim($runDir, '/');
        return dirname($runDir) . '/judge_scratch/' . basename($runDir);
    }

    /**
     * Creates <root>/<chunk> for every chunk, empty, mode 0700, and returns chunk => absolute directory. Refuses (throws
     * \RuntimeException, nothing created) a relative root, a root inside one of $forbidden or one that contains one, a chunk
     * name that is not a plain file name, a name given twice, or an existing directory that is not empty (a previous
     * attempt's files: remove them, or build into another root).
     *
     * @param list<string> $chunks chunk names ("run4_c001")
     * @param list<string> $forbidden folders a judge must not share (the run folder, the private folder)
     * @return array<string, string>
     */
    public static function prepare(string $root, array $chunks, array $forbidden): array
    {
        if (!str_starts_with($root, '/')) {
            throw new \RuntimeException("scratch root must be an absolute path: {$root}");
        }
        $seen = [];
        foreach ($chunks as $c) {
            if (!is_string($c) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$/', $c) !== 1) {
                throw new \RuntimeException('bad chunk name for a scratch directory: ' . json_encode($c));
            }
            if (isset($seen[$c])) {
                throw new \RuntimeException("chunk {$c} named twice: two judges would share one scratch directory");
            }
            $seen[$c] = true;
        }
        $rootReal = self::resolve($root);
        foreach ($forbidden as $f) {
            $fReal = self::resolve((string) $f);
            if (self::within($rootReal, $fReal) || self::within($fReal, $rootReal)) {
                throw new \RuntimeException("scratch root {$root} overlaps {$f}: judges' scratch must be apart from the run and private folders");
            }
        }
        $dirs = [];
        foreach ($chunks as $c) {
            $d = $rootReal . '/' . $c;
            if (file_exists($d) || is_link($d)) {
                if (!is_dir($d) || is_link($d)) {
                    throw new \RuntimeException("scratch path {$d} exists and is not a directory");
                }
                if (array_diff(scandir($d) ?: [], ['.', '..']) !== []) {
                    throw new \RuntimeException("scratch directory {$d} is not empty (a previous attempt's files): remove them or use another --scratch");
                }
            }
            $dirs[$c] = $d;
        }
        if (!is_dir($rootReal) && !@mkdir($rootReal, self::DIR_MODE, true) && !is_dir($rootReal)) {
            throw new \RuntimeException("cannot create scratch root {$rootReal}");
        }
        foreach ($dirs as $d) {
            if (!is_dir($d) && !@mkdir($d, self::DIR_MODE) && !is_dir($d)) {
                throw new \RuntimeException("cannot create scratch directory {$d}");
            }
            chmod($d, self::DIR_MODE);
        }
        return $dirs;
    }

    /** Absolute path with symlinks resolved as far as the path exists (the rest appended as given, "." and ".." refused). */
    private static function resolve(string $path): string
    {
        $path = '/' . trim($path, '/');
        $tail = [];
        $head = $path;
        while ($head !== '/' && realpath($head) === false) {
            array_unshift($tail, basename($head));
            $head = dirname($head);
        }
        foreach ($tail as $t) {
            if ($t === '.' || $t === '..') {
                throw new \RuntimeException("path {$path} holds . or ..");
            }
        }
        $base = rtrim((string) realpath($head), '/');
        return $tail === [] ? ($base === '' ? '/' : $base) : $base . '/' . implode('/', $tail);
    }

    private static function within(string $path, string $dir): bool
    {
        return $path === $dir || str_starts_with($path . '/', rtrim($dir, '/') . '/');
    }
}
