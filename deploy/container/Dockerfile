# syntax=docker/dockerfile:1
FROM php:8.4-fpm-trixie AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev postgresql-client-17 libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev \
    unzip util-linux ca-certificates curl $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && for ext in pdo_pgsql pgsql gd zip pcntl opcache mbstring; do php -r "exit(extension_loaded('$ext')||('$ext'==='opcache'&&extension_loaded('Zend OPcache'))?0:1);" || docker-php-ext-install -j"$(nproc)" "$ext" || exit 1; done \
    && pecl install redis-6.2.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
RUN php -r 'foreach (["pdo_pgsql","redis","mbstring","gd","sodium","pcntl","zip"] as $e) if (!extension_loaded($e)) exit(1);'

FROM php-base AS verified
ARG CE_RELEASE_VERSION
ARG CE_RELEASE_SHA256
COPY core /verify/core
COPY deploy/verify.php /verify/deploy/verify.php
COPY deploy/container/artifacts/release.zip /artifact/release.zip
COPY deploy/container/artifacts/release.zip.sig /artifact/release.zip.sig
COPY deploy/container/artifacts/release.pub /artifact/release.pub
RUN test -n "$CE_RELEASE_VERSION" && test "${#CE_RELEASE_SHA256}" = 64 \
    && php /verify/deploy/verify.php /artifact/release.zip /artifact/release.pub "$CE_RELEASE_SHA256" "$CE_RELEASE_VERSION" \
    && unzip -q /artifact/release.zip -d /opt/dist

FROM php-base AS app
COPY --from=verified /opt/dist/cajeerengine/ /opt/ce-image/
COPY --from=verified /artifact/release.pub /opt/ce-release.pub
COPY deploy/container/gate.php deploy/container/bootstrap.php deploy/container/runtime.php deploy/container/init.sh deploy/container/loop.sh /opt/ce-container/
COPY deploy/container/php-fpm.conf /usr/local/etc/php-fpm.d/zzzz-forum.conf
RUN chmod 0755 /opt/ce-container/*.sh && mkdir -p /srv/forum /run/ce-keys \
    && printf 'expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\nupload_max_filesize=25M\npost_max_size=32M\nmemory_limit=256M\nopcache.enable=1\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/forum.ini
WORKDIR /srv/forum
USER 33:33
CMD ["php-fpm", "-F"]

# Separate operator image: AWS CLI multipart transport is never needed in PHP-FPM.
FROM app AS backup
USER 0:0
RUN apt-get update && apt-get install -y --no-install-recommends awscli \
    && rm -rf /var/lib/apt/lists/*
COPY deploy/container/backup.sh /opt/ce-container/backup.sh
RUN chmod 0755 /opt/ce-container/backup.sh
USER 33:33

FROM nginx:1.28-alpine AS gateway
RUN apk add --no-cache openssl
COPY deploy/container/nginx.conf.template /opt/forum/nginx.conf.template
COPY deploy/container/gateway.sh /opt/forum/gateway.sh
RUN chmod 0755 /opt/forum/gateway.sh
ENTRYPOINT ["/opt/forum/gateway.sh"]
