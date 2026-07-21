FROM php:8-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

COPY admin.php /var/www/html/admin.php
COPY index.php /var/www/html/index.php
COPY includes/ /var/www/html/includes/
COPY assets/ /var/www/html/assets/
COPY seed/ /seed/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/security-headers.conf /etc/apache2/conf-enabled/security-headers.conf

RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
