<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Auth\Csrf;
use CW\Auth\Permissions;
use CW\Auth\StaffIdentity;
use CW\Caller;
use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\ItemCards;
use CW\Company\CompanyDetails;
use CW\Config;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Mapping\DecisionService;
use CW\Receiving\GoodsReceipts;
use CW\Receiving\Incidents;
use CW\Settings;
use CW\Staff\SecretBox;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;

/**
 * Everything a controller needs for one /ui request: the request, the database, the signed-in
 * person (null only on public routes), CSRF tokens, the renderer and the route's `{id}` values.
 */
final class Context
{
    private ?Queries $queries = null;
    private ?DecisionService $decisions = null;
    private ?Documents $documents = null;
    private ?FileStore $files = null;
    private ?Settings $settings = null;
    private ?Suppliers $suppliers = null;
    private ?SupplierItems $supplierItems = null;
    private ?CompanyDetails $company = null;
    private ?ItemCards $itemCards = null;
    private ?GoodsReceipts $goodsReceipts = null;
    /** @var array<string, int>|null the badge counts of this request (computed once) */
    private ?array $badges = null;
    /** @var array{approval: int, review: int}|null the review queue's tasks this person may decide, by kind (checks()) */
    private ?array $checks = null;

    /** Pages without a menu item of their own => the menu item that stays marked. */
    private const ACTIVE_ALIAS = ['reasons' => 'settings', 'series' => 'settings'];

    /**
     * @param array<string, string> $params route parameters
     * @param string|null $pre the login form's pre-session cookie value (public routes)
     * @param (\Closure(Db): array<string, \CW\Documents\DocumentHandler>)|null $handlers the live document types (Kernel)
     * @param (\Closure(string): void)|null $log the kernel's error log (file integrity failures land there with the request id)
     */
    public function __construct(
        public readonly UiRequest $req,
        public readonly Db $db,
        public readonly ?StaffIdentity $who,
        public readonly Csrf $csrf,
        public readonly array $params,
        public readonly string $rid,
        public readonly ?string $pre = null,
        #[\SensitiveParameter] private readonly string $secretKey = '',
        private readonly ?\Closure $handlers = null,
        private readonly ?\Closure $log = null,
        /** app.env says environment=staging: every page carries the "TEST SYSTEM" strip. */
        public readonly bool $testSystem = false,
    ) {
    }

    /** The box that seals TOTP secrets (app.env ui_secret_key). */
    public function secretBox(): SecretBox
    {
        return SecretBox::fromBase64($this->secretKey);
    }

    public function me(): StaffIdentity
    {
        return $this->who ?? throw new \LogicException('this route needs a signed-in person');
    }

    public function caller(): Caller
    {
        return Caller::staff($this->me()->id, $this->req->ip);
    }

    public function queries(): Queries
    {
        return $this->queries ??= new Queries($this->db);
    }

    public function decisions(): DecisionService
    {
        return $this->decisions ??= new DecisionService($this->db);
    }

    /** The document base with the live document types (none in I-1: DocumentHandlers::all). */
    public function documents(): Documents
    {
        return $this->documents ??= new Documents($this->db, $this->handlers !== null ? ($this->handlers)($this->db) : DocumentHandlers::all($this->db));
    }

    /** CW's settings (app_setting), read once per request. */
    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db);
    }

    /** Suppliers and their approvals (IM4). */
    public function suppliers(): Suppliers
    {
        return $this->suppliers ??= new Suppliers($this->db, $this->settings());
    }

    /** Supplier items and prices (IM4). */
    public function supplierItems(): SupplierItems
    {
        return $this->supplierItems ??= new SupplierItems($this->db);
    }

    /** The company details printed on POs (company_profile, I90-I99). */
    public function company(): CompanyDetails
    {
        return $this->company ??= new CompanyDetails($this->db);
    }

    /** The item cards (IM3, I100-I105). */
    public function itemCards(): ItemCards
    {
        return $this->itemCards ??= new ItemCards($this->db);
    }

    /** Goods receipts (IM6, I125-I147), with the file store when this server has one (attach() answers 503 otherwise). */
    public function goodsReceipts(): GoodsReceipts
    {
        return $this->goodsReceipts ??= new GoodsReceipts($this->db, $this->documents(), $this->settings(), fn (): FileStore => $this->files());
    }

    /** The file store app.env names (file_store_dir / CW_FILE_STORE_DIR); 503 file_store_unconfigured without one. */
    public function files(): FileStore
    {
        $log = $this->log;
        $rid = $this->rid;
        return $this->files ??= FileStore::fromConfig(Config::loadApp(), $this->db, static function (string $m) use ($log, $rid): void {
            if ($log !== null) {
                $log("{$rid} {$m}");
            } else {
                error_log("[cw-ui] {$rid} {$m}");
            }
        });
    }

    public function id(string $name = 'id'): int
    {
        return (int) ($this->params[$name] ?? 0);
    }

    /** The CSRF token for the forms of this page (bound to the session, or to the login form's cookie). */
    public function token(): string
    {
        if ($this->who !== null) {
            return $this->csrf->forSession($this->who->sessionId);
        }
        return $this->pre !== null ? $this->csrf->forPre($this->pre) : '';
    }

    /**
     * A page in the layout (frame()).
     *
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $layout title, active (nav key), notice ...
     */
    public function page(string $template, array $vars, int $status = 200, array $layout = []): HtmlResponse
    {
        $token = $this->token();
        $shared = ['csrf' => $token, 'who' => $this->who];
        $view = new View(View::defaultDir(), $shared);
        $layout += $this->frame();
        $layout['active'] = self::ACTIVE_ALIAS[$layout['active']] ?? $layout['active'];
        return new HtmlResponse($status, $view->page($template, $vars, $layout));
    }

    /**
     * The layout's variables for this person: their menu (Permissions::menu of the roles read for this request, I14), the
     * badge counts it shows, the phone tab bar (Tabs), whether the find box is theirs (catalogue.view), the test-system strip
     * and the strip that says which jobs Admin switches off. During the forced password change there is no menu, no tab bar
     * and no find box (every link would lead back to the password page): only Sign out.
     *
     * @return array<string, mixed>
     */
    public function frame(): array
    {
        $who = $this->who;
        $forced = $who !== null && $who->mustChangePassword;
        $menu = $forced ? [] : $this->menu();
        return [
            'title' => Words::UI['brand'],
            'active' => '',
            'notice' => null,
            'menu' => $menu,
            'badges' => $forced ? [] : $this->badges(),
            'searchBox' => !$forced && ($who?->can('catalogue.view') ?? false),
            'tabs' => Tabs::of($menu),
            'testSystem' => $this->testSystem,
            'switchedOff' => $who === null ? null : Words::switchedOffNote($who->roles),
        ];
    }

    /** @return list<array{section: string, key: string, items: list<array<string, mixed>>}> the signed-in person's menu ([] when nobody) */
    public function menu(): array
    {
        return $this->who === null ? [] : Permissions::menu($this->who->roles);
    }

    /**
     * Badge counts, only of work this person can act on (plan F007), computed once per request:
     *         linking_pending (decisions waiting for a second approval that this matching lead did not make), linking_duplicates
     *         (open duplicate groups, M34, for a matching lead: they decide them), reviews_open (open review and approval tasks this
     *         person may decide: not opened by them, not on a document they created, submitted or posted, I19; plus the
     *         open supplier tasks they may decide: not on a supplier they created, asked for or last changed, I40; plus the
     *         open reviews of a change of the company details they did not make, I94; a goods receipt is not offered to the
     *         person who did its bench check, I133; the sum of checks()), barcodes_open (open barcode reviews, for catalogue.edit,
     *         I107), incidents_open (open incidents of posted receipts, for incidents.view, I131). Home's cards (Ui\HomeCounts)
     *         use the same numbers.
     *
     * @return array<string, int> badge name (Permissions::MENU `badge`) => count
     */
    public function badges(): array
    {
        if ($this->badges !== null) {
            return $this->badges;
        }
        $out = [];
        if ($this->who !== null && $this->who->can('mapping.approve')) {
            $out['linking_pending'] = $this->queries()->pendingCountFor($this->who->id);
            $out['linking_duplicates'] = (new Duplicates($this->db))->openCount();
        }
        if ($this->who !== null && $this->who->can('catalogue.edit')) {
            // The barcode review queue (IM3, I107): open rows, for the people who decide them.
            $out['barcodes_open'] = (new BarcodeReviews($this->db))->openCount();
        }
        if ($this->who !== null && $this->who->can('incidents.view')) {
            // The incident register (IM6, I131): the open incidents of posted receipts.
            $out['incidents_open'] = (new Incidents($this->db))->openCount();
        }
        $checks = $this->checks();
        if ($checks !== null) {
            $out['reviews_open'] = $checks['approval'] + $checks['review'];
        }
        return $this->badges = $out;
    }

    /**
     * The open tasks of the review queue this person may decide, by kind (computed once per request; null when their jobs
     * decide none): `approval` (blocking: nothing goes ahead until a reviewer says yes) and `review` (done already, a
     * reviewer checks it). Documents, suppliers and the company details share the queue (I40, I94); a check of the company
     * details is always a review. Their sum is the badge reviews_open; Home shows them as two cards.
     *
     * @return array{approval: int, review: int}|null
     */
    public function checks(): ?array
    {
        $who = $this->who;
        if ($who === null || !($who->can('documents.review') || $who->can('suppliers.approve') || $who->can('company.confirm'))) {
            return null;
        }
        if ($this->checks !== null) {
            return $this->checks;
        }
        // One query per source (documents and suppliers by kind, the company details): the same three as the badge before Home.
        $docs = $this->documents()->decidableCounts($who->id, $who->roles);
        $sups = $this->suppliers()->decidableCounts($who->id, $who->roles);
        return $this->checks = [
            'approval' => $docs['approval'] + $sups['approval'],
            'review' => $docs['review'] + $sups['review'] + $this->company()->decidableCount($who->id, $who->roles),
        ];
    }

    /**
     * An error page (with the navigation when signed in): the heading by status, the message by error code (Words::ERROR; a
     * service's own message where the code has none: those messages are the API's and stay as they are). $back, when given,
     * is the page to go back to ([path, its name], plan F048) besides Home.
     *
     * @param array{0: string, 1: string}|null $back
     */
    public function error(int $status, string $code, string $message, ?array $back = null): HtmlResponse
    {
        $heading = Words::errorTitle($status, $this->req->method === 'POST');
        return $this->page('error', ['status' => $status, 'code' => $code, 'heading' => $heading,
            'message' => Words::error($code, $message), 'rid' => $this->rid, 'back' => $back], $status, ['title' => $heading]);
    }
}
