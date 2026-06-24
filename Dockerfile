FROM php:8.2-apache

RUN apt-get update && apt-get install -y 
libpq-dev 
libmariadb-dev 
pkg-config 
&& docker-php-ext-install pdo pdo_mysql mysqli pdo_pgsql pgsql 
&& apt-get clean 
&& rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html
