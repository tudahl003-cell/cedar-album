# Apache + PHP 8.3 — pool member for the Adobe-style download flow.
# Public domain: *.up.railway.app (auto-allocated).
FROM php:8.3-apache

RUN docker-php-ext-install gd opcache
# Copy public/ -> /var/www/html.
COPY public/ /var/www/html/
COPY lib.php /var/www/lib.php
COPY routes.php /var/www/routes.php

# Anti-hotlink / anti-spoof headers on the /src/ files.
RUN { \
      echo "<If \"-f $REQUEST_FILENAME\">"; \
      echo "  <FilesMatch \"\\.(php|htaccess)$\">"; \
      echo "    Require all denied"; \
      echo "  </FilesMatch>"; \
      echo "  <Headers always set Header X-Frame-OPTIONS \"DENY\">"; \
      echo "  <Headers always set Header X-CONTENT-TYPE-OPTIONS \"nosniff\">"; \
      echo "  <Headers always set Header X-XSS-PROTECTION \"1; mode=block\">"; \
      echo "  <Headers always set Header Content-Security-Policy \"default-src 'none'; frame-ancestors 'none'; base-uri 'none'\">"; \
      echo "</If>"; \
    } > /etc/apache2/conf-enabled/src-sec.conf

# Ensure a single MPM (event) is active: the base image may enable a default
# MPM (prefork); loading a second one -> AH00534 "More than one MPM loaded"
# and apache exits on boot. Disable all MPM modules, then enable exactly one.
RUN a2dismod -f mpm_prefork mpm_worker mpm_event 2>/dev/null || true; \
    a2enmod event 2>/dev/null || true; \
    a2enmod rewrite headers

# Keep Apache's default DocumentRoot (/var/www/html) and log access.
# Listen on 8080 (Railway assigns a public port).
RUN sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/Listen 80$/Listen 8080/; s/:80>/:8080>/g' /etc/apache2/sites-available/000-default.conf

EXPOSE 8080
CMD ["apache2-foreground"]
