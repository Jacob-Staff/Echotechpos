FROM php:8.2-apache

# =========================================================
# SYSTEM PACKAGES + CHROMIUM
# =========================================================
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        unzip \
        zip \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        chromium \
        ca-certificates \
        fonts-liberation \
        fonts-dejavu \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install \
        mysqli \
        pdo \
        pdo_mysql \
        zip \
        gd \
    && rm -rf /var/lib/apt/lists/*


# =========================================================
# APACHE CONFIGURATION
# =========================================================
RUN a2enmod rewrite


# =========================================================
# COMPOSER
# =========================================================
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer


# =========================================================
# APPLICATION DIRECTORY
# =========================================================
WORKDIR /var/www/html


# =========================================================
# COMPOSER DEPENDENCIES
# =========================================================
# Copy these first so Docker can cache Composer installation.
COPY composer.json composer.lock ./


# =========================================================
# INSTALL DOMPDF AND OTHER PHP DEPENDENCIES
# =========================================================
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader


# =========================================================
# COPY APPLICATION
# =========================================================
COPY . /var/www/html/


# =========================================================
# PERMISSIONS
# =========================================================
RUN chown -R www-data:www-data /var/www/html


# =========================================================
# APACHE
# =========================================================
EXPOSE 80
