FROM php:8.2-apache

# Enable Apache rewrite (important for most PHP apps)
RUN a2enmod rewrite

# Copy your app into the container
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html