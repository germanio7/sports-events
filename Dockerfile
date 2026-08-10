FROM php:8.3-fpm

# Install common PHP extension dependencies, Node.js and other tools
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    zlib1g-dev \
    libzip-dev \
    unzip \
    curl \
    libsqlite3-dev \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions for SQLite
RUN docker-php-ext-install pdo_sqlite

# Set the working directory
WORKDIR /var/www

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Copy composer and package files first for better caching
COPY composer.json composer.lock package.json package-lock.json ./

# Copy application code (excluding vendor via .dockerignore)
COPY . .

# Install dependencies and build assets
RUN rm -rf vendor/ composer.lock \
    && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
    && npm ci && npm run build

# Set correct permissions for Laravel
RUN mkdir -p /var/www/storage /var/www/bootstrap/cache \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Configure PHP-FPM to listen on all interfaces (required for Docker networking)
RUN sed -i 's/^;\?listen = .*/listen = 0.0.0.0:9000/' /usr/local/etc/php-fpm.d/www.conf

# Expose port 9000 and set the default command to run php-fpm
EXPOSE 9000
CMD ["php-fpm"]
