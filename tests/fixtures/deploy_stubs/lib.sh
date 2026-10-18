# The stub commands of tests/Unit/ProductionDeployBehaviourTest.php (sourced by each one in bin/). deploy/production's scripts
# run with bin/ first on PATH, against the temporary tree CW_DEPLOY_ROOT names. Each stub records its call in
# $CW_STUB_DIR/calls.log (paths shown without the tree's prefix, as the box would see them), answers from
# $CW_STUB_DIR/answers/<name> (the scenario's; a default otherwise) and keeps what it must remember (php-fpm running, the sites
# enabled, the firewall) in $CW_STUB_DIR/state. None reaches a service, the network or a database.
: "${CW_STUB_DIR:?the deploy stubs run only under tests/Unit/ProductionDeployBehaviourTest.php}"
: "${CW_DEPLOY_ROOT:?the deploy stubs run only against a test tree}"
A=$CW_STUB_DIR/answers
S=$CW_STUB_DIR/state
R=${CW_DEPLOY_ROOT%/}

log() {
    local line="$*"
    [[ -z $R ]] || line=${line//"$R"/}
    printf '%s\n' "$line" >> "$CW_STUB_DIR/calls.log"
}

# answer NAME [DEFAULT]: the scenario's answer, or the default.
answer() {
    if [[ -f $A/$1 ]]; then cat "$A/$1"; else printf '%s' "${2-}"; fi
}

# real NAME: the system's own rsync, flock or getent (CW_STUB_SYSPATH: a directory only the stubs look in; the scripts'
# PATH never holds them, so a missing stub is "not found", never the real command).
real() {
    PATH=$CW_STUB_SYSPATH command -v "$1" || { echo "stub: no real $1 on this machine" >&2; exit 127; }
}
