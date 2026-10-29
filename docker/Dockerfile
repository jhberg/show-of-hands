# Development image: PHP 8.3 with the extensions the production web space provides.
FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev unzip git \
    && docker-php-ext-install intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer HOME=/tmp
WORKDIR /app
