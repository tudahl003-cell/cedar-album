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

# Serve public/ as DocumentRoot
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
    && chmod -R a+rx /var/www/html/public \
    && chown -R www-data:www-data /var/www/html

# Custom entrypoint that forces exactly ONE Apache MPM right before Apache
# starts. The base php:8.3-apache resolves to a config that boots with two MPMs
# loaded and aborts with "AH00534: More than one MPM loaded", crash-looping.
# Build-time a2enmod/a2dismod and `apache2ctl -t` (a syntax test that never runs
# the MPM init phase) do NOT reliably prevent it, so we enforce the module state
# as the final step, in the entrypoint, and log the exact runtime state.
RUN cp /var/www/html/entrypoint.sh /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
