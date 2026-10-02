<?php

declare(strict_types=1);

namespace CW\Api;

use CW\Api\Controller\AvailabilityController;
use CW\Api\Controller\ChangesController;
use CW\Api\Controller\HealthController;
use CW\Api\Controller\HeartbeatController;
use CW\Api\Controller\ListingsController;
use CW\Api\Controller\MovementsController;
use CW\Api\Controller\OpeningOrdersController;
use CW\Api\Controller\PurchasingController;
use CW\Api\Controller\ReservationsController;
use CW\Api\Controller\SnapshotController;
use CW\Config;
use CW\ConfigException;
use CW\CwException;
use CW\Db;

/**
 * The /v1 API (plan §3, §11): one request in, one JSON envelope out.
 *
 *   1. a well-formed Bearer token, else 401 (no database work for junk traffic)
 *   2. connect as the app login (cw_app; the web process reads app.env only, never db.env), then
 *      authenticate: the key's hash AND the channel's IP allowlist (Auth)     401 / 403
 *   3. route                                                                    404 / 405
 *   4. a stock-writing route while the channel is `off`                        409 channel_off
 *   5. every POST needs an Idempotency-Key                                      400
 *   6. the controller; domain outcomes come back as stored OpResults (replays included)
 *
 * Every answer to an authenticated caller (step 2 passed), errors and replays included, carries
 * `X-CW-Channel-Mode: off|shadow|live`: the channel's mode as this request read it, so the site
 * learns CW's mode from every call (plan §6.2, F1). An answer before authentication (401, 403, a 503
 * before the database answered) never carries it.
 *
 * Nothing that failed in steps 1-5, and no CwException from the core, is stored under the
 * Idempotency-Key, so the site may retry the same key. Unexpected failures answer
 * 500 "internal" (or 503 when the database is unavailable or busy) with a request id; the
 * details go to the error log only.
 */
final class Kernel
{
    /** The channel's mode, on every answer after authentication (A13). */
    public const MODE_HEADER = 'X-CW-Channel-Mode';

    /** MySQL errors that mean "try again shortly": deadlock after retries, lock wait timeout. */
    private const BUSY_CODES = [1205, 1213];
    /** Client-side / connection errors: the database is not reachable or refused the login. */
    private const UNAVAILABLE_CODES = [1040, 1044, 1045, 1049, 1203, 2002, 2003, 2005, 2006, 2013];

    private ?Router $router = null;

    /**
     * @param \Closure(): Db $connect
     * @param \Closure(string): void $log
     */
    public function __construct(private readonly \Closure $connect, private readonly \Closure $log)
    {
    }

    /** The web front controller's kernel: app.env only, CW_* overrides from the process env. */
    public static function fromEnvironment(): self
    {
        return new self(
            static fn (): Db => Db::connect(Config::loadApp()->dbApp()),
            static function (string $message): void {
                error_log('[cw-api] ' . $message);
            },
        );
    }

    public function handle(Request $req): Response
    {
        $rid = bin2hex(random_bytes(8));
        $channel = null;
        try {
            $response = $this->dispatch($req, $rid, $channel);
        } catch (CwException $e) {
            $response = Response::fromException($e);
            if ($e->errorCode === 'method_not_allowed' && is_array($e->detail['allow'] ?? null)) {
                $response->withHeader('Allow', implode(', ', $e->detail['allow']));
            }
            if ($e->httpStatus === 401) {
                $response->withHeader('WWW-Authenticate', 'Bearer');
            }
        } catch (\Throwable $e) {
            $response = $this->failure($e, $req, $rid);
        }
        if ($channel !== null) {
            $response->withHeader(self::MODE_HEADER, $channel->mode);
        }
        return $response->withHeader('X-Request-Id', $rid);
    }

    /** @param-out ?ApiChannel $channel the authenticated channel, set as soon as Auth accepted the caller */
    private function dispatch(Request $req, string $rid, ?ApiChannel &$channel): Response
    {
        Auth::bearer($req, $this->log); // 401 before any database work
        try {
            $db = ($this->connect)();
        } catch (ConfigException | \PDOException $e) {
            ($this->log)("{$rid} database unavailable: " . self::describe($e));
            return Response::error(503, 'unavailable', 'the service is temporarily unavailable; retry later')
                ->withHeader('Retry-After', '5');
        }
        $channel = (new Auth($db, $this->log))->authenticate($req);
        [$route, $params] = $this->router()->match($req->method, $req->path);
        if ($route->stockWrite && $channel->mode === 'off') {
            throw new CwException('channel_off', 'this site is switched off in CW; stock calls start in shadow mode', 409);
        }
        $ctx = new Context($req, $channel, $db, $params);
        if ($req->method === 'POST') {
            $ctx->idemKey();
        }
        return ($route->handler)($ctx);
    }

    public function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }
        $res = new ReservationsController();
        $r = new Router();
        $r->add('POST', '/v1/reservations', $res->reserve(...), true);
        $r->add('POST', '/v1/reservations/{ref}/commit', $res->commit(...), true);
        $r->add('POST', '/v1/reservations/{ref}/release', $res->release(...), true);
        $r->add('POST', '/v1/reservations/{ref}/cancel', $res->cancel(...), true);
        $r->add('POST', '/v1/reservations/{ref}/uncancel', $res->uncancel(...), true);
        $r->add('POST', '/v1/reservations/{ref}/ship', $res->ship(...), true);
        $r->add('POST', '/v1/reservations/{ref}/unship', $res->unship(...), true);
        $r->add('POST', '/v1/reservations/{ref}/return', $res->returnUnits(...), true);
        $r->add('POST', '/v1/opening_orders', (new OpeningOrdersController())->create(...), true);
        $r->add('POST', '/v1/movements', (new MovementsController())->create(...), true);
        $r->add('GET', '/v1/changes', (new ChangesController())->index(...));
        $r->add('GET', '/v1/availability', (new AvailabilityController())->index(...));
        $r->add('GET', '/v1/snapshot', (new SnapshotController())->index(...));
        $r->add('PUT', '/v1/listings', (new ListingsController())->put(...));
        $r->add('POST', '/v1/heartbeat', (new HeartbeatController())->create(...));
        $r->add('GET', '/v1/purchasing', (new PurchasingController())->index(...));
        $r->add('GET', '/v1/health', (new HealthController())->show(...));
        return $this->router = $r;
    }

    private function failure(\Throwable $e, Request $req, string $rid): Response
    {
        ($this->log)("{$rid} {$req->method} {$req->path} failed: " . self::describe($e));
        $code = Db::driverCode($e) ?? ($e->getPrevious() !== null ? Db::driverCode($e->getPrevious()) : null);
        if ($code !== null && in_array($code, self::BUSY_CODES, true)) {
            return Response::error(503, 'busy', 'CW is busy; retry the same request shortly')->withHeader('Retry-After', '1');
        }
        if ($code !== null && in_array($code, self::UNAVAILABLE_CODES, true)) {
            return Response::error(503, 'unavailable', 'the service is temporarily unavailable; retry later')->withHeader('Retry-After', '5');
        }
        return Response::error(500, 'internal', 'internal error (request ' . $rid . ')');
    }

    /** Class + message + first frame, for the error log (never sent to the client). */
    private static function describe(\Throwable $e): string
    {
        $s = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        return $e->getPrevious() !== null ? $s . ' <- ' . self::describe($e->getPrevious()) : $s;
    }
}
