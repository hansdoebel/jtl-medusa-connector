FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev unzip git \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize && mkdir -p var/log var/cache && chmod -R a+rX /app && chown -R www-data:www-data var
USER www-data
EXPOSE 8080
# Development/staging server. Put HTTPS termination in front; see README for production requirements.
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
