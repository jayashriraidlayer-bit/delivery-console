FROM php:8.2-apache

# PostgreSQL client libraries + the PHP extension this app needs (pdo_pgsql).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Apache serves the project root directly (delivery-ops-map.html, driver.html, api/, etc.)
COPY . /var/www/html/

# The host assigns the external port via $PORT at container start (not
# build time), so Apache's listen port has to be set in an entrypoint
# script rather than baked into the config with a build-time sed.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
