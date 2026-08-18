FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

# Set login_inc.php as the primary landing page
RUN echo "DirectoryIndex login_inc.php index.php" >> /etc/apache2/apache2.conf

COPY . /var/www/html/

EXPOSE 80
