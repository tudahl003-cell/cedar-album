#!/bin/bash
# Custom entrypoint: force EXACTLY ONE Apache MPM at runtime, then exec the
# standard base-image foreground runner.
#
# Why: the php:8.3-apache base resolves to a config where Apache boots with two
# MPMs loaded and aborts with "AH00534: More than one MPM loaded", crash-looping.
# Build-time a2enmod/a2dismod and `apache2ctl -t` (a syntax test that never runs
# the MPM init phase) do NOT reliably prevent this, so we enforce the module
# state as the final step, right before Apache starts.
set -e

: "${APACHE_CONFDIR:=/etc/apache2}"
CONFDIR="$APACHE_CONFDIR"
ME="$CONFDIR/mods-enabled"
MA="$CONFDIR/mods-available"

echo "=== [entrypoint] MPM mods-enabled BEFORE fix ==="
ls -1 "$ME" 2>/dev/null | grep -i mpm || echo "(no mpm_*)"
echo "=== [entrypoint] any LoadModule mpm lines under \$CONFDIR ==="
grep -rn "LoadModule[[:space:]]*mpm" "$CONFDIR" 2>/dev/null || echo "(none)"

# Wipe every active MPM, then enable exactly one (prefork = safe with mod_php).
rm -f "$ME"/mpm_*.load "$ME"/mpm_*.conf 2>/dev/null || true
if [ -f "$MA/mpm_prefork.load" ]; then
    ln -sf "$MA/mpm_prefork.load" "$ME/mpm_prefork.load"
    [ -f "$MA/mpm_prefork.conf" ] && ln -sf "$MA/mpm_prefork.conf" "$ME/mpm_prefork.conf"
else
    # fall back to whatever MPM is available (prefer event, then worker, then prefork)
    for m in mpm_event mpm_worker mpm_prefork; do
        if [ -f "$MA/$m.load" ]; then
            ln -sf "$MA/$m.load" "$ME/$m.load"
            [ -f "$MA/$m.conf" ] && ln -sf "$MA/$m.conf" "$ME/$m.conf"
            echo "=== [entrypoint] fell back to $m ==="
            break
        fi
    done
fi

echo "=== [entrypoint] MPM mods-enabled AFTER fix ==="
ls -1 "$ME" 2>/dev/null | grep -i mpm || echo "(no mpm_*)"
echo "=== [entrypoint] loaded MPM module files (runtime truth) ==="
grep -rh "LoadModule[[:space:]]*mpm" "$ME"/*.load 2>/dev/null || echo "(none found in mods-enabled)"

# Hand off to the base image's apache2-foreground (it sources envvars, makes
# run/lock dirs, and `exec apache2 -DFOREGROUND`).
exec /usr/local/bin/apache2-foreground "$@"
