# Use the official PHP 8.3 image as the base
FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (adjust based on your needs)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory
WORKDIR /app

# Copy the project files
COPY src/ ./src/
#COPY db-backup.ini.example ./db-backup.ini

# Ensure the script has execution permissions
RUN chmod +x /app/src/DbBackup.php

# Command to run the backup script
CMD ["php", "/app/src/DbBackup.php"]
