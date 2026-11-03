FROM php:8.3-fpm-alpine
RUN apk add --no-cache ca-certificates libcurl oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev oniguruma-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring curl \
    && apk del .build-deps
WORKDIR /srv/backend
COPY . /srv/backend
COPY docker-entrypoint.sh /usr/local/bin/puffsqueeze-entrypoint
RUN printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\npost_max_size=12M\nupload_max_filesize=8M\n' > /usr/local/etc/php/conf.d/puffsqueeze.ini
ENTRYPOINT ["sh", "/usr/local/bin/puffsqueeze-entrypoint"]
CMD ["php-fpm"]
