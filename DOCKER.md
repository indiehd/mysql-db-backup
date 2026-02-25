# Docker Deployment Guide

This guide covers running MySQL Database Backup in a fully containerized environment.

## Quick Start

### Option 1: Using Docker Compose (Recommended)

```bash
# 1. Clone the repository
git clone https://github.com/indieTorrent/mysql-db-backup.git
cd mysql-db-backup
git checkout composer

# 2. Create environment file
cat > .env <<EOF
DB_HOST=localhost
DB_USERNAME=your_mysql_user
DB_PASSWORD=your_mysql_password
HOST_BACKUP_DIR=/path/to/backups
EOF

# 3. Build and run
docker-compose up
```

### Option 2: Using Docker Run

```bash
# Build the image
docker build -t mysql-db-backup -f docker/Dockerfile .

# Run the backup
docker run --rm \
  --network host \
  -e DB_HOST=localhost \
  -e DB_USERNAME=your_user \
  -e DB_PASSWORD=your_password \
  -e BACKUP_DIR=/backups \
  -v /path/to/backups:/backups \
  mysql-db-backup
```

## Network Configuration

### Accessing MySQL on Host Machine

If MySQL is running on the host (most common):

**Linux:**
```bash
docker run --rm \
  --network host \
  -e DB_HOST=localhost \
  ...
```

**macOS/Windows:**
```bash
docker run --rm \
  -e DB_HOST=host.docker.internal \
  ...
```

### Accessing MySQL in Another Container

If MySQL is in a Docker network:

```bash
# Run on the same network as your MySQL container
docker run --rm \
  --network your_mysql_network \
  -e DB_HOST=mysql_container_name \
  ...
```

## Configuration Methods

### Method 1: Environment Variables (Recommended for Docker)

```bash
docker run --rm \
  -e DB_HOST=localhost \
  -e DB_USERNAME=backup_user \
  -e DB_PASSWORD=secret123 \
  -e BACKUP_DIR=/backups \
  -v /opt/backups:/backups \
  mysql-db-backup
```

### Method 2: Config File

```bash
# Create config file
cp db-backup.ini.example db-backup.ini
nano db-backup.ini

# Mount it into the container
docker run --rm \
  -v $(pwd)/db-backup.ini:/app/db-backup.ini:ro \
  -v /opt/backups:/backups \
  mysql-db-backup
```

## Cron Job Setup

### Using Host Cron

Add to crontab (`crontab -e`):

```cron
# Daily at 2 AM
0 2 * * * docker run --rm --network host -e DB_HOST=localhost -e DB_USERNAME=backup -e DB_PASSWORD=secret -e BACKUP_DIR=/backups -v /opt/backups:/backups mysql-db-backup >> /var/log/mysql-backup.log 2>&1
```

### Using Environment File (Cleaner)

Create `/opt/mysql-backup/.env`:
```bash
DB_HOST=localhost
DB_USERNAME=backup_user
DB_PASSWORD=your_password
BACKUP_DIR=/backups
```

Crontab:
```cron
0 2 * * * docker run --rm --network host --env-file /opt/mysql-backup/.env -v /opt/backups:/backups mysql-db-backup >> /var/log/mysql-backup.log 2>&1
```

### Using a Wrapper Script (Best Practice)

Create `/opt/mysql-backup/run-backup.sh`:
```bash
#!/bin/bash
set -e

# Load environment variables
source /opt/mysql-backup/.env

# Run backup
docker run --rm \
  --network host \
  -e DB_HOST="${DB_HOST}" \
  -e DB_USERNAME="${DB_USERNAME}" \
  -e DB_PASSWORD="${DB_PASSWORD}" \
  -e BACKUP_DIR=/backups \
  -v "${HOST_BACKUP_DIR:-/opt/backups}":/backups \
  mysql-db-backup
```

Make executable and add to cron:
```bash
chmod +x /opt/mysql-backup/run-backup.sh
crontab -e
# Add: 0 2 * * * /opt/mysql-backup/run-backup.sh >> /var/log/mysql-backup.log 2>&1
```

## Security Considerations

### Protect Credentials

**Option 1: Environment File with Restricted Permissions**
```bash
# Create .env file
cat > /opt/mysql-backup/.env <<EOF
DB_PASSWORD=your_secret_password
EOF

# Restrict permissions
chmod 600 /opt/mysql-backup/.env
chown mariadb-backup:mariadb-backup /opt/mysql-backup/.env
```

**Option 2: Docker Secrets (Docker Swarm)**
```bash
# Create secret
echo "your_password" | docker secret create db_password -

# Use in docker-compose.yml or swarm service
```

### Read-Only MySQL User

Create a dedicated backup user with minimal permissions:

```sql
CREATE USER 'backup'@'localhost' IDENTIFIED BY 'secure_password';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER ON *.* TO 'backup'@'localhost';
FLUSH PRIVILEGES;
```

## Troubleshooting

### Can't Connect to MySQL

**Error:** `Connect Error (2002) Connection refused`

**Solution:** Check network settings. For MySQL on host:
- Linux: Use `--network host` and `DB_HOST=localhost`
- macOS/Windows: Use `DB_HOST=host.docker.internal`

### Permission Denied on Backup Directory

**Error:** Directory could not be created

**Solution:** Ensure the backup directory has correct permissions:
```bash
mkdir -p /opt/backups
chmod 755 /opt/backups
# If running as specific user:
chown mariadb-backup:mariadb-backup /opt/backups
```

### mysqldump Not Found

**Error:** Command not found

**Solution:** The Dockerfile includes `default-mysql-client`. Rebuild:
```bash
docker build --no-cache -t mysql-db-backup -f docker/Dockerfile .
```

## Building for Production

### Multi-stage Build (Smaller Image)

For production, you can optimize the Dockerfile:

```dockerfile
FROM php:8.3-cli-alpine
RUN apk add --no-cache mysql-client
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
# ... rest of build
```

### Version Tagging

```bash
# Build with version tag
docker build -t mysql-db-backup:2.0.0 -f docker/Dockerfile .
docker tag mysql-db-backup:2.0.0 mysql-db-backup:latest

# Use specific version in cron
docker run --rm mysql-db-backup:2.0.0 ...
```

## Monitoring

### Log Output

```bash
# Run with logging
docker run --rm mysql-db-backup 2>&1 | tee /var/log/mysql-backup-$(date +%Y%m%d).log
```

### Health Check (Docker Compose)

Add to `docker-compose.yml`:
```yaml
healthcheck:
  test: ["CMD", "test", "-f", "/backups/last-run"]
  interval: 24h
  timeout: 10s
  retries: 1
```

## Complete Example

Full production setup:

```bash
# 1. Setup
mkdir -p /opt/mysql-backup/backups
cd /opt/mysql-backup

# 2. Clone and build
git clone https://github.com/indieTorrent/mysql-db-backup.git .
git checkout composer
docker build -t mysql-db-backup:latest -f docker/Dockerfile .

# 3. Configure
cat > .env <<EOF
DB_HOST=localhost
DB_USERNAME=backup_user
DB_PASSWORD=$(openssl rand -base64 32)
HOST_BACKUP_DIR=/opt/mysql-backup/backups
EOF
chmod 600 .env

# 4. Test
docker run --rm --network host --env-file .env \
  -v /opt/mysql-backup/backups:/backups \
  mysql-db-backup:latest

# 5. Setup cron (as mariadb-backup user)
cat > run-backup.sh <<'EOF'
#!/bin/bash
docker run --rm --network host --env-file /opt/mysql-backup/.env \
  -v /opt/mysql-backup/backups:/backups mysql-db-backup:latest
EOF
chmod +x run-backup.sh

crontab -e
# Add: 0 2 * * * /opt/mysql-backup/run-backup.sh >> /var/log/mysql-backup.log 2>&1
```
