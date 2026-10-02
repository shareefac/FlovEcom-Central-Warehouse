<?php

declare(strict_types=1);

namespace CW\Tests\Support\Documents;

/**
 * Runs document-base jobs in parallel tests/Support/doc_worker.php processes, each with its own DB connection, all
 * starting at the same moment (the WorkerPool of the stock tests, for the document ops). Bounded: the staging cluster
 * allows 76 connections in total, shared by every slot (docs/dev.md).
 */
final class DocWorkerPool
{
    public const MAX_WORKERS = 12;

    /**
     * @param list<list<array<string, mixed>>> $jobs one list of ops per worker
     * @return array{results: list<list<array<string, mixed>>>, deadlocks: int} results per worker, in job order
     */
    public static function run(array $jobs, float $startIn = 1.5): array
    {
        if (count($jobs) > self::MAX_WORKERS) {
            throw new \InvalidArgumentException('at most ' . self::MAX_WORKERS . ' workers');
        }
        $start = microtime(true) + $startIn;
        $procs = [];
        foreach ($jobs as $i => $ops) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/doc_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($p)) {
                throw new \RuntimeException("cannot start doc worker {$i}");
            }
            fwrite($pipes[0], json_encode(['start' => $start, 'ops' => $ops], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $procs[] = [$p, $pipes];
        }
        $results = [];
        $deadlocks = 0;
        foreach ($procs as $i => [$p, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($p);
            $line = json_decode(trim($stdout), true);
            if ($code !== 0 || !is_array($line)) {
                throw new \RuntimeException("doc worker {$i} failed (exit {$code}): " . substr($stderr . $stdout, 0, 2000));
            }
            $results[] = $line['results'];
            $deadlocks += (int) $line['deadlocks'];
        }
        return ['results' => $results, 'deadlocks' => $deadlocks];
    }
}
