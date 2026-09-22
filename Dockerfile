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
RUN printf 'User-agent: *\nDisallow: /\n' > /var/www/html/public/robots.txt

# Serve public/ as DocumentRoot.
# MPM: force EXACTLY ONE MPM. The php:8.3.32-apache tag is re-published in
# place, and a recent re-publish ships two MPM load files active, so Apache
# aborts at boot with "AH00534: More than one MPM loaded" and crash-loops.
# That is why some services from the same commit boot and others crash —
# it's which image variant the build pulled. Disabling every MPM and enabling
# only mpm_prefork is idempotent and immune to base-image state.
RUN a2dismod -f mpm_event mpm_worker mpm_prefork 2>/dev/null; \
    a2enmod -f mpm_prefork
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && chmod -R a+rx /var/www/html/public \
    && chown -R www-data:www-data /var/www/html
