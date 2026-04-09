FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress

FROM php:8.2-cli

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .

ENV REDIS_DSN=tcp://redis:6379

CMD ["php", "bin/console", "app:event-loader"]