FROM php:8.3-apache

# zip extension -> ZipArchive (dl.php rebuilds a fresh, unique-hash zip per download)
# php:8.3-apache does not ship libzip-dev; install it first or the ext build fails.
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

# Full app layout under the web root. PHP entry points live in public/ and
# reference ../lib.php and ../src/ via __DIR__, so keep that structure.
COPY . /var/www/html/

# robots.txt: keep crawlers off (anti-bot layer)
RUN printf 'User-agent: *\nDisallow: /\n' > /var/www/html/public/robots.txt

# Point Apache at public/. Remove ALL MPM load symlinks then enable exactly one
# (the base image can leave >1 MPM enabled -> "AH00534 more than one MPM loaded").
# configtest at build time so a bad config fails the build, not a 502 at runtime.
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
    && a2enmod mpm_event \
    && sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && chmod -R a+rx /var/www/html/public \
    && chown -R www-data:www-data /var/www/html \
    && apache2ctl configtest
