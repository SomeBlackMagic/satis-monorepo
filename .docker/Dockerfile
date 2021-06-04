FROM alpine:3.9 AS build
RUN apk add --no-cache --upgrade composer
WORKDIR /app
COPY . /app

RUN set -eux; \
  composer install \
    --no-interaction \
    --no-ansi \
    --no-scripts \
    --no-plugins \
    --no-dev \
    --prefer-dist \
    --no-progress \
    --no-suggest \
    --classmap-authoritative

FROM alpine:3.9
MAINTAINER https://github.com/SomeBlackMagic/satis-monorepo
RUN set -eux; \
  apk add --no-cache --upgrade \
    bash \
    curl \
    git \
    subversion \
    mercurial \
    openssh \
    openssl \
    zip \
    unzip \
    zlib-dev \
    libzip-dev \
    php7 \
    php7-zip \
    php7-json \
    php7-ctype \
    php7-curl \
    php7-dom \
    php7-fileinfo \
    php7-ftp \
    php7-iconv \
    php7-json \
    php7-mysqlnd \
    php7-openssl \
    php7-pdo \
    php7-phar \
    php7-posix \
    php7-session \
    php7-simplexml \
    php7-sodium \
    php7-sqlite3 \
    php7-tokenizer \
    php7-xml \
    php7-xmlreader \
    php7-xmlwriter \
    php7-mbstring
ENV COMPOSER_HOME /composer
COPY php-cli.ini /usr/local/etc/php/
COPY --from=build /app /app
WORKDIR /build

#ENTRYPOINT ["/app/.docker/docker-entrypoint.sh"]
CMD ["php", "/app/bin/satis", "--ansi", "-vvv", "build", "/build/config/satis.json", "/build/output"]
