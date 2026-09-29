# QueryDeck — production image (Apache + PHP 8.3)
FROM php:8.3-apache

# System libs for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev libsqlite3-dev unzip gnupg curl \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite zip intl opcache \
    && rm -rf /var/lib/apt/lists/*

# --- Optional: SQL Server support (Microsoft ODBC driver + pdo_sqlsrv) ----------
# Build with:  docker build --build-arg WITH_SQLSRV=1 .
ARG WITH_SQLSRV=0
RUN if [ "$WITH_SQLSRV" = "1" ]; then \
      curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft.gpg && \
      echo "deb [arch=amd64 signed-by=/usr/share/keyrings/microsoft.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" > /etc/apt/sources.list.d/mssql.list && \
      apt-get update && ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev && \
      pecl install pdo_sqlsrv && docker-php-ext-enable pdo_sqlsrv && rm -rf /var/lib/apt/lists/*; \
    fi

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/querydeck.ini
COPY deploy/apache/querydeck.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers

WORKDIR /var/www/querydeck
COPY . .
RUN rm -f .env && chown -R www-data:www-data storage && chmod -R 770 storage

COPY deploy/docker/entrypoint.sh /usr/local/bin/querydeck-entrypoint
RUN chmod +x /usr/local/bin/querydeck-entrypoint

EXPOSE 80
ENTRYPOINT ["querydeck-entrypoint"]
CMD ["apache2-foreground"]
