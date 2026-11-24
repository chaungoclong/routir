FROM php:8.4-cli-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache bash git unzip linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install opcache pcntl \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS linux-headers \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
        echo 'pcov.enabled=0'; \
    } > /usr/local/etc/php/conf.d/pcov.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN addgroup -g ${GID} dev \
    && adduser -D -u ${UID} -G dev -s /bin/bash dev

WORKDIR /app
USER dev

CMD ["bash"]
