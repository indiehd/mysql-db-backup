# Use the official PHP 8.3 image as the base
FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    default-mysql-client \
    gzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install mysqli

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /app

# Copy composer files first for better layer caching
COPY composer.json ./

# Install dependencies (creates autoloader)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application files
COPY bin/ ./bin/
COPY src/ ./src/

# Ensure the executable has proper permissions
RUN chmod +x /app/bin/mysql-db-backup

# Set default backup directory
ENV BACKUP_DIR=/backups

# Create backup directory
RUN mkdir -p /backups

# Run the backup script
CMD ["/app/bin/mysql-db-backup"]
