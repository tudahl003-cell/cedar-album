FROM php:8.3-apache

# zip extension -> ZipArchive (dl.php rebuilds a fresh, unique-hash zip per download)
RUN docker-php-ext-install zip

# Copy app. public/ is served by Apache; lib.php + src/ stay OUTSIDE the web root.
COPY . /var/www/html

# Point Apache at public/
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# robots.txt: keep crawlers off (anti-bot layer)
RUN printf 'User-agent: *\nDisallow: /\n' > /var/www/html/public/robots.txt

# Write perms for any temp data (rate-limit/alert dedup use sys_get_temp_dir(), but be safe)
RUN chown -R www-data:www-data /var/www/html
