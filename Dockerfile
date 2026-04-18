FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    docker.io \
    docker-compose \
    git \
    nodejs \
    npm \
    openssh-client \
    unzip \
    libsqlite3-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl pdo_sqlite zip \
    && npm install -g @openai/codex \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1

CMD ["sleep", "infinity"]
