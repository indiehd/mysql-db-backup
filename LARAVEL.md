# Laravel Integration Guide

This guide shows how to integrate MySQL Database Backup into a Laravel Sail project.

## Overview

The package integrates seamlessly with Laravel Sail by:
- Installing via Composer as a regular dependency
- Using the existing Laravel app container (no separate Dockerfile needed)
- Sharing the Laravel database credentials (no separate backup user)
- Running backups via a simple docker-compose service

## Installation

### 1. Install Package

```bash
composer require indiehd/mysql-db-backup
```

### 2. Add Backup Service to docker-compose.yml

Add this service to your existing `docker-compose.yml`:

```yaml
services:
    # ... your existing services (laravel.test, mysql, etc.) ...

    mysql-backup:
        image: custom-laravel-sail-app  # Or your Laravel app image name
        working_dir: /var/www/html
        entrypoint: []
        command: vendor/bin/mysql-db-backup
        environment:
            DB_HOST: mysql                # Your MySQL container name
            DB_USERNAME: ${DB_USERNAME}   # Reuses Laravel's credentials
            DB_PASSWORD: ${DB_PASSWORD}
            BACKUP_DIR: /backups
        volumes:
            - '.:/var/www/html'           # Same volume as Laravel app
            - './backups:/backups'
        networks:
            - sail                        # Same network as your services
        depends_on:
            - mysql
        profiles:
            - backup                      # Only runs when explicitly called
```

**Note:** Replace `custom-laravel-sail-app` with your actual Laravel app image name (check your docker-compose.yml).

### 3. That's It!

No additional configuration needed. The backup service will:
- ✅ Use your existing database credentials from `.env`
- ✅ Access the MySQL container via the Docker network
- ✅ Store backups in `./backups/` directory
- ✅ Skip system databases automatically

## Usage

### Run One-Time Backup

```bash
docker compose run --rm mysql-backup
```

**Expected output:**
```
Skipping system database: information_schema
Processing database: laravel
The database "laravel" was dumped successfully to /backups/laravel/202602271445.sql
File was gzipped successfully to /backups/laravel/202602271445.sql.gz
Skipping system database: mysql
Skipping system database: performance_schema
Skipping system database: sys
Processing database: testing
The database "testing" was dumped successfully to /backups/testing/202602271445.sql
File was gzipped successfully to /backups/testing/202602271445.sql.gz
```

### Schedule with Cron

Add to your server's crontab:

```bash
crontab -e
```

```cron
# Daily backups at 2 AM
0 2 * * * cd /path/to/laravel/project && docker compose run --rm mysql-backup >> /var/log/mysql-backup.log 2>&1

# Weekly cleanup - keep last 30 days
0 3 * * 0 find /path/to/laravel/project/backups -name "*.sql.gz" -mtime +30 -delete
```

### Check Backups

```bash
ls -lh backups/
```

**Directory structure:**
```
backups/
├── laravel/
│   ├── 202602271400.sql.gz
│   └── 202602271500.sql.gz
└── testing/
    └── 202602271400.sql.gz
```

## Features

### Intelligent Deduplication

The tool automatically detects when database content hasn't changed:

```bash
# First run - creates backup
docker compose run --rm mysql-backup
# The database "laravel" was dumped successfully...
# File was gzipped successfully...

# Second run (no changes) - skips backup
docker compose run --rm mysql-backup
# More than one backup exists; checking hashes...
# This dump matches the previous dump exactly; discarding this backup
```

### System Database Filtering

Automatically skips MySQL system databases:
- `mysql`
- `information_schema`
- `performance_schema`
- `sys`

### Automatic Compression

All backups are automatically gzipped, saving significant disk space.

## Security Considerations

### Using Root Credentials

This guide recommends using Laravel's existing database credentials for backups. This is acceptable when:

✅ **Single-admin environment** - You're the only person with server access
✅ **Dedicated infrastructure** - Server is dedicated to this application
✅ **No external access** - MySQL not exposed to the internet
✅ **Credentials co-located** - Both sets of credentials in same `.env` file

### When to Use Dedicated Credentials

Consider creating a separate backup user if:

❌ **Multiple admins/developers** have access
❌ **Shared hosting** environment
❌ **External backup services** are used
❌ **Compliance requirements** (SOC 2, PCI, etc.)

To create a dedicated backup user:

```sql
CREATE USER 'backup'@'%' IDENTIFIED BY 'secure_password';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON *.* TO 'backup'@'%';
GRANT SELECT ON mysql.proc TO 'backup'@'%';
FLUSH PRIVILEGES;
```

Then update your `.env`:
```bash
BACKUP_DB_USERNAME=backup
BACKUP_DB_PASSWORD=secure_password
```

And docker-compose.yml:
```yaml
environment:
    DB_USERNAME: ${BACKUP_DB_USERNAME}
    DB_PASSWORD: ${BACKUP_DB_PASSWORD}
```

## Advantages of This Approach

### No Separate Dockerfile Needed
- ✅ Uses your existing Laravel app image
- ✅ All dependencies already installed
- ✅ No duplicate Docker builds

### Simple Configuration
- ✅ Reuses existing database credentials
- ✅ No new environment variables (unless you want dedicated credentials)
- ✅ Works immediately after `composer install`

### Native Docker Integration
- ✅ Part of your existing docker-compose stack
- ✅ Uses same network as your services
- ✅ Profile-based execution (doesn't start with `docker compose up`)

### Minimal Overhead
- ✅ Container only runs during backup
- ✅ No long-running backup service
- ✅ Same resource usage as running `artisan` commands

## Troubleshooting

### Package Not Found

If you get "command not found" error:

```bash
# Ensure package is installed
docker compose exec laravel.test composer show indiehd/mysql-db-backup

# Verify executable exists
docker compose exec laravel.test ls -la vendor/bin/mysql-db-backup
```

### Connection Refused

If backup can't connect to MySQL:

```bash
# Check MySQL container name in docker-compose.yml
# Update DB_HOST in mysql-backup service to match
```

### Permission Denied on Backup Directory

```bash
# Create backups directory with correct permissions
mkdir -p backups
chmod 755 backups
```

## Example: Complete Setup

Here's a minimal working example for a fresh Laravel Sail project:

```yaml
# docker-compose.yml
services:
    laravel.test:
        # ... your Laravel service config ...

    mysql:
        # ... your MySQL service config ...

    mysql-backup:
        image: sail-8.3/app  # Or your app image name
        working_dir: /var/www/html
        entrypoint: []
        command: vendor/bin/mysql-db-backup
        environment:
            DB_HOST: mysql
            DB_USERNAME: ${DB_USERNAME}
            DB_PASSWORD: ${DB_PASSWORD}
            BACKUP_DIR: /backups
        volumes:
            - '.:/var/www/html'
            - './backups:/backups'
        networks:
            - sail
        depends_on:
            - mysql
        profiles:
            - backup
```

```bash
# .env
DB_HOST=mysql
DB_USERNAME=sail
DB_PASSWORD=password
# ... other Laravel config ...
```

```bash
# Install and test
composer require indiehd/mysql-db-backup
docker compose run --rm mysql-backup
```

That's it! Your Laravel project now has automated database backups. 🎉
