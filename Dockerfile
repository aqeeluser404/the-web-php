# Use official PHP image with Apache
FROM php:8.2-apache

# Set the working directory inside the container
WORKDIR /var/www/html

# Copy project files into the container
COPY . .

# Install dependencies using Composer
RUN apt-get update && \
    apt-get install -y git unzip && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    composer install

# Enable Apache mod_rewrite for URL handling
RUN a2enmod rewrite

# Copy Apache configuration and environment files
COPY .htaccess /var/www/html/.htaccess

# Expose port 80 for incoming requests
EXPOSE 80

# Start Apache server
CMD ["apache2-foreground"]