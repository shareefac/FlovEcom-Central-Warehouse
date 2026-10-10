<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Csrf;
use CW\Auth\Permissions;
use CW\Auth\Sessions;
use CW\Config;
use CW\ConfigException;
use CW\CwException;
use CW\Db;
use CW\Ops\TestRefPurge;
use CW\Ui\Controller\AccessController;
use CW\Ui\Controller\ApprovalsController;
use CW\Ui\Controller\AuthController;
use CW\Ui\Controller\BarcodesController;
use CW\Ui\Controller\BulkController;
use CW\Ui\Controller\CompanyController;
use CW\Ui\Controller\DashboardController;
use CW\Ui\Controller\DocumentsController;
use CW\Ui\Controller\DuplicatesController;
use CW\Ui\Controller\FilesController;
use CW\Ui\Controller\IncidentsController;
use CW\Ui\Controller\ItemCardsController;
use CW\Ui\Controller\ItemController;
use CW\Ui\Controller\OtherAccountsController;
use CW\Ui\Controller\PeopleController;
use CW\Ui\Controller\PurchaseOrdersController;
use CW\Ui\Controller\ReasonsController;
use CW\Ui\Controller\ReceivingController;
use CW\Ui\Controller\ReferenceController;
use CW\Ui\Controller\ReorderController;
use CW\Ui\Controller\ReservationsController;
use CW\Ui\Controller\ReviewController;
use CW\Ui\Controller\ReviewsController;
use CW\Ui\Controller\SalesHistoryController;
use CW\Ui\Controller\SamplesController;
use CW\Ui\Controller\SearchController;
use CW\Ui\Controller\SellingModeController;
use CW\Ui\Controller\SettingsController;
use CW\Ui\Controller\StaffRequestsController;
use CW\Ui\Controller\StockController;
use CW\Ui\Controller\StockOpsController;
use CW\Ui\Controller\StoreProductsController;
use CW\Ui\Controller\SupplierItemsController;
use CW\Ui\Controller\SuppliersController;
use CW\Ui\Controller\SystemController;
use CW\Ui\Controller\WarehousesController;

/**
 * The /ui staff screens (plan §7.1, §11): one request in, one HTML page out.
 *
 *   1. route (404 / 405; no database work for a path that does not exist)
 *   2. connect as the app login (cw_app) and load `ui_secret_key` (CSRF); 503 page when either fails
 *   3. resolve the session cookie: live session (not revoked, MFA done, idle < 30 min, age < 12 h) or nobody
 *   4. access: public routes; otherwise sign-in (303 to /ui/login), forced password change,
 *      then the route's permission (Auth\Permissions::MAP, checked against the roles read for this
 *      request: a page a person cannot open is refused 403, not only left out of their menu, I11)
 *   5. every POST: same-origin check (Origin / Sec-Fetch-Site when sent) and the CSRF token
 *   6. the controller
 * Every answer carries the security headers (CSP `default-src 'self'`: no inline script or style,
 * no third-party anything; frame-ancestors 'none'; no-store). A download (a PDF, a CSV, a stored file:
 * Controller\FilesController::download) is an attachment and carries a second policy, `sandbox`, which the
 * browser enforces together with the first. Unexpected failures are a plain
 * 500 page with a request id (503 when the database is unavailable or busy); details go to the
 * error log only.
 */
final class Kernel
{
    public const CSP = "default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
    public const SESSION_COOKIE = 'cw_session';
    public const PRE_COOKIE = 'cw_pre';
    /** The step token of a sign-in being set up (Staff\Enrolment, /ui/new-code): only the browser that passed the first step has it. */
    public const SETUP_COOKIE = 'cw_setup';

    private const BUSY_CODES = [1205, 1213];
    private const UNAVAILABLE_CODES = [1040, 1044, 1045, 1049, 1203, 2002, 2003, 2005, 2006, 2013];

    private ?Router $router = null;
    private ?bool $isTestSystem = null;

    /**
     * @param \Closure(): Db $connect
     * @param \Closure(): ?string $secretKey base64 ui_secret_key
     * @param \Closure(string): void $log
     * @param (\Closure(Db): array<string, \CW\Documents\DocumentHandler>)|null $handlers the live document types
     *        (default CW\Documents\DocumentHandlers::all: PO since I-2; tests add the fixture ADJ type)
     * @param (\Closure(): bool)|null $testSystem whether this is a test system (app.env environment=staging): every page then
     *        carries the strip "TEST SYSTEM: nothing here is real"
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly \Closure $secretKey,
        private readonly \Closure $log,
        private readonly ?\Closure $handlers = null,
        private readonly ?\Closure $testSystem = null,
    ) {
    }

    /** The front controller's kernel: app.env only (like the API), CW_* overrides from the process env. */
    public static function fromEnvironment(): self
    {
        return new self(
            // A persistent link: the worker's next page skips the TCP + TLS + login handshake (Db's class comment says why it is safe).
            static fn (): Db => Db::connect(Config::loadApp()->dbApp(), true),
            static fn (): ?string => Config::loadApp()->get('ui_secret_key'),
            static function (string $message): void {
                error_log('[cw-ui] ' . $message);
            },
            null,
            static fn (): bool => strtolower(trim((string) Config::loadApp()->appFile(TestRefPurge::ENV_KEY))) === 'staging',
        );
    }

    /** Whether the pages carry the test-system strip (read once; a config that cannot be read says no). */
    private function testSystem(): bool
    {
        if ($this->isTestSystem === null) {
            try {
                $this->isTestSystem = $this->testSystem !== null && ($this->testSystem)() === true;
            } catch (\Throwable) {
                $this->isTestSystem = false;
            }
        }
        return $this->isTestSystem;
    }

    public function handle(UiRequest $req): HtmlResponse
    {
        $rid = bin2hex(random_bytes(8));
        try {
            $response = $this->dispatch($req, $rid);
        } catch (CwException $e) {
            $response = $this->bare($e->httpStatus, $e->errorCode, Words::error($e->errorCode, $e->getMessage()), $rid);
            if ($e->errorCode === 'method_not_allowed' && is_array($e->detail['allow'] ?? null)) {
                $response->withHeader('Allow', implode(', ', $e->detail['allow']));
            }
        } catch (\Throwable $e) {
            $response = $this->failure($e, $req, $rid);
        }
        return self::secure($response, $req->secure)->withHeader('X-Request-Id', $rid);
    }

    public static function secure(HtmlResponse $r, bool $https): HtmlResponse
    {
        $r->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            // Not 'no-referrer': under it a browser sends `Origin: null` on the page's own form posts (Fetch
            // spec), so checkOrigin() refused every sign-in from a real browser (U24).
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
        if ($r->header('Cache-Control') === null) {
            $r->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
        }
        if ($https) {
            $r->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        return $r;
    }

    private function dispatch(UiRequest $req, string $rid): HtmlResponse
    {
        [$route, $params] = $this->router()->match($req->method, $req->path);
        try {
            $db = ($this->connect)();
            $key = ($this->secretKey)();
            if ($key === null || $key === '') {
                ($this->log)("{$rid} ui_secret_key is not set in app.env (run bin/create_staff.php or deploy/staging/install_ui.sh)");
                return $this->bare(503, 'unavailable', Words::error('unconfigured'), $rid);
            }
            $csrf = Csrf::fromSecretKey($key);
        } catch (ConfigException | \PDOException $e) {
            ($this->log)("{$rid} unavailable: " . self::describe($e));
            return $this->bare(503, 'unavailable', Words::error('unavailable'), $rid)->withHeader('Retry-After', '5');
        }

        $who = (new Sessions($db))->resolve($req->cookie(self::SESSION_COOKIE));
        $pre = $req->cookie(self::PRE_COOKIE);
        $pre = $pre !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $pre) === 1 ? $pre : null;
        $ctx = new Context($req, $db, $who, $csrf, $params, $rid, $pre, $key, $this->handlers, $this->log, $this->testSystem());
        try {
            return $this->guarded($route, $ctx);
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, $e->getMessage());
        }
    }

    private function guarded(Route $route, Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $who = $ctx->who;
        if ($route->access === Route::PUBLIC) {
            if ($who !== null && $req->method !== 'POST') {
                // Signed in already (another tab): the sign-in page leads on to the page it was asked for (behaviour item 1).
                return HtmlResponse::redirect(($req->path === '/ui/login' ? AuthController::safeBack($req->param('back')) : null) ?? '/ui/');
            }
        } else {
            if ($who === null) {
                $cookie = $req->cookie(self::SESSION_COOKIE);
                // A cookie of a session that ended (30 minutes away, 12 hours old, signed out or switched off elsewhere): the
                // sign-in page says why (plan F056). A cookie nobody issued is the same as none.
                $id = $cookie === null ? null : Sessions::idOf($cookie);
                $ended = $id !== null && $ctx->db->value('SELECT 1 FROM staff_session WHERE id = ?', [$id]) !== null;
                $to = HtmlResponse::redirect(self::signInPath($req, $ended));
                return $cookie === null ? $to : $to->withoutCookie(self::SESSION_COOKIE, $req->secure);
            }
            if ($who->mustChangePassword && !in_array($req->path, ['/ui/password', '/ui/logout'], true)) {
                return HtmlResponse::redirect('/ui/password');
            }
            if ($route->access !== Route::ANY && !$who->can($route->access)) {
                throw new CwException($route->access === Route::LEAD ? 'lead_required' : 'role_not_allowed', self::refusal($route->access, $who, $req->method), 403);
            }
        }
        if ($req->method === 'POST') {
            // A body over the UI pool's post_max_size (2M) reaches PHP with no fields and no files at all: say so (413)
            // rather than "this form has expired" (the CSRF field was dropped with the rest).
            if ($req->post === [] && $req->files === [] && (int) ($req->header('content-length') ?? '0') > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', Words::error('too_large', '', intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)), 413);
            }
            // PHP drops the fields past max_input_vars without a word: a POST that arrives with that many may have lost
            // some (a typed charge, a line's packs), so nothing is done with it (I73).
            if (count($req->post) >= UiRequest::maxInputVars()) {
                throw new CwException('form_truncated', Words::error('form_truncated', '', UiRequest::maxInputVars()), 400);
            }
            $this->checkOrigin($req);
            $token = $req->field('csrf');
            // A public form (the sign-in) always carries the pre-login token, even when the browser holds a
            // session by now (a second tab): that POST replaces the session. Every other form is bound to the session.
            $ok = $route->access !== Route::PUBLIC && $who !== null
                ? $ctx->csrf->validForSession($who->sessionId, $token)
                : $ctx->csrf->validForPre($ctx->pre, $token);
            if (!$ok) {
                if ($route->access === Route::PUBLIC && $req->path === '/ui/login') {
                    // The sign-in form was open longer than its cookie lives (1 hour), or the cookie went: the form again, with the
                    // e-mail kept and a plain word, instead of an error page (behaviour item 3, F059). Still 403 and no sign-in attempt.
                    return (new AuthController())->expired($ctx);
                }
                throw new CwException('csrf', Words::error('csrf'), 403);
            }
        }
        return ($route->handler)($ctx);
    }

    /**
     * Where a request without a live session is sent (behaviour item 1, F056, F057): the sign-in page, saying why when a session
     * ended (`why=signed_out`) or when a form was sent that is now lost (`why=lost`: "What you sent was NOT saved"), and with the
     * page to return to after the sign-in (`back`): the page itself for a GET, the page the form was on for a POST (its
     * same-origin Referer). Only a safe local page path is carried (AuthController::safeBack); Home is the default anyway.
     */
    private static function signInPath(UiRequest $req, bool $ended): string
    {
        $query = [];
        if ($req->method === 'POST') {
            // Signing out without a session: there is nothing to lose.
            if ($req->path !== '/ui/logout') {
                $query['why'] = 'lost';
                $query['back'] = AuthController::safeBack(self::refererPath($req));
            }
        } else {
            if ($ended) {
                $query['why'] = 'signed_out';
            }
            $own = $req->query;
            unset($own['notice'], $own['prev']); // a "Done: …" of the last visit is not said again
            $qs = http_build_query($own, '', '&', PHP_QUERY_RFC3986);
            $query['back'] = AuthController::safeBack($req->path . ($qs === '' ? '' : '?' . $qs));
        }
        $query = array_filter($query, static fn (?string $v): bool => $v !== null);
        return '/ui/login' . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /** The path and query of the Referer when it is this site's own page, else null. */
    private static function refererPath(UiRequest $req): ?string
    {
        $ref = $req->header('referer');
        $host = $req->header('host');
        if ($ref === null || $host === null || strlen($ref) > 2048) {
            return null;
        }
        $p = parse_url($ref);
        if (!is_array($p) || !isset($p['host'], $p['path'])) {
            return null;
        }
        $given = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strcasecmp($given, $host) !== 0) {
            return null;
        }
        return $p['path'] . (isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '');
    }

    /** A browser that says where the POST came from must say "here". (Absent headers: the token and SameSite=Strict decide.) */
    private function checkOrigin(UiRequest $req): void
    {
        $site = $req->header('sec-fetch-site');
        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            throw new CwException('csrf', Words::error('cross_site'), 403);
        }
        $origin = $req->header('origin');
        if ($origin !== null) {
            $host = $req->header('host');
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);
            $given = is_string($originHost) ? $originHost . (is_int($originPort) ? ':' . $originPort : '') : null;
            if ($host === null || $given === null || strcasecmp($given, $host) !== 0) {
                throw new CwException('csrf', Words::error('cross_site'), 403);
            }
        }
    }

    /**
     * Why a page or a button is refused, in jobs (plan F037-F040): who it is for and what the person is, or, when it is Admin
     * alone that keeps it from them, that and the one fix (correction a: ask for Admin to be taken off, never a second account).
     */
    public static function refusal(string $access, \CW\Auth\StaffIdentity $who, string $method = 'GET'): string
    {
        $perm = $access; // Route::DECIDE and Route::LEAD are permissions too
        $look = $method === 'GET' || $method === 'HEAD';
        if (Permissions::blockedByAdmin($who->roles, $perm)) {
            $jobs = array_values(array_filter(Permissions::switchedOff($who->roles), static fn (string $r): bool => Permissions::can([$r], $perm)));
            return ($look ? 'You cannot open this page' : 'You cannot do this') . ' while this account has Admin: Admin switches off your '
                . Words::andList(array_map(static fn (string $r): string => Words::of('ROLE', $r), $jobs)) . (count($jobs) === 1 ? ' job.' : ' jobs.')
                . ($look ? '' : ' Nothing was changed.') . ' Ask ' . Words::ASK . ' to take Admin off this account.';
        }
        return match ($access) {
            Route::DECIDE => Words::error('decide_required'),
            Route::LEAD => Words::error('lead_only'),
            default => ($look ? 'This page is for ' . Words::whoCan($perm) . '.'
                    : 'Only ' . Words::whoCan($perm) . ' can do this. Nothing was changed.')
                . ' You work as: ' . Words::roles($who->roles) . '. If you need it for your work, ask ' . Words::ASK_ROLE . '.',
        };
    }

    public function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }
        $auth = new AuthController();
        $dash = new DashboardController();
        $review = new ReviewController();
        $items = new ItemController();
        $search = new SearchController();
        $people = new PeopleController();
        $documents = new DocumentsController();
        $reviews = new ReviewsController();
        $reference = new ReferenceController();
        $files = new FilesController();
        $suppliers = new SuppliersController();
        $supplierItems = new SupplierItemsController();
        $orders = new PurchaseOrdersController();
        $reorder = new ReorderController();
        $sales = new SalesHistoryController();
        $r = new Router();
        $r->add('GET', '/ui/login', Route::PUBLIC, $auth->loginForm(...));
        $r->add('POST', '/ui/login', Route::PUBLIC, $auth->login(...));
        $r->add('POST', '/ui/logout', Route::ANY, $auth->logout(...));
        // The sign-out address opened from the history or a bookmark (behaviour item 2, F043): never signs anybody out (a GET
        // could be sent by any page); signed in it leads Home, signed out to the sign-in page.
        $r->add('GET', '/ui/logout', Route::PUBLIC, $auth->signedOut(...));
        // A person sets their own password with their phone's code (G06, Y20, Y22): set up on the screen, or told to choose a new one.
        $r->add('GET', '/ui/enrol', Route::PUBLIC, $auth->enrolForm(...));
        $r->add('POST', '/ui/enrol', Route::PUBLIC, $auth->enrol(...));
        $r->add('GET', '/ui/new-code', Route::PUBLIC, $auth->newCodeForm(...));
        $r->add('POST', '/ui/new-code', Route::PUBLIC, $auth->newCode(...));
        $r->add('GET', '/ui/password', Route::ANY, $auth->passwordForm(...));
        $r->add('POST', '/ui/password', Route::ANY, $auth->password(...));
        $r->add('GET', '/ui', Route::ANY, $dash->index(...));
        $r->add('GET', '/ui/', Route::ANY, $dash->index(...));
        // The linking screens: linking.view (the inventory roles never had them, I14); search and items: catalogue.view.
        $r->add('GET', '/ui/review', 'linking.view', $review->queue(...));
        $r->add('GET', '/ui/review/listing/{id}', 'linking.view', $review->listing(...));
        $r->add('POST', '/ui/review/listing/{id}/decide', Route::DECIDE, $review->decide(...));
        $r->add('POST', '/ui/review/decision/{id}/approve', Route::LEAD, $review->approve(...));
        $r->add('POST', '/ui/review/decision/{id}/withdraw', Route::DECIDE, $review->withdraw(...));
        // Store-wise review and bulk action (M46-M53, U106-U112): Store Products for everyone with linking.view; the ticked rows of a
        // list for whoever may decide one (each row checked again by BulkDecisions and DecisionService); the batch page is the result.
        $bulk = new BulkController();
        $r->add('GET', '/ui/review/store', 'linking.view', (new StoreProductsController())->index(...));
        $r->add('POST', '/ui/review/bulk', Route::DECIDE, $bulk->run(...));
        $r->add('GET', '/ui/review/batches/{id}', 'linking.view', $bulk->show(...));
        // Duplicates (M34): Vape and Go's own duplicate listings; everyone with linking.view looks, a mapping lead decides (merge,
        // keep separate, split), checked again by DecisionService.
        $duplicates = new DuplicatesController();
        $r->add('GET', '/ui/review/duplicates', 'linking.view', $duplicates->index(...));
        $r->add('GET', '/ui/review/duplicates/{id}', 'linking.view', $duplicates->show(...));
        $r->add('POST', '/ui/review/duplicates/{id}/decide', Route::LEAD, $duplicates->decide(...));
        $r->add('POST', '/ui/review/duplicates/{id}/split', Route::LEAD, $duplicates->split(...));
        // The Key spot-check (M28): read-only; the owner decides each member on the listing page above.
        $samples = new SamplesController();
        $r->add('GET', '/ui/review/samples', 'linking.view', $samples->index(...));
        $r->add('GET', '/ui/review/samples/{id}', 'linking.view', $samples->show(...));
        $r->add('GET', '/ui/items/{id}', 'catalogue.view', $items->show(...));
        $r->add('GET', '/ui/search', 'catalogue.view', $search->index(...));
        // The item card and barcodes (IM3, I100-I112): everyone with catalogue.view reads the cards list and the item page; the
        // catalogue team (catalogue.edit, checked again by CW\Catalogue\*, never admin) changes cards, barcodes and decides the
        // barcode review.
        $cards = new ItemCardsController();
        $barcodes = new BarcodesController();
        $r->add('GET', '/ui/items/cards', 'catalogue.view', $cards->index(...));
        $r->add('GET', '/ui/items/cards.csv', 'catalogue.view', $cards->csv(...));
        $r->add('GET', '/ui/items/cards/import', 'catalogue.edit', $cards->importForm(...));
        $r->add('POST', '/ui/items/cards/import', 'catalogue.edit', $cards->import(...));
        $r->add('GET', '/ui/items/{id}/card', 'catalogue.edit', $cards->form(...));
        $r->add('POST', '/ui/items/{id}/card', 'catalogue.edit', $cards->save(...));
        $r->add('POST', '/ui/items/{id}/card/accept', 'catalogue.edit', $cards->accept(...));
        $r->add('POST', '/ui/items/{id}/card/confirm', 'catalogue.edit', $cards->confirm(...));
        $r->add('POST', '/ui/items/{id}/barcodes', 'catalogue.edit', $barcodes->add(...));
        // IM10 (I158): the selling-mode switch of a legacy item, per website.
        $r->add('POST', '/ui/items/{id}/selling-mode', 'modes.set', (new SellingModeController())->set(...));
        $r->add('POST', '/ui/items/{id}/barcodes/remove', 'catalogue.edit', $barcodes->remove(...));
        $r->add('POST', '/ui/items/{id}/barcodes/units', 'catalogue.edit', $barcodes->setUnits(...));
        $r->add('GET', '/ui/items/barcodes', 'catalogue.edit', $barcodes->queue(...));
        $r->add('POST', '/ui/items/barcodes/{id}/decide', 'catalogue.edit', $barcodes->decide(...));
        // People and roles (I13): admin and auditor look, admin changes.
        $r->add('GET', '/ui/people', 'staff.view', $people->index(...));
        $r->add('GET', '/ui/people/{id}', 'staff.view', $people->show(...));
        $r->add('POST', '/ui/people/{id}/roles', 'staff.manage', $people->roles(...));
        $r->add('POST', '/ui/people/{id}/active', 'staff.manage', $people->active(...));
        $r->add('GET', '/ui/people.csv', 'staff.view', $people->csv(...));
        // The set-it-yourself pack (G06, Y20-Y25): add a person (their QR code once), a new sign-in code, a new password chosen by the
        // person, signing out one device or all, withdrawing a request for Admin or Reviewer. Only an admin (staff.manage), checked
        // again by StaffAdmin / RoleRequests; a reviewer decides the requests (staff.approve).
        $r->add('POST', '/ui/people', 'staff.manage', $people->create(...));
        $r->add('POST', '/ui/people/{id}/sheet', 'staff.manage', $people->newSheet(...));
        $r->add('POST', '/ui/people/{id}/authenticator', 'staff.manage', $people->authenticator(...));
        $r->add('POST', '/ui/people/{id}/password', 'staff.manage', $people->password(...));
        $r->add('POST', '/ui/people/{id}/sign-out', 'staff.manage', $people->signOut(...));
        $r->add('POST', '/ui/people/{id}/request/withdraw', 'staff.manage', $people->withdrawRequest(...));
        $requests = new StaffRequestsController();
        $r->add('GET', '/ui/staff-requests', 'staff.approve', $requests->index(...));
        $r->add('POST', '/ui/staff-requests/{id}/approve', 'staff.approve', $requests->approve(...));
        $r->add('POST', '/ui/staff-requests/{id}/reject', 'staff.approve', $requests->reject(...));
        // Documents (IM1, I17-I27): the list and pages for documents.view; posting and reversing are checked per type by
        // CW\Documents\Documents (doc.<TYPE>.post), the kind of a review task likewise (documents.review / documents.approve).
        $r->add('GET', '/ui/documents', 'documents.view', $documents->index(...));
        $r->add('GET', '/ui/documents/reviews', 'documents.review', $reviews->queue(...));
        $r->add('POST', '/ui/documents/reviews/{id}/approve', 'documents.review', $reviews->approve(...));
        $r->add('POST', '/ui/documents/reviews/{id}/reject', 'documents.review', $reviews->reject(...));
        $r->add('GET', '/ui/documents/{id}', 'documents.view', $documents->show(...));
        $r->add('GET', '/ui/documents/{id}/pdf', 'documents.view', $documents->pdf(...));
        $r->add('POST', '/ui/documents/{id}/reverse', 'documents.view', $documents->reverse(...));
        $r->add('GET', '/ui/files/{id}', 'documents.view', $files->show(...));
        // The set-it-yourself pack (0019, Y4-Y19, Y30-Y35): everyone looks at the settings, rules, reasons and warehouses
        // (reference.view); an admin or a reviewer changes them (settings.manage, checked again by the services). The websites, the
        // safety checks (system.view) and the audit log (audit.view) are read only.
        $reasons = new ReasonsController();
        $r->add('GET', '/ui/reference/reasons', 'reference.view', $reasons->index(...));
        $r->add('POST', '/ui/reference/reasons', 'settings.manage', $reasons->add(...));
        $r->add('GET', '/ui/reference/reasons/reason', 'reference.view', $reasons->show(...));
        $r->add('POST', '/ui/reference/reasons/reason', 'settings.manage', $reasons->change(...));
        $r->add('GET', '/ui/reference/reasons.csv', 'reference.view', $reference->reasonsCsv(...));
        $r->add('GET', '/ui/reference/series', 'reference.view', $reference->series(...));
        $r->add('GET', '/ui/reference/settings', 'reference.view', $reference->settings(...));
        $setting = new SettingsController();
        $r->add('GET', '/ui/reference/settings/setting', 'reference.view', $setting->show(...));
        $r->add('POST', '/ui/reference/settings/setting', 'settings.manage', $setting->save(...));
        $approvals = new ApprovalsController();
        $r->add('GET', '/ui/reference/approvals', 'reference.view', $approvals->index(...));
        $r->add('POST', '/ui/reference/approvals', 'settings.manage', $approvals->save(...));
        $warehouses = new WarehousesController();
        $r->add('GET', '/ui/reference/warehouses', 'reference.view', $warehouses->index(...));
        $r->add('POST', '/ui/reference/warehouses', 'settings.manage', $warehouses->add(...));
        $r->add('GET', '/ui/reference/warehouses/{id}', 'reference.view', $warehouses->show(...));
        $r->add('POST', '/ui/reference/warehouses/{id}', 'settings.manage', $warehouses->change(...));
        $r->add('GET', '/ui/reference/access', 'reference.view', (new AccessController())->index(...));
        $system = new SystemController();
        $r->add('GET', '/ui/system/sites', 'system.view', $system->sites(...));
        $r->add('GET', '/ui/system/checks', 'system.view', $system->checks(...));
        $r->add('GET', '/ui/system/audit', 'audit.view', $system->audit(...));
        $r->add('GET', '/ui/system/audit.csv', 'audit.view', $system->auditCsv(...));
        // The company details printed on POs (I90-I99): everyone reads; company.edit saves, company.confirm confirms and decides a
        // review of another reviewer's change (reviewer today, never admin; checked again by CW\Company\CompanyDetails).
        $company = new CompanyController();
        $r->add('GET', '/ui/reference/company', 'reference.view', $company->show(...));
        $r->add('GET', '/ui/reference/company/sample.pdf', 'reference.view', $company->samplePdf(...));
        $r->add('GET', '/ui/reference/company/edit', 'company.edit', $company->editForm(...));
        $r->add('POST', '/ui/reference/company', 'company.edit', $company->save(...));
        $r->add('POST', '/ui/reference/company/confirm', 'company.confirm', $company->confirm(...));
        $r->add('POST', '/ui/reference/company/reviews/{id}/approve', 'company.confirm', $company->approveReview(...));
        $r->add('POST', '/ui/reference/company/reviews/{id}/reject', 'company.confirm', $company->rejectReview(...));
        // Purchasing, Phase I-2 (IM4 suppliers, I38-I47): everyone with suppliers.view looks; buyers change (suppliers.manage,
        // checked again by CW\Suppliers\*); reviewers decide supplier tasks (suppliers.approve and the second-person rule).
        $r->add('GET', '/ui/purchasing/suppliers', 'suppliers.view', $suppliers->index(...));
        $r->add('GET', '/ui/purchasing/suppliers.csv', 'suppliers.view', $suppliers->csv(...));
        $r->add('GET', '/ui/purchasing/suppliers/new', 'suppliers.manage', $suppliers->newForm(...));
        $r->add('POST', '/ui/purchasing/suppliers', 'suppliers.manage', $suppliers->create(...));
        $r->add('POST', '/ui/purchasing/suppliers/tasks/{id}/approve', 'suppliers.approve', $suppliers->approve(...));
        $r->add('POST', '/ui/purchasing/suppliers/tasks/{id}/reject', 'suppliers.approve', $suppliers->reject(...));
        $r->add('POST', '/ui/purchasing/suppliers/tasks/{id}/withdraw', 'suppliers.manage', $suppliers->withdraw(...));
        $r->add('GET', '/ui/purchasing/suppliers/{id}', 'suppliers.view', $suppliers->show(...));
        $r->add('GET', '/ui/purchasing/suppliers/{id}/edit', 'suppliers.manage', $suppliers->editForm(...));
        $r->add('POST', '/ui/purchasing/suppliers/{id}', 'suppliers.manage', $suppliers->update(...));
        $r->add('POST', '/ui/purchasing/suppliers/{id}/request-activation', 'suppliers.manage', $suppliers->requestActivation(...));
        $r->add('POST', '/ui/purchasing/suppliers/{id}/deactivate', 'suppliers.manage', $suppliers->deactivate(...));
        $r->add('POST', '/ui/purchasing/suppliers/{id}/check-alone', 'suppliers.approve', $suppliers->checkAlone(...));
        $r->add('POST', '/ui/purchasing/suppliers/{id}/evidence', 'suppliers.manage', $suppliers->evidence(...));
        $r->add('GET', '/ui/purchasing/suppliers/{id}/items', 'suppliers.view', $supplierItems->index(...));
        $r->add('GET', '/ui/purchasing/suppliers/{id}/items.csv', 'suppliers.view', $supplierItems->csv(...));
        $r->add('GET', '/ui/purchasing/suppliers/{id}/items/new', 'suppliers.manage', $supplierItems->newForm(...));
        $r->add('POST', '/ui/purchasing/suppliers/{id}/items', 'suppliers.manage', $supplierItems->create(...));
        $r->add('GET', '/ui/purchasing/supplier-items/{id}', 'suppliers.view', $supplierItems->show(...));
        $r->add('POST', '/ui/purchasing/supplier-items/{id}', 'suppliers.manage', $supplierItems->update(...));
        $r->add('POST', '/ui/purchasing/supplier-items/{id}/price', 'suppliers.manage', $supplierItems->price(...));
        // Purchase orders (IM5, I48-I59): purchasing.view looks; doc.PO.post (buyers) acts, checked again by CW\PurchaseOrders\PurchaseOrders
        // (and the creator-only rule of drafts); reviewers decide on the order's page through /ui/documents/reviews/{id}/*.
        $r->add('GET', '/ui/purchasing/orders', 'purchasing.view', $orders->index(...));
        $r->add('GET', '/ui/purchasing/orders.csv', 'purchasing.view', $orders->csv(...));
        $r->add('POST', '/ui/purchasing/orders', 'doc.PO.post', $orders->create(...));
        $r->add('GET', '/ui/purchasing/orders/{id}', 'purchasing.view', $orders->show(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/lines', 'doc.PO.post', $orders->lines(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/lines/import', 'doc.PO.post', $orders->import(...));
        $r->add('GET', '/ui/purchasing/orders/{id}/lines.csv', 'purchasing.view', $orders->linesCsv(...));
        $r->add('GET', '/ui/purchasing/orders/{id}/lines.xlsx', 'purchasing.view', $orders->linesXlsx(...));
        $r->add('GET', '/ui/purchasing/orders/{id}/pdf', 'purchasing.view', $orders->pdf(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/approve', 'doc.PO.post', $orders->approve(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/send', 'doc.PO.post', $orders->send(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/cancel', 'doc.PO.post', $orders->cancel(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/amend', 'doc.PO.post', $orders->amend(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/copy', 'doc.PO.post', $orders->copy(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/close', 'doc.PO.post', $orders->close(...));
        $r->add('POST', '/ui/purchasing/orders/{id}/withdraw', 'doc.PO.post', $orders->withdraw(...));
        // The reorder list and the sales history (IM9 basic, I60-I71): reorder.view looks; reorder.manage changes settings and
        // recalculates (checked again by CW\Reorder\ReorderSettings); doc.PO.post makes the drafts (checked again by PurchaseOrders).
        $r->add('GET', '/ui/purchasing/reorder', 'reorder.view', $reorder->index(...));
        $r->add('GET', '/ui/purchasing/reorder.csv', 'reorder.view', $reorder->csv(...));
        $r->add('POST', '/ui/purchasing/reorder/draft', 'doc.PO.post', $reorder->draft(...));
        $r->add('POST', '/ui/purchasing/reorder/recalculate', 'reorder.manage', $reorder->recalculate(...));
        $r->add('GET', '/ui/purchasing/reorder/items/{id}', 'reorder.view', $reorder->item(...));
        $r->add('POST', '/ui/purchasing/reorder/items/{id}', 'reorder.manage', $reorder->saveItem(...));
        $r->add('GET', '/ui/purchasing/reorder/brands', 'reorder.view', $reorder->brands(...));
        $r->add('POST', '/ui/purchasing/reorder/brands', 'reorder.manage', $reorder->saveBrand(...));
        $r->add('GET', '/ui/purchasing/reorder/anomalies', 'reorder.view', $reorder->anomalies(...));
        $r->add('POST', '/ui/purchasing/reorder/anomalies', 'reorder.manage', $reorder->addAnomaly(...));
        $r->add('POST', '/ui/purchasing/reorder/anomalies/{id}/end', 'reorder.manage', $reorder->endAnomaly(...));
        $r->add('GET', '/ui/purchasing/sales-history', 'reorder.view', $sales->index(...));
        $r->add('GET', '/ui/purchasing/sales-history/unlinked.csv', 'reorder.view', $sales->unlinkedCsv(...));
        // Receiving (IM6, I-3; I125-I147): receiving.view looks; doc.GRN.post (goods_in, purchasing_desk, purchasing_manager) keys,
        // checks at the bench and posts, checked again by CW\Receiving\GoodsReceipts (the creator-only rule of a draft's lines);
        // reviewers decide on the receipt's page through /ui/documents/reviews/{id}/*; incidents.view / incidents.resolve the register.
        $recv = new ReceivingController();
        $incidents = new IncidentsController();
        $r->add('GET', '/ui/receiving', 'receiving.view', $recv->index(...));
        $r->add('POST', '/ui/receiving', 'doc.GRN.post', $recv->create(...));
        $r->add('GET', '/ui/receiving/template.csv', 'receiving.view', $recv->template(...));
        $r->add('GET', '/ui/receiving/bench', 'doc.GRN.post', $recv->benchList(...));
        $r->add('GET', '/ui/receiving/incidents', 'incidents.view', $incidents->index(...));
        $r->add('POST', '/ui/receiving/incidents/{id}', 'incidents.resolve', $incidents->resolve(...));
        $r->add('GET', '/ui/receiving/{id}', 'receiving.view', $recv->show(...));
        $r->add('POST', '/ui/receiving/{id}/lines', 'doc.GRN.post', $recv->lines(...));
        $r->add('POST', '/ui/receiving/{id}/copy', 'doc.GRN.post', $recv->copy(...));
        $r->add('POST', '/ui/receiving/{id}/import', 'doc.GRN.post', $recv->import(...));
        $r->add('POST', '/ui/receiving/{id}/files', 'doc.GRN.post', $recv->files(...));
        $r->add('POST', '/ui/receiving/{id}/post', 'doc.GRN.post', $recv->post(...));
        $r->add('POST', '/ui/receiving/{id}/cancel', 'doc.GRN.post', $recv->cancel(...));
        $r->add('POST', '/ui/receiving/{id}/invoice', 'doc.GRN.post', $recv->invoice(...));
        $r->add('POST', '/ui/receiving/{id}/reverse', 'doc.GRN.post', $recv->reverse(...));
        $r->add('GET', '/ui/receiving/{id}/bench', 'doc.GRN.post', $recv->benchForm(...));
        $r->add('POST', '/ui/receiving/{id}/bench', 'doc.GRN.post', $recv->bench(...));
        // Stock (the owner's request of 8 Oct 2026): what each warehouse holds and every change, read only, for everyone who sees an
        // item's stock on its page (catalogue.view). Counts and quality come with pack A2 (Ui\Sections: "Soon").
        $stock = new StockController();
        $r->add('GET', '/ui/stock', 'catalogue.view', $stock->overview(...));
        $r->add('GET', '/ui/stock/movements', 'catalogue.view', $stock->movements(...));
        // Reservations (docs/decisions.md RS1-RS12): the stock the engine keeps for the stores' orders, for the same people. Read
        // only: GET routes and nothing else (CW\Reservations stays the only writer).
        $reservations = new ReservationsController();
        $r->add('GET', ReservationsController::PATH, 'catalogue.view', $reservations->index(...));
        $r->add('GET', ReservationsController::PATH . '.csv', 'catalogue.view', $reservations->csv(...));
        $r->add('GET', ReservationsController::PATH . '/{id}', 'catalogue.view', $reservations->show(...));
        // The stock records of pack A1 (docs/decisions.md SO10): documents.view looks; doc.<TYPE>.post keeps them (checked again by
        // CW\StockOps\StockOps and the document base, never admin). The balance owed to another account: documents.view looks,
        // accounts.pay records and reverses payments (checked again by CW\StockOps\OtherAccounts).
        StockOpsController::routes($r);
        $accounts = new OtherAccountsController();
        $r->add('GET', '/ui/stock/accounts', 'documents.view', $accounts->index(...));
        $r->add('POST', '/ui/stock/accounts/{id}/payments', 'accounts.pay', $accounts->pay(...));
        $r->add('POST', '/ui/stock/accounts/payments/{id}/reverse', 'accounts.pay', $accounts->reverse(...));
        return $this->router = $r;
    }

    /** A page for a failure before the person is known (no navigation). */
    private function bare(int $status, string $code, string $message, string $rid): HtmlResponse
    {
        $view = new View(View::defaultDir(), ['csrf' => '', 'who' => null]);
        $html = $view->page('error', ['status' => $status, 'code' => $code, 'heading' => Words::errorTitle($status), 'message' => $message, 'rid' => $rid],
            ['title' => Words::errorTitle($status), 'active' => '', 'notice' => null, 'nav' => [], 'tabs' => [], 'searchBox' => false,
                'testSystem' => $this->testSystem()]);
        return new HtmlResponse($status, $html);
    }

    private function failure(\Throwable $e, UiRequest $req, string $rid): HtmlResponse
    {
        ($this->log)("{$rid} {$req->method} {$req->path} failed: " . self::describe($e));
        $code = Db::driverCode($e) ?? ($e->getPrevious() !== null ? Db::driverCode($e->getPrevious()) : null);
        if ($code !== null && in_array($code, self::BUSY_CODES, true)) {
            return $this->bare(503, 'busy', Words::error('busy'), $rid)->withHeader('Retry-After', '1');
        }
        if ($code !== null && in_array($code, self::UNAVAILABLE_CODES, true)) {
            return $this->bare(503, 'unavailable', Words::error('unavailable'), $rid)->withHeader('Retry-After', '5');
        }
        return $this->bare(500, 'internal', Words::error('internal'), $rid);
    }

    private static function describe(\Throwable $e): string
    {
        $s = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        return $e->getPrevious() !== null ? $s . ' <- ' . self::describe($e->getPrevious()) : $s;
    }
}
