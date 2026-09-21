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

# Point Apache at public/ (hand-written conf -> no sed side effects, single MPM).
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork 2>/dev/null || true \
    && printf '%s\n' \
        '<VirtualHost *:80>' \
        '    ServerName localhost' \
        '    DocumentRoot /var/www/html/public' \
        '    <Directory /var/www/html/public>' \
        '        Options -Indexes +FollowSymLinks' \
        '        AllowOverride All' \
        '        Require all granted' \
        '    </Directory>' \
        '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
        '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
        '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf \
    && chmod -R a+rx /var/www/html/public \
    && chown -R www-data:www-data /var/www/html
