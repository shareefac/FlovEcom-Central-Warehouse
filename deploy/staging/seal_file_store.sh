#!/usr/bin/env bash
# Seals the newly stored files of the CW document store on the STAGING server (docs/decisions.md I23, I36; docs/ops.md
# "The document store"). LocalFileStorage links a file into its shard owned by the process that stored it (php-fpm's
# www-data, or root for bin/store_file.php), mode 0440: its owner could chmod it back and rewrite it in place, because
# `chattr +a` on the shard stops rm and mv only. This sweep takes every stored file (a sha256 name in a shard 00..ff)
# that is not immutable yet, makes it root:www-data 0440 and `chattr +i`: from then on nobody, root included, can change,
# remove or rename it until the flag is taken off. Until a file is sealed (at most a minute), and for root, a change is
# DETECTED (FileStore::read and bin/verify_files.php re-hash), not prevented. The Object Lock bucket replaces all of
# this before live.
#
# Run by cron every minute as root (deploy/staging/cw-staging.cron) and once by install_file_store.sh. Idempotent; silent
# when nothing is new; exits 0 when the store is not installed (yet).
#   bash deploy/staging/seal_file_store.sh [root]      (default /srv/cw-docs; a test passes a scratch directory)
# Exit codes: 0 ok · 1 a file could not be sealed · 3 cannot run (not root, no chattr/lsattr)
set -euo pipefail

root=${1:-/srv/cw-docs}
log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) seal_file_store [$root] $*"; }

[[ $(id -u) -eq 0 ]] || { log "cannot run: not root" >&2; exit 3; }
[[ -d $root ]] || exit 0
if ! command -v chattr >/dev/null 2>&1 || ! command -v lsattr >/dev/null 2>&1; then
    log "cannot run: chattr/lsattr not installed (e2fsprogs)" >&2
    exit 3
fi
getent group www-data >/dev/null || { log "cannot run: group www-data is missing" >&2; exit 3; }

shopt -s nullglob
shards=("$root"/[0-9a-f][0-9a-f])
[[ ${#shards[@]} -gt 0 ]] || exit 0

sealed=0
failed=0
# lsattr on a directory lists its entries with their flags: "<flags> <path>". Names are sha256 hex, never spaces.
while read -r flags path; do
    name=${path##*/}
    [[ $name =~ ^[0-9a-f]{64}$ ]] || continue
    [[ $flags == *i* ]] && continue
    [[ -f $path && ! -L $path ]] || continue
    if chown root:www-data "$path" && chmod 0440 "$path" && chattr +i "$path"; then
        sealed=$((sealed + 1))
    else
        failed=$((failed + 1))
        log "FAILED to seal $path" >&2
    fi
done < <(lsattr "${shards[@]}" 2>/dev/null || true)

if [[ $sealed -gt 0 || $failed -gt 0 ]]; then
    log "sealed=$sealed failed=$failed"
fi
[[ $failed -eq 0 ]]
