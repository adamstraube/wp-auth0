FROM php:7.4-cli-alpine

# Setup essentials for building PHP extensions
RUN apk update && \
    apk add git \
            npm \
            gcc \
            autoconf \
            make \
            musl-dev \
            oniguruma-dev \
            gnupg

# Build necessary PHP extensions from source
RUN pecl install pcov; exit 0

# Enable necessary PHP extensions
RUN docker-php-ext-enable pcov
RUN docker-php-ext-install mysqli

# Setup Composer for installing dependencies
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

ENTRYPOINT [ "./docker/entrypoint.sh" ]
