# Test-Image: php:8.3-cli mit denselben Erweiterungen wie die App.
# Bauen: docker build -t klausurwerk-test tests
FROM php:8.3-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip \
    && rm -rf /var/lib/apt/lists/*
