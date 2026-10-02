<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * One /ui request: method, path, query, form fields, uploaded files, cookies and the few headers the UI reads.
 * REMOTE_ADDR is the ONLY client address (mod_remoteip stays disabled, as for the API), so a
 * forged X-Forwarded-For can neither dodge the login limiter nor plant an address in audit_log.
 *
 * Uploads (I-2, docs/decisions.md I46): `files` holds one entry per file field, {path, name, size, error}; fromGlobals()
 * keeps only what PHP really received through this request (is_uploaded_file()), plus the entries PHP refused for their
 * size (UPLOAD_ERR_INI_SIZE / FORM_SIZE, no file), so a screen can answer 413. The UI pool caps an upload at 2 MiB
 * (upload_max_filesize, post_max_size 2M): a larger body arrives with no fields at all, which Kernel answers 413 too.
 */
final class UiRequest
{
    /** The largest upload the UI pool accepts (upload_max_filesize = post_max_size = 2M). */
    public const MAX_UPLOAD_BYTES = 2_097_152;

    /**
     * PHP's max_input_vars (1000 unless the pool raises it). PHP silently DROPS the fields of a POST past it, so Kernel
     * refuses a POST that arrives with this many fields (400 form_truncated, I73) and big forms size themselves below it.
     */
    public static function maxInputVars(): int
    {
        $v = (int) ini_get('max_input_vars');
        return $v > 0 ? $v : 1000;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, string> $headers lower-case name => value
     * @param array<string, array{path: string, name: string, size: int, error: int}> $files file field => the upload
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $headers,
        public readonly string $ip,
        public readonly bool $secure,
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH']) && (is_string($server['CONTENT_LENGTH']) || is_int($server['CONTENT_LENGTH']))) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }
        $files = [];
        foreach ($_FILES as $field => $f) {
            // One file per field (a `name[]` array field is ignored, as field() ignores arrays).
            if (!is_string($field) || !is_array($f) || !is_string($f['name'] ?? null) || !is_int($f['error'] ?? null)) {
                continue;
            }
            $tmp = is_string($f['tmp_name'] ?? null) ? $f['tmp_name'] : '';
            if ($f['error'] === UPLOAD_ERR_OK) {
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    continue;
                }
            } elseif (!in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE], true)) {
                continue;
            } else {
                $tmp = '';
            }
            $files[$field] = ['path' => $tmp, 'name' => $f['name'], 'size' => is_int($f['size'] ?? null) ? $f['size'] : 0, 'error' => $f['error']];
        }
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $https = $server['HTTPS'] ?? '';
        return new self(
            strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET'),
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $_POST,
            $_COOKIE,
            $headers,
            filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0',
            is_string($https) && $https !== '' && strtolower($https) !== 'off',
            $files,
        );
    }

    /** A query-string value that is a plain string (an array value `a[]=` counts as absent). */
    public function param(string $name): ?string
    {
        $v = $this->query[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    /** A form field that is a plain string. */
    public function field(string $name): ?string
    {
        $v = $this->post[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    /**
     * Every plain-string form field whose name matches $regex (an array value is ignored, as by field()), in the order the
     * form sent them: the many-row forms (the PO editor's line_<n>_packs, the reorder list's pick_<sku>) read their rows
     * with it, since field() takes one name at a time (I-2, spec §8.2).
     *
     * @return array<string, string>
     */
    public function fieldsMatching(string $regex): array
    {
        $out = [];
        foreach ($this->post as $name => $v) {
            if (is_string($v) && preg_match($regex, (string) $name) === 1) {
                $out[(string) $name] = $v;
            }
        }
        return $out;
    }

    /**
     * The upload of a file field, or null when the form sent none: {path (the temporary file; '' when PHP refused it),
     * name (the client's file name: never trusted for anything but display), size, error (UPLOAD_ERR_*)}.
     *
     * @return array{path: string, name: string, size: int, error: int}|null
     */
    public function file(string $name): ?array
    {
        $f = $this->files[$name] ?? null;
        return is_array($f) && ($f['error'] ?? null) !== UPLOAD_ERR_NO_FILE ? $f : null;
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** The positive integer in a query/form value, else null. */
    public static function id(?string $v): ?int
    {
        return $v !== null && preg_match('/^[1-9][0-9]{0,17}$/D', $v) === 1 ? (int) $v : null;
    }
}
