FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends lua5.1 mariadb-client curl libzip-dev && docker-php-ext-install pdo_mysql zip && rm -rf /var/lib/apt/lists/*
