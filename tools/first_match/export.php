<?php
/**
 * First-time product match — read-only catalogue export for ONE site.
 *
 * Runs on (or with network access to) the site's database and writes a gzipped JSONL
 * snapshot, one line per variant, plus a manifest. Nothing is written to any database:
 * every query runs inside START TRANSACTION READ ONLY.
 *
 * Connection sources (pick one):
 *   --global-config=/path/to/App/global_config.php   uses that site's active $global_* vars
 *   --dbtransfer-dest=/path/to/App/db-transfer/config.php   uses its DEST_* constants (Electrofag)
 *
 * Usage:
 *   php export.php --site=vapeandgo  --global-config=/var/www/html/vpg_ecom/App/global_config.php --out=/root/cw_work/first_match
 *   php export.php --site=electrofag --dbtransfer-dest=/var/www/html/vpg_ecom/App/db-transfer/config.php --out=/root/cw_work/first_match
 *   (Vape Big: run on its own server with its own App/global_config.php)
 *
 * Units sold come from the nightly rollup consolidate_product_sale (Completed, Online orders),
 * not from orders_items, to keep the load on the live database trivial.
 */

$o = getopt('', ['site:', 'global-config::', 'dbtransfer-dest::', 'out::']);
$site = $o['site'] ?? '';
if (!preg_match('/^[a-z0-9_-]{2,32}$/', $site)) {
    fwrite(STDERR, "--site is required (e.g. vapeandgo, electrofag, vapebig)\n");
    exit(2);
}
$outDir = rtrim($o['out'] ?? getcwd(), '/');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!empty($o['global-config'])) {
    require $o['global-config'];
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $db->real_connect($global_servername, $global_username, $global_password, $global_dbname, (int) $global_dbport);
    $dbName = $global_dbname;
} elseif (!empty($o['dbtransfer-dest'])) {
    require $o['dbtransfer-dest'];
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $flags = 0;
    if (defined('DEST_SSL') && DEST_SSL) {
        $db->ssl_set(null, null, null, null, null);
        $flags = MYSQLI_CLIENT_SSL;
    }
    $db->real_connect(DEST_HOST, DEST_USER, DEST_PASS, DEST_DB, (int) DEST_PORT, null, $flags);
    $dbName = DEST_DB;
} else {
    fwrite(STDERR, "Give --global-config or --dbtransfer-dest\n");
    exit(2);
}
$db->set_charset('utf8mb4');
$db->query('SET SESSION TRANSACTION READ ONLY');
$db->query('START TRANSACTION READ ONLY');

$t0 = microtime(true);
$started = gmdate('Y-m-d\TH:i:s\Z');

/* Attribute names */
$attrNames = [];
foreach ($db->query('SELECT attr_id, attr_name FROM attributes_master') as $r) {
    $attrNames[(int) $r['attr_id']] = $r['attr_name'];
}

/* Attributes per variant */
$attrs = [];
$res = $db->query('SELECT proda_prodt_id, proda_attr_id, proda_title, proda_value, proda_is_variable
                     FROM product_attributes WHERE proda_prodt_id IS NOT NULL AND proda_prodt_id > 0', MYSQLI_USE_RESULT);
while ($r = $res->fetch_assoc()) {
    $attrs[(int) $r['proda_prodt_id']][] = [
        'attr_id'     => (int) $r['proda_attr_id'],
        'name'        => $attrNames[(int) $r['proda_attr_id']] ?? $r['proda_title'],
        'value'       => $r['proda_value'],
        'is_variable' => (int) $r['proda_is_variable'],
    ];
}
$res->free();

/* Barcodes per variant (inventory_sku_barcode only — never isku_no, which is P1-<local id>) */
$barcodes = [];
$res = $db->query("SELECT k.isku_item_id, b.isb_code
                     FROM inventory_stock_keeping_units k
                     JOIN inventory_sku_barcode b ON b.isb_isku_id = k.isku_id
                    WHERE k.isku_item_table = 'product_types_and_pricing'", MYSQLI_USE_RESULT);
while ($r = $res->fetch_assoc()) {
    $code = trim((string) $r['isb_code']);
    if ($code !== '') {
        $barcodes[(int) $r['isku_item_id']][$code] = true;
    }
}
$res->free();

/* Units sold (nightly rollup; Completed Online orders) */
$units = [];
$res = $db->query("SELECT cps_prodt_id,
                          SUM(CASE WHEN cps_date >= CURDATE() - INTERVAL 30 DAY THEN cps_sale_qty ELSE 0 END) u30,
                          SUM(cps_sale_qty) u365,
                          MAX(CASE WHEN cps_sale_qty > 0 THEN cps_date END) last_sale
                     FROM consolidate_product_sale
                    WHERE cps_date >= CURDATE() - INTERVAL 365 DAY
                    GROUP BY cps_prodt_id");
while ($r = $res->fetch_assoc()) {
    $units[(int) $r['cps_prodt_id']] = [(int) $r['u30'], (int) $r['u365'], $r['last_sale']];
}
$res->free();

/* Variants */
$sql = "SELECT pt.prodt_id, pt.prodt_prod_id, pt.prodt_name, pt.prodt_status, pt.prodt_islanding, pt.prodt_isDefault,
               pt.prodt_stock_mode, pt.prodt_allow_backorders, pt.prodt_stock, pt.prodt_regular_price, pt.prodt_sale_price,
               pt.prodt_cost, pt.prodt_perma_link, pt.prodt_code, pt.prodt_added_date, pt.prodt_updated_date,
               pm.prod_name, pm.prod_status, pm.prod_type, pm.prod_perma_link, pm.prod_brand_id, bm.brand_name
          FROM product_types_and_pricing pt
          LEFT JOIN product_master pm ON pm.prod_id = pt.prodt_prod_id
          LEFT JOIN brand_master bm   ON bm.brand_id = pm.prod_brand_id
         ORDER BY pt.prodt_id";
$file = sprintf('%s/%s_listings_%s.jsonl.gz', $outDir, $site, gmdate('Ymd\THis\Z'));
$gz = gzopen($file, 'wb6');
$n = 0; $pub = 0; $withBarcode = 0;
$res = $db->query($sql, MYSQLI_USE_RESULT);
while ($r = $res->fetch_assoc()) {
    $id = (int) $r['prodt_id'];
    $u = $units[$id] ?? [0, 0, null];
    $bc = array_keys($barcodes[$id] ?? []);
    $row = [
        'site'            => $site,
        'variant_id'      => $id,
        'product_id'      => (int) $r['prodt_prod_id'],
        'product_title'   => $r['prod_name'],
        'variant_title'   => $r['prodt_name'],
        'brand'           => $r['brand_name'],
        'brand_id'        => $r['prod_brand_id'] !== null ? (int) $r['prod_brand_id'] : null,
        'product_type'    => $r['prod_type'],
        'variant_status'  => $r['prodt_status'],
        'product_status'  => $r['prod_status'],
        'is_landing'      => (int) $r['prodt_islanding'],
        'is_default'      => (int) $r['prodt_isDefault'],
        'stock_mode'      => $r['prodt_stock_mode'],
        'allow_backorder' => $r['prodt_allow_backorders'] !== null ? (int) $r['prodt_allow_backorders'] : null,
        'stock'           => $r['prodt_stock'] !== null ? (float) $r['prodt_stock'] : null,
        'price'           => $r['prodt_regular_price'] !== null ? (float) $r['prodt_regular_price'] : null,
        'sale_price'      => $r['prodt_sale_price'] !== null ? (float) $r['prodt_sale_price'] : null,
        'cost'            => $r['prodt_cost'] !== null ? (float) $r['prodt_cost'] : null,
        'permalink'       => $r['prodt_perma_link'],
        'product_permalink' => $r['prod_perma_link'],
        'code'            => $r['prodt_code'],
        'barcodes'        => $bc,
        'attributes'      => $attrs[$id] ?? [],
        'units_30d'       => $u[0],
        'units_365d'      => $u[1],
        'last_sale'       => $u[2],
        'added'           => $r['prodt_added_date'],
        'updated'         => $r['prodt_updated_date'],
    ];
    gzwrite($gz, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    $n++;
    if ($r['prodt_status'] === 'Published' && $r['prod_status'] === 'Published' && (int) $r['prodt_islanding'] !== 1) $pub++;
    if ($bc) $withBarcode++;
}
$res->free();
gzclose($gz);

$migrations = null;
try {
    $migrations = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetch_row()[0];
} catch (Throwable $e) {
    $migrations = null;
}
$db->rollback();

$manifest = [
    'site'                 => $site,
    'database'             => $dbName,
    'exported_at'          => $started,
    'seconds'              => round(microtime(true) - $t0, 1),
    'variants'             => $n,
    'published_non_landing'=> $pub,
    'with_barcode'         => $withBarcode,
    'variants_with_sales_365d' => count(array_filter($units, fn($x) => $x[1] > 0)),
    'schema_migrations'    => $migrations,
    'file'                 => basename($file),
    'sha256'               => hash_file('sha256', $file),
];
file_put_contents(preg_replace('/\.jsonl\.gz$/', '.manifest.json', $file), json_encode($manifest, JSON_PRETTY_PRINT) . "\n");
echo json_encode($manifest, JSON_PRETTY_PRINT), "\n";
