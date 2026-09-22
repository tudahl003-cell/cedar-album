#!/bin/bash
# Custom entrypoint: force EXACTLY ONE Apache MPM at runtime, log the full
# runtime state (so we can see where a rogue second MPM comes from), then exec
# the base image's foreground runner.
#
# Background: base php:8.3-apache boots with two MPMs loaded and aborts with
# "AH00534: More than one MPM loaded". `apache2 -t` (config syntax test) does
# NOT run the MPM post-config check, so it reports "Syntax OK" even with two
# MPMs; only the real `apache2 -DFOREGROUND` startup fails. So we enforce the
# module state here, at runtime, as the final authority, and log everything.
# (No `set -e`: diagnostics must never abort before we hand off.)

: "${APACHE_CONFDIR:=/etc/apache2}"
CONFDIR="$APACHE_CONFDIR"
ME="$CONFDIR/mods-enabled"
MA="$CONFDIR/mods-available"

echo "=== [entry] APACHE_CONFDIR=$CONFDIR ==="
echo "=== [entry] apache2 -V (real config path) ==="
apache2 -V 2>&1 | head -20
echo "=== [entry] full mods-enabled listing ==="
ls -la "$ME" 2>/dev/null || echo "(no mods-enabled dir)"
echo "=== [entry] mpm symlinks in mods-enabled (BEFORE) ==="
ls -la "$ME" 2>/dev/null | grep -i mpm || echo "(no mpm_*)"
echo "=== [entry] every LoadModule mpm directive under \$CONFDIR (BEFORE) ==="
grep -rn "LoadModule[[:space:]]*mpm" "$CONFDIR" 2>/dev/null || echo "(none)"

# Wipe every active MPM, then enable exactly one (prefork = safe with mod_php).
rm -f "$ME"/mpm_*.load "$ME"/mpm_*.conf 2>/dev/null || true
if [ -f "$MA/mpm_prefork.load" ]; then
    ln -sf "$MA/mpm_prefork.load" "$ME/mpm_prefork.load"
    [ -f "$MA/mpm_prefork.conf" ] && ln -sf "$MA/mpm_prefork.conf" "$ME/mpm_prefork.conf"
else
    for m in mpm_event mpm_worker mpm_prefork; do
        if [ -f "$MA/$m.load" ]; then
            ln -sf "$MA/$m.load" "$ME/$m.load"
            [ -f "$MA/$m.conf" ] && ln -sf "$MA/$m.conf" "$ME/$m.conf"
            echo "=== [entry] fell back to $m ==="
            break
        fi
    done
fi

echo "=== [entry] mpm symlinks in mods-enabled (AFTER) ==="
ls -la "$ME" 2>/dev/null | grep -i mpm || echo "(no mpm_*)"
echo "=== [entry] every LoadModule mpm directive under \$CONFDIR (AFTER) ==="
grep -rn "LoadModule[[:space:]]*mpm" "$CONFDIR" 2>/dev/null || echo "(none)"
echo "=== [entry] apache2 -t at RUNTIME ==="
apache2 -t 2>&1
echo "=== [entry] loaded MPM modules (apache2ctl -M | grep mpm) ==="
apache2ctl -M 2>/dev/null | grep -i mpm || echo "(apache2ctl -M found no mpm)"

# Bind Apache to the port the Railway proxy forwards to ($PORT, 8080 on
# Railway), NOT the base image default of 80. If we only Listen 80 the
# proxy has nothing to reach and every request is a 502.
P="${PORT:-8080}"
if [ -f "$CONFDIR/ports.conf" ]; then
  sed -i "s/^Listen[[:space:]]\{1,\}80\b/Listen $P/" "$CONFDIR/ports.conf"
fi
# Repoint the vhost(s) to the same port so the :$P request matches the
# public/ DocumentRoot vhost instead of falling through to the default.
if [ -d "$CONFDIR/sites-available" ]; then
  find "$CONFDIR/sites-available" -maxdepth 1 -name '*.conf' \
    -exec sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$P>/" {} +
fi
echo "=== [entry] PORT=$P; ports.conf now: ==="
grep -nE '^Listen' "$CONFDIR/ports.conf" 2>/dev/null || echo "(no Listen lines)"
echo "=== [entry] vhost vhost port now: ==="
grep -rnE 'VirtualHost' "$CONFDIR/sites-available" 2>/dev/null || echo "(no vhosts)"

echo "=== [entry] handing off to apache2-foreground ==="
# Do NOT forward "$@". The base image's CMD ["apache2-foreground"] passes
# "apache2-foreground" into this script as $1, and forwarding it again yields
# `apache2 -DFOREGROUND apache2-foreground` -> apache2 prints the usage text
# and exits -> crash loop. Hardcode the foreground runner with no args.
exec /usr/local/bin/apache2-foreground
