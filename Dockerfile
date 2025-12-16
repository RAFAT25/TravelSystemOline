FROM php:8.1-apache

# تثبيت مكتبة تطوير PostgreSQL (libpq-dev) اللازمة لتفعيل امتدادات pgsql و pdo_pgsql
# Install system dependencies including libpq-dev and zip (for Composer)
RUN apt-get update && apt-get install -y libpq-dev zip unzip

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo_mysql pdo_pgsql pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory to standard Apache root
WORKDIR /var/www/html

# Copy application files
COPY . .

# Run Composer Install
RUN composer install --no-dev --optimize-autoloader

# Change DocumentRoot to /var/www/html/public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Expose port and start Apache
EXPOSE 80
CMD ["apache2-foreground"]
