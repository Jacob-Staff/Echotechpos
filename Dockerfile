FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
