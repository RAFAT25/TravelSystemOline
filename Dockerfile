FROM php:8.1-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    mysqli \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    intl \
    bcmath \
    mbstring \
    zip

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
# Ensure AllowOverride is set to All for the public directory to read .htaccess
RUN sed -i '/<Directory \${APACHE_DOCUMENT_ROOT}>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

RUN a2enmod rewrite

# Expose port and start Apache
EXPOSE 80
CMD ["apache2-foreground"]
