#!/bin/sh
# =============================================================================
# T-6 — Test runner isolation gate for the shared `ecos_dev_test` schema.
#
# THE PROBLEM THIS SOLVES
#
# `tests/TestCase.php` pins the test database in code (all three env sources,
# plus a reset of Laravel's Env repository singleton), so neither
# `docker exec -e DB_DATABASE=…` nor a custom phpunit.xml can redirect a run —
# every agent's suite lands on the same schema. `RefreshDatabase` then runs
# `migrate:fresh`, which DROPS EVERY TABLE. Two agents running concurrently
# therefore destroy each other's schema mid-run, and the victim sees failures
# that look like code defects. Measured cost: one run reported 39 non-passing
# where a clean run of the same suites reported 18.
#
# WHAT THIS GATE DOES
#
#   1. ADVISORY LOCK  — a MySQL named lock, held by a dedicated background
#      session for exactly as long as the suite runs. Atomic, and released
#      automatically by the server if this process dies, so a crashed run can
#      never leave the gate stuck shut.
#   2. UNGATED-RUN DETECTION — scans /proc for phpunit/paratest processes.
#      `ps` is not installed in the runner image, so /proc is the only route.
#      Matching is on TOKEN BASENAME, never substring: a shell whose script
#      merely mentions "phpunit" (this script, for one) must not count as a run.
#   3. DDL / OCCUPANCY DETECTION — inspects information_schema.processlist for
#      live work on the target schema, and separately for the DROP/CREATE/ALTER
#      signature of a `migrate:fresh` in flight.
#
# Checks 2 and 3 exist because OTHER AGENTS DO NOT USE THIS GATE. The lock only
# coordinates gated runs; the scans are what stop this gate from reporting
# "free" while an ungated suite is mid-migration.
#
# WHAT IT DOES NOT DO — stated plainly rather than implied:
# it cannot stop an ungated run from dropping the schema. Only a lock inside
# the harness can do that (see docs/verification/T-6-TEST-RUNNER-ISOLATION.md).
# This gate guarantees that runs launched THROUGH it never collide, and that it
# refuses to start when anything else is already working.
#
# USAGE
#   scripts/test-gate.sh tests/Feature/Operations
#   scripts/test-gate.sh --status            # report occupancy, run nothing
#   GATE_WAIT=600 scripts/test-gate.sh …     # queue instead of refusing
#
# EXIT CODES
#   0   suite ran (its own exit code is propagated)
#   70  gate busy — nothing was run
#   71  gate could not establish the lock (infrastructure fault)
# =============================================================================

set -u

APP_DIR="${APP_DIR:-/var/www/html}"
TEST_DB="${TEST_DB:-ecos_dev_test}"
LOCK_NAME="ecos:testrunner:${TEST_DB}"
GATE_WAIT="${GATE_WAIT:-0}"          # seconds to queue for the lock; 0 = refuse
HOLD_MAX="${HOLD_MAX:-14400}"        # hard ceiling on how long a holder may hold (4h)
POLL_INTERVAL=3
STATE_DIR="${STATE_DIR:-/tmp/ecos-test-gate}"

DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-ecos}"
DB_PASSWORD="${DB_PASSWORD:-}"

mkdir -p "$STATE_DIR"
HOLDER_ID_FILE="$STATE_DIR/holder.$$"
HOLDER_PID=""

sql() {
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" -N -B -e "$1" 2>/dev/null
}

log()  { printf '  %s\n' "$*"; }
fail() { printf '\n[GATE] %s\n' "$*" >&2; }

# ---------------------------------------------------------------------------
# 1. /proc scan — phpunit or paratest running anywhere in this container.
#
# Self and ancestors are excluded: this script is itself a process, and the
# `sh -c` that launched it carries this file's path in its argv. Matching the
# BASENAME of each NUL-separated token (rather than grepping the whole cmdline)
# is what keeps a script that merely mentions the word from matching.
# ---------------------------------------------------------------------------
ancestors() {
    _p=$$
    while [ "$_p" != "0" ] && [ "$_p" != "1" ] && [ -r "/proc/$_p/stat" ]; do
        printf '%s ' "$_p"
        _p=$(awk '{print $4}' "/proc/$_p/stat" 2>/dev/null)
        [ -z "$_p" ] && break
    done
}

scan_phpunit() {
    _excluded=" $(ancestors) "
    _found=""
    for _d in /proc/[0-9]*; do
        _pid=${_d#/proc/}
        case "$_excluded" in *" $_pid "*) continue ;; esac
        [ -r "$_d/cmdline" ] || continue

        # Split argv on NUL and test each token's basename.
        _hit=$(tr '\0' '\n' < "$_d/cmdline" 2>/dev/null | while IFS= read -r _tok; do
            [ -n "$_tok" ] || continue
            case "$(basename "$_tok" 2>/dev/null)" in
                phpunit|paratest) echo yes; break ;;
            esac
        done)

        if [ -n "$_hit" ]; then
            _cmd=$(tr '\0' ' ' < "$_d/cmdline" 2>/dev/null | cut -c1-110)
            _found="${_found}${_pid}|${_cmd}
"
        fi
    done
    printf '%s' "$_found"
}

# ---------------------------------------------------------------------------
# 2. Database occupancy.
# ---------------------------------------------------------------------------
db_active_queries() {
    sql "SELECT COUNT(*) FROM information_schema.processlist
         WHERE db='${TEST_DB}' AND command <> 'Sleep' AND id <> CONNECTION_ID();"
}

db_ddl_in_flight() {
    sql "SELECT COUNT(*) FROM information_schema.processlist
         WHERE db='${TEST_DB}' AND command <> 'Sleep' AND id <> CONNECTION_ID()
           AND (LOWER(info) LIKE 'drop table%'
             OR LOWER(info) LIKE 'create table%'
             OR LOWER(info) LIKE 'alter table%');"
}

db_lock_holder() { sql "SELECT COALESCE(IS_USED_LOCK('${LOCK_NAME}'), 0);"; }

table_count() {
    sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${TEST_DB}';"
}

# ---------------------------------------------------------------------------
# 3. Lock acquisition.
#
# The holder is a background mysql session that prints its own CONNECTION_ID,
# takes the lock, then sleeps. Ownership is then PROVEN by comparing
# IS_USED_LOCK() against that id from a second connection — checking only that
# the lock is held would happily accept somebody else's lock as our own.
# ---------------------------------------------------------------------------
# NOTE ON CALLING CONVENTION: this writes the connection id to a FILE and must be
# called as a plain command, never as `$(acquire_lock)`. Command substitution runs
# it in a subshell, so HOLDER_PID would be set there and lost — leaving the parent
# with nothing to kill and the lock held by an orphaned client for the full sleep.
# That is not hypothetical: it leaked connection 67401 during T-6 proving.
acquire_lock() {
    : > "$HOLDER_ID_FILE"
    # --unbuffered is load-bearing, not decoration. Without it the client holds
    # CONNECTION_ID() in its stdio buffer until the process exits — and this one
    # never exits, it sleeps holding the lock. The id file therefore stayed empty
    # for ever and ownership could never be proven, so the gate failed closed on
    # a free database. Measured: [] buffered vs [67414] unbuffered.
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" -N -B --unbuffered \
        -e "SELECT CONNECTION_ID(); DO GET_LOCK('${LOCK_NAME}', 10); DO SLEEP(${HOLD_MAX});" \
        > "$HOLDER_ID_FILE" 2>/dev/null &
    HOLDER_PID=$!

    _tries=0
    while [ $_tries -lt 20 ]; do
        LOCK_CONN=$(head -1 "$HOLDER_ID_FILE" 2>/dev/null | tr -d '[:space:]')
        if [ -n "$LOCK_CONN" ] && [ "$(db_lock_holder)" = "$LOCK_CONN" ]; then
            return 0
        fi
        _tries=$((_tries + 1)); sleep 1
    done
    kill "$HOLDER_PID" 2>/dev/null
    HOLDER_PID=""
    return 1
}

release_lock() {
    if [ -n "$HOLDER_PID" ]; then
        kill "$HOLDER_PID" 2>/dev/null
        # Settle: the server frees the lock when it notices the connection has
        # gone, a few seconds after the client dies. Without this pause a second
        # gated run started immediately afterwards sees the just-released lock as
        # still held and refuses — the gate blocking itself.
        _s=0
        while [ $_s -lt 6 ]; do
            [ "$(db_lock_holder)" = "0" ] && break
            _s=$((_s + 1)); sleep 1
        done
    fi
    rm -f "$HOLDER_ID_FILE" 2>/dev/null
    HOLDER_PID=""
}

# Reap holder clients orphaned by an earlier crash. A named lock lives as long as
# its CONNECTION does, so an orphaned client keeps the gate shut for HOLD_MAX even
# though nothing is running. Holders are identified by the lock name in their own
# argv, so this can never target an unrelated mysql session.
reap_orphan_holders() {
    _killed=0
    _excluded=" $(ancestors) "
    for _d in /proc/[0-9]*; do
        _pid=${_d#/proc/}
        case "$_excluded" in *" $_pid "*) continue ;; esac
        [ -r "$_d/cmdline" ] || continue
        _cmd=$(tr '\0' ' ' < "$_d/cmdline" 2>/dev/null)
        case "$_cmd" in
            *"GET_LOCK('${LOCK_NAME}'"*)
                kill "$_pid" 2>/dev/null && _killed=$((_killed + 1)) ;;
        esac
    done
    echo "$_killed"
}

trap 'release_lock' EXIT INT TERM

# ---------------------------------------------------------------------------
# Occupancy report.
# ---------------------------------------------------------------------------
report() {
    _procs=$(scan_phpunit)
    _q=$(db_active_queries); _ddl=$(db_ddl_in_flight); _lock=$(db_lock_holder); _tbl=$(table_count)

    printf '\n[GATE] %s @ %s\n' "$TEST_DB" "$DB_HOST"
    log "phpunit/paratest processes : $( [ -n "$_procs" ] && echo "$_procs" | grep -c . || echo 0 )"
    [ -n "$_procs" ] && printf '%s' "$_procs" | while IFS='|' read -r p c; do [ -n "$p" ] && log "    pid $p  $c"; done
    log "active queries on schema   : ${_q:-?}"
    log "migrate:fresh DDL in flight: ${_ddl:-?}"
    log "advisory lock held by       : $( [ "${_lock:-0}" = "0" ] && echo "nobody" || echo "connection ${_lock}" )"
    log "tables present              : ${_tbl:-?}"
    printf '\n'

    if [ -n "$_procs" ] || [ "${_q:-0}" -gt 0 ] || [ "${_ddl:-0}" -gt 0 ] || [ "${_lock:-0}" != "0" ]; then
        return 1
    fi
    return 0
}

busy_reason() {
    _procs=$(scan_phpunit)
    [ -n "$_procs" ] && { echo "an ungated phpunit process is running"; return; }
    [ "$(db_ddl_in_flight)" -gt 0 ] 2>/dev/null && { echo "a migrate:fresh is dropping/creating tables"; return; }
    [ "$(db_active_queries)" -gt 0 ] 2>/dev/null && { echo "another session is querying the schema"; return; }
    [ "$(db_lock_holder)" != "0" ] && { echo "another gated run holds the advisory lock"; return; }
    echo "unknown"
}

# ---------------------------------------------------------------------------
# Entry point.
# ---------------------------------------------------------------------------
if [ "${1:-}" = "--status" ]; then
    report; exit $?
fi

if [ "${1:-}" = "--release" ]; then
    _n=$(reap_orphan_holders)
    printf '[GATE] reaped %s orphaned lock holder(s)\n' "$_n"
    # The server frees a named lock when it notices the CONNECTION has gone, which
    # is not instant after the client dies. One second was measurably too short —
    # IS_USED_LOCK still returned the dead connection id and --release reported
    # failure on a lock it had in fact just freed.
    sleep 4
    report; exit $?
fi

_waited=0
while :; do
    if report >/dev/null 2>&1; then break; fi
    if [ "$_waited" -ge "$GATE_WAIT" ]; then
        report
        fail "BUSY — $(busy_reason). Nothing was run; the schema was not touched."
        fail "Re-run with GATE_WAIT=<seconds> to queue instead of refusing."
        exit 70
    fi
    [ "$_waited" = "0" ] && printf '[GATE] busy (%s) — queueing up to %ss\n' "$(busy_reason)" "$GATE_WAIT"
    sleep "$POLL_INTERVAL"; _waited=$((_waited + POLL_INTERVAL))
done

LOCK_CONN=""
acquire_lock || { fail "could not establish the advisory lock."; exit 71; }
printf '[GATE] acquired %s (connection %s)\n\n' "$LOCK_NAME" "$LOCK_CONN"

cd "$APP_DIR" || exit 71

# `--exec` runs an arbitrary command under the same protection instead of phpunit.
# This exists for the destructive maintenance that must NOT race: `db:wipe`,
# `migrate:fresh`, seeding. Those are precisely the operations that corrupt another
# agent's suite, so they need the lock at least as much as a test run does.
if [ "${1:-}" = "--exec" ]; then
    shift
    printf '[GATE] exec: %s\n\n' "$*"
    "$@"
else
    php vendor/bin/phpunit "$@"
fi
_rc=$?

printf '\n[GATE] released %s\n' "$LOCK_NAME"
exit $_rc
