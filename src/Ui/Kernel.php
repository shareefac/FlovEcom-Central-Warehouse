<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Csrf;
use CW\Auth\Sessions;
use CW\Config;
use CW\ConfigException;
use CW\CwException;
use CW\Db;
use CW\Ui\Controller\AuthController;
use CW\Ui\Controller\CompanyController;
use CW\Ui\Controller\DashboardController;
use CW\Ui\Controller\DocumentsController;
use CW\Ui\Controller\FilesController;
use CW\Ui\Controller\ItemController;
use CW\Ui\Controller\PeopleController;
use CW\Ui\Controller\PurchaseOrdersController;
use CW\Ui\Controller\ReferenceController;
use CW\Ui\Controller\ReorderController;
use CW\Ui\Controller\ReviewController;
use CW\Ui\Controller\ReviewsController;
use CW\Ui\Controller\SalesHistoryController;
use CW\Ui\Controller\SamplesController;
use CW\Ui\Controller\SearchController;
use CW\Ui\Controller\SupplierItemsController;
use CW\Ui\Controller\SuppliersController;

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

    private const BUSY_CODES = [1205, 1213];
    private const UNAVAILABLE_CODES = [1040, 1044, 1045, 1049, 1203, 2002, 2003, 2005, 2006, 2013];

    private ?Router $router = null;

    /**
     * @param \Closure(): Db $connect
     * @param \Closure(): ?string $secretKey base64 ui_secret_key
     * @param \Closure(string): void $log
     * @param (\Closure(Db): array<string, \CW\Documents\DocumentHandler>)|null $handlers the live document types
     *        (default CW\Documents\DocumentHandlers::all: PO since I-2; tests add the fixture ADJ type)
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly \Closure $secretKey,
        private readonly \Closure $log,
        private readonly ?\Closure $handlers = null,
    ) {
    }

    /** The front controller's kernel: app.env only (like the API), CW_* overrides from the process env. */
    public static function fromEnvironment(): self
    {
        return new self(
            static fn (): Db => Db::connect(Config::loadApp()->dbApp()),
            static fn (): ?string => Config::loadApp()->get('ui_secret_key'),
            static function (string $message): void {
                error_log('[cw-ui] ' . $message);
            },
        );
    }

    public function handle(UiRequest $req): HtmlResponse
    {
        $rid = bin2hex(random_bytes(8));
        try {
            $response = $this->dispatch($req, $rid);
        } catch (CwException $e) {
            $response = $this->bare($e->httpStatus, $e->errorCode, $e->getMessage(), $rid);
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
                return $this->bare(503, 'unavailable', 'the staff screens are not configured yet', $rid);
            }
            $csrf = Csrf::fromSecretKey($key);
        } catch (ConfigException | \PDOException $e) {
            ($this->log)("{$rid} unavailable: " . self::describe($e));
            return $this->bare(503, 'unavailable', 'the staff screens are temporarily unavailable; retry shortly', $rid)->withHeader('Retry-After', '5');
        }

        $who = (new Sessions($db))->resolve($req->cookie(self::SESSION_COOKIE));
        $pre = $req->cookie(self::PRE_COOKIE);
        $pre = $pre !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $pre) === 1 ? $pre : null;
        $ctx = new Context($req, $db, $who, $csrf, $params, $rid, $pre, $key, $this->handlers, $this->log);
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
                return HtmlResponse::redirect('/ui/');
            }
        } else {
            if ($who === null) {
                $to = HtmlResponse::redirect('/ui/login');
                return $req->cookie(self::SESSION_COOKIE) !== null ? $to->withoutCookie(self::SESSION_COOKIE, $req->secure) : $to;
            }
            if ($who->mustChangePassword && !in_array($req->path, ['/ui/password', '/ui/logout'], true)) {
                return HtmlResponse::redirect('/ui/password');
            }
            if ($route->access !== Route::ANY && !$who->can($route->access)) {
                throw match ($route->access) {
                    Route::DECIDE => new CwException('role_not_allowed', $who->rolesPhrase(true) . ' cannot make mapping decisions', 403),
                    Route::LEAD => new CwException('lead_required', 'only a mapping lead can do this', 403),
                    default => new CwException('role_not_allowed', $who->rolesPhrase(true)
                        . (count($who->roles) === 1 ? ' does not open this page' : ' do not open this page'), 403),
                };
            }
        }
        if ($req->method === 'POST') {
            // A body over the UI pool's post_max_size (2M) reaches PHP with no fields and no files at all: say so (413)
            // rather than "this form has expired" (the CSRF field was dropped with the rest).
            if ($req->post === [] && $req->files === [] && (int) ($req->header('content-length') ?? '0') > UiRequest::MAX_UPLOAD_BYTES) {
                throw new CwException('too_large', 'the form was larger than ' . intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576)
                    . ' MiB (a file is at most ' . intdiv(UiRequest::MAX_UPLOAD_BYTES, 1_048_576) . ' MiB): nothing was saved', 413);
            }
            // PHP drops the fields past max_input_vars without a word: a POST that arrives with that many may have lost
            // some (a typed charge, a line's packs), so nothing is done with it (I73).
            if (count($req->post) >= UiRequest::maxInputVars()) {
                throw new CwException('form_truncated', 'the form had more fields than this server accepts (' . UiRequest::maxInputVars()
                    . '), so some may have been dropped: nothing was saved. A long purchase order is changed with the file import.', 400);
            }
            $this->checkOrigin($req);
            $token = $req->field('csrf');
            // A public form (the sign-in) always carries the pre-login token, even when the browser holds a
            // session by now (a second tab): that POST replaces the session. Every other form is bound to the session.
            $ok = $route->access !== Route::PUBLIC && $who !== null
                ? $ctx->csrf->validForSession($who->sessionId, $token)
                : $ctx->csrf->validForPre($ctx->pre, $token);
            if (!$ok) {
                throw new CwException('csrf', 'this form has expired or did not come from this site: go back, reload the page and try again', 403);
            }
        }
        return ($route->handler)($ctx);
    }

    /** A browser that says where the POST came from must say "here". (Absent headers: the token and SameSite=Strict decide.) */
    private function checkOrigin(UiRequest $req): void
    {
        $site = $req->header('sec-fetch-site');
        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            throw new CwException('csrf', 'cross-site form posts are refused', 403);
        }
        $origin = $req->header('origin');
        if ($origin !== null) {
            $host = $req->header('host');
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);
            $given = is_string($originHost) ? $originHost . (is_int($originPort) ? ':' . $originPort : '') : null;
            if ($host === null || $given === null || strcasecmp($given, $host) !== 0) {
                throw new CwException('csrf', 'cross-site form posts are refused', 403);
            }
        }
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
        // The Key spot-check (M28): read-only; the owner decides each member on the listing page above.
        $samples = new SamplesController();
        $r->add('GET', '/ui/review/samples', 'linking.view', $samples->index(...));
        $r->add('GET', '/ui/review/samples/{id}', 'linking.view', $samples->show(...));
        $r->add('GET', '/ui/items/{id}', 'catalogue.view', $items->show(...));
        $r->add('GET', '/ui/search', 'catalogue.view', $search->index(...));
        // People and roles (I13): admin and auditor look, admin changes.
        $r->add('GET', '/ui/people', 'staff.view', $people->index(...));
        $r->add('GET', '/ui/people/{id}', 'staff.view', $people->show(...));
        $r->add('POST', '/ui/people/{id}/roles', 'staff.manage', $people->roles(...));
        $r->add('POST', '/ui/people/{id}/active', 'staff.manage', $people->active(...));
        $r->add('GET', '/ui/people.csv', 'staff.view', $people->csv(...));
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
        $r->add('GET', '/ui/reference/reasons', 'reference.view', $reference->reasons(...));
        $r->add('GET', '/ui/reference/reasons.csv', 'reference.view', $reference->reasonsCsv(...));
        $r->add('GET', '/ui/reference/series', 'reference.view', $reference->series(...));
        $r->add('GET', '/ui/reference/settings', 'reference.view', $reference->settings(...));
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
        return $this->router = $r;
    }

    /** A page for a failure before the person is known (no navigation). */
    private function bare(int $status, string $code, string $message, string $rid): HtmlResponse
    {
        $view = new View(View::defaultDir(), ['csrf' => '', 'who' => null]);
        $html = $view->page('error', ['status' => $status, 'code' => $code, 'message' => $message, 'rid' => $rid],
            ['title' => 'Error ' . $status, 'active' => '', 'notice' => null, 'menu' => [], 'badges' => [], 'searchBox' => false]);
        return new HtmlResponse($status, $html);
    }

    private function failure(\Throwable $e, UiRequest $req, string $rid): HtmlResponse
    {
        ($this->log)("{$rid} {$req->method} {$req->path} failed: " . self::describe($e));
        $code = Db::driverCode($e) ?? ($e->getPrevious() !== null ? Db::driverCode($e->getPrevious()) : null);
        if ($code !== null && in_array($code, self::BUSY_CODES, true)) {
            return $this->bare(503, 'busy', 'CW is busy; retry shortly', $rid)->withHeader('Retry-After', '1');
        }
        if ($code !== null && in_array($code, self::UNAVAILABLE_CODES, true)) {
            return $this->bare(503, 'unavailable', 'the staff screens are temporarily unavailable; retry shortly', $rid)->withHeader('Retry-After', '5');
        }
        return $this->bare(500, 'internal', 'internal error', $rid);
    }

    private static function describe(\Throwable $e): string
    {
        $s = get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
        return $e->getPrevious() !== null ? $s . ' <- ' . self::describe($e->getPrevious()) : $s;
    }
}
