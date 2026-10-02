#!/usr/bin/env bash
# Installs (idempotently) the CW document store on the STAGING server (docs/decisions.md I23, docs/ops.md "File store"):
#   /srv/cw-docs             root:www-data 02770, outside every web root and every code directory
#   /srv/cw-docs/tmp         copies being written (fsync'd, then hard-linked into a shard)
#   /srv/cw-docs/00 .. ff    the shards: root:www-data 02770 and APPEND-ONLY (chattr +a): a file can be added, never
#                            removed or renamed, by anyone (root included) until the attribute is taken off
#   app.env file_store_dir = /srv/cw-docs   (through CW\Staff\AppEnvFile: mode and group kept)
#   every stored file sealed (deploy/staging/seal_file_store.sh: root:www-data 0440, chattr +i), which cron then repeats
#   every minute for new files (deploy/staging/cw-staging.cron, installed by install_cron.sh)
# Then it checks that removing a file from a shard fails, that a SEALED file cannot be rewritten in place (by www-data
# or root), and that php-fpm's user can write a copy. Until the sweep has sealed a new file (at most a minute), its
# content is protected by detection only (re-hash on read, bin/verify_files.php nightly): I36.
# Before live this local store is replaced by an S3 (London) / B2 bucket with Object Lock in COMPLIANCE mode.
#
# NOT run by the build: the owner approves it after the Phase I-1 deploy (install_cron.sh --migrate).
# Run from this machine (it syncs the repo to /opt/cw-<slot> first; never from /opt/cw-staging):
#   scripts/remote.sh <slot> bash deploy/staging/install_file_store.sh
set -euo pipefail

root=/srv/cw-docs
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
say() { echo "[install_file_store] $*"; }
die() { echo "install_file_store: $*" >&2; exit 1; }

[[ $(id -u) -eq 0 ]] || die "run as root"
[[ $repo == /opt/cw-* && $repo != /opt/cw-staging ]] || die "run from a slot copy /opt/cw-<slot> (scripts/remote.sh <slot> ...), not $repo"
[[ -f $repo/vendor/autoload.php ]] || die "$repo has no vendor/ (scripts/remote.sh installs it)"
getent group www-data >/dev/null || die "group www-data is missing"
case "$root" in
    /var/www*|*/public|*/public/*|/opt/*) die "refusing $root: a web or code directory" ;;
esac

# 1. Directories (02770: group www-data writes, setgid keeps the group on everything created inside).
install -d -o root -g www-data -m 02770 "$root" "$root/tmp"
made=0
for i in $(seq 0 255); do
    d=$(printf '%s/%02x' "$root" "$i")
    if [[ ! -d $d ]]; then
        install -d -o root -g www-data -m 02770 "$d"
        made=$((made + 1))
    fi
done
chown root:www-data "$root" "$root/tmp"
chmod 02770 "$root" "$root/tmp"
say "$root and tmp/ root:www-data 02770; $made shard dir(s) created (00..ff)"

# 2. Append-only shards. chattr +a on a directory: entries may be added (link()), never removed or renamed.
append_only=1
if ! command -v chattr >/dev/null 2>&1 || ! command -v lsattr >/dev/null 2>&1; then
    append_only=0
    say "WARNING: chattr/lsattr not installed (e2fsprogs): the shards are NOT append-only"
else
    failed=0
    for i in $(seq 0 255); do
        d=$(printf '%s/%02x' "$root" "$i")
        if ! lsattr -d "$d" 2>/dev/null | awk '{print $1}' | grep -q a; then
            chattr +a "$d" 2>/dev/null || failed=$((failed + 1))
        fi
    done
    if [[ $failed -gt 0 ]]; then
        append_only=0
        say "WARNING: chattr +a failed on $failed shard dir(s): this file system does not support it; the shards are NOT append-only"
    else
        say "shards 00..ff are append-only (chattr +a)"
    fi
fi

# 2b. Seal what is stored already (new files: the cron sweep, every minute).
if [[ $append_only -eq 1 ]]; then
    bash "$repo/deploy/staging/seal_file_store.sh" "$root" || die "sealing the stored files failed (see above)"
    say "stored files sealed (chattr +i); cron seals new ones every minute"
fi

# 3. app.env: file_store_dir (kept mode and group; never prints other keys).
(cd "$repo" && FS_ROOT="$root" php -d display_errors=stderr -r '
require "vendor/autoload.php";
$c = CW\Config::load();
$app = CW\Config::parseEnvFile($c->appEnvPath);
$want = getenv("FS_ROOT");
if (($app["file_store_dir"] ?? "") === $want) {
    echo "[install_file_store] app.env: file_store_dir already $want\n";
} else {
    CW\Staff\AppEnvFile::set($c->appEnvPath, ["file_store_dir" => $want]);
    echo "[install_file_store] app.env: file_store_dir = $want\n";
}
new CW\Files\LocalFileStorage($want); // refuses a root a web server or a deploy could reach
') || die "could not set file_store_dir in app.env"

# 4. Self-checks.
#    a) a file in a shard cannot be removed (the probe stays: it is not a sha256 name, so verify_files ignores it)
probe="$root/00/.append-only-probe"
if [[ ! -e $probe ]]; then
    : > "$probe" || die "cannot create $probe"
    chmod 0440 "$probe"
fi
if rm -f "$probe" 2>/dev/null && [[ ! -e $probe ]]; then
    if [[ $append_only -eq 1 ]]; then
        die "self-check failed: rm of $probe succeeded although the shard should be append-only"
    fi
    say "WARNING: rm of a stored file succeeds (no append-only support): files are protected by the code only"
else
    say "self-check: rm of $probe refused (append-only works)"
fi
#    b) a sealed file cannot be rewritten in place: a probe stored the way php-fpm stores (owner www-data, 0440), sealed
#       the way the sweep seals; then www-data's chmod-and-write and root's append must both fail (the probe stays:
#       immutable, and not a sha256 name, so the verifier and the sweep ignore it)
sealprobe="$root/01/.immutable-probe"
if [[ ! -e $sealprobe ]]; then
    runuser -u www-data -- sh -c "umask 027; printf probe > '$sealprobe'" || die "www-data cannot add a file to $root/01"
    chmod 0440 "$sealprobe"
fi
if [[ $append_only -eq 1 ]]; then
    chown root:www-data "$sealprobe" && chmod 0440 "$sealprobe" && chattr +i "$sealprobe" 2>/dev/null || true
fi
if runuser -u www-data -- sh -c "chmod 0640 '$sealprobe' 2>/dev/null && printf FORGED > '$sealprobe'" 2>/dev/null \
   || { printf FORGED >> "$sealprobe"; } 2>/dev/null; then
    if [[ $append_only -eq 1 ]]; then
        die "self-check failed: $sealprobe could be rewritten in place although it is sealed"
    fi
    say "WARNING: a stored file can be rewritten in place (no chattr +i): its content is protected by detection only (verify_files)"
else
    say "self-check: in-place rewrite of a sealed file refused, for www-data and root"
fi
#    c) php-fpm's user can write a copy (tmp/) and read a shard
runuser -u www-data -- test -w "$root/tmp" || die "www-data cannot write $root/tmp"
runuser -u www-data -- test -r "$root/00" || die "www-data cannot read $root/00"
#    d) the code accepts the root
(cd "$repo" && php -d display_errors=stderr -r 'require "vendor/autoload.php"; new CW\Files\LocalFileStorage("/srv/cw-docs"); echo "[install_file_store] CW accepts /srv/cw-docs\n";') \
    || die "CW\\Files\\LocalFileStorage refuses $root"
say "done. Next: php bin/store_file.php --file=<pdf> --kind=duty_evidence, then php bin/verify_files.php (docs/ops.md)"
