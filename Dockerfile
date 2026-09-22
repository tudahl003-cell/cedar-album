FROM php:8.3.32-apache

# zip extension -> ZipArchive (dl.php rebuilds a fresh, unique-hash zip per download)
# php:8.3-apache does not ship libzip-dev; install it first or the ext build fails.
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Full app layout under /var/www/html. PHP entry points live in public/ and
# reference ../lib.php and ../src/ via __DIR__, so keep that structure.
COPY . /var/www/html/

# robots.txt: keep crawlers off (anti-bot layer)
RUN printf 'User-agent: *\\nDisallow: /\\n' > /var/www/html/public/robots.txt

# Serve public/ as DocumentRoot (do this BEFORE the MPM validation so
# apache2ctl -t checks the final, real config).
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && chmod -R a+rx /var/www/html/public \
    && chown -R www-data:www-data /var/www/html

# MPM: force EXACTLY ONE MPM. The php:8.3.32-apache tag is re-published in
# place, and a recent re-publish ships two MPM load files active, so Apache
# aborts at boot with "AH00534: More than one MPM loaded" and crash-loops.
# That is why some services from the same commit boot and others crash - it
# is which image variant the build pulled. Wipe every active MPM load file
# and re-enable only mpm_prefork (safe with mod_php). Then validate the full
# final config with apache2ctl -t so a broken config FAILS THE BUILD instead
# of silently crash-looping at runtime.
RUN set -eux; \
    echo "=== MPM mods-available ==="; \
    ls -1 /etc/apache2/mods-available/ | grep -i '^mpm' || true; \
    echo "=== MPM mods-enabled (before) ==="; \
    ls -la /etc/apache2/mods-enabled/ | grep -i mpm || true; \
    echo "=== any LoadModule mpm lines in config ==="; \
    grep -rn "LoadModule[[:space:]]*mpm" /etc/apache2/ 2>/dev/null || true; \
    echo "=== removing all active MPMs ==="; \
    rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf; \
    a2dismod -f mpm_event 2>/dev/null || true; \
    a2dismod -f mpm_worker 2>/dev/null || true; \
    a2dismod -f mpm_prefork 2>/dev/null || true; \
    echo "=== enabling mpm_prefork ==="; \
    a2enmod -f mpm_prefork; \
    echo "=== MPM mods-enabled (after) ==="; \
    ls -la /etc/apache2/mods-enabled/ | grep -i mpm || true; \
    echo "=== apache2ctl -t (must pass or build fails) ==="; \
    apache2ctl -t
