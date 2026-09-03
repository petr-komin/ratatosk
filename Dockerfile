FROM php:8.3-fpm-alpine

# ffmpeg je součást image — worker je krátkoběžný proces, ne služba.
# libpq zůstává, build závislosti letí ven, ať image nebobtná.
RUN apk add --no-cache ffmpeg libpq \
 && apk add --no-cache --virtual .build-deps postgresql-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
 && apk del .build-deps

COPY docker/php.ini      /usr/local/etc/php/conf.d/ratatosk.ini
COPY docker/www.conf     /usr/local/etc/php-fpm.d/zz-ratatosk.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

# Sezení mimo /tmp, ať přežijí restart kontejneru (viz volume v compose).
RUN mkdir -p /var/lib/php/sessions && chown www-data:www-data /var/lib/php/sessions

EXPOSE 9000
CMD ["php-fpm", "-F"]
