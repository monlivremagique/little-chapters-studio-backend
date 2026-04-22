FROM ghcr.io/sylius/sylius-php:8.3-alpine

USER root

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pgsql pdo_pgsql

WORKDIR /srv/sylius
