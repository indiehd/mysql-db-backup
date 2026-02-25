# MySQL Database Backup

A PHP CLI tool for automated MySQL/MariaDB database backups with intelligent deduplication and compression.

## Features

- ✅ **Automated backups** of all databases (excluding system databases)
- ✅ **Intelligent deduplication** - skips backups if database content hasn't changed (SHA1 hash comparison)
- ✅ **Automatic compression** - gzip compression of backup files
- ✅ **Organized storage** - separate directories for each database
- ✅ **Cron-ready** - designed for scheduled execution
- ✅ **Docker support** - includes Dockerfile for containerized usage
- ✅ **Flexible configuration** - INI file or environment variables

## Requirements

- PHP 8.0 or higher
- `mysqli` PHP extension
- MySQL or MariaDB server
- System executables: `mysqldump`, `gzip`, `gunzip`, `sha1sum`

## Installation

### Via Composer (Recommended)

```bash
composer require indietorrent/mysql-db-backup
```

### Manual Installation

```bash
git clone https://github.com/yourusername/mysql-db-backup.git
cd mysql-db-backup
composer install
```

## Configuration

### Option 1: Configuration File (Recommended)

Copy the example configuration file and customize it:

```bash
cp db-backup.ini.example db-backup.ini
```

Edit `db-backup.ini`:

```ini
[connection]
hostname = localhost
username = your_mysql_user
password = your_mysql_password

[backup]
dumpdir = /path/to/backups
```

**Note:** The `password` field is optional if you're using socket-based authentication (e.g., `auth_socket` plugin in MariaDB).

### Option 2: Environment Variables

You can also configure using environment variables:

```bash
export DB_HOST=localhost
export DB_USERNAME=your_mysql_user
export DB_PASSWORD=your_mysql_password
export BACKUP_DIR=/path/to/backups
```

## Usage

### Command Line

If installed via Composer:

```bash
./vendor/bin/mysql-db-backup
```

If installed globally or from project directory:

```bash
./bin/mysql-db-backup
```

### Cron Job Setup

To run automated backups daily at 2 AM:

```bash
crontab -e
```

Add this line:

```cron
0 2 * * * cd /path/to/project && ./vendor/bin/mysql-db-backup >> /var/log/mysql-backup.log 2>&1
```

Or if using environment variables:

```cron
0 2 * * * DB_HOST=localhost DB_USERNAME=backup_user DB_PASSWORD=secret BACKUP_DIR=/backups /path/to/vendor/bin/mysql-db-backup >> /var/log/mysql-backup.log 2>&1
```

### Docker Usage

Build and run with Docker Compose:

```bash
docker-compose up
```

Or build manually:

```bash
docker build -t mysql-db-backup -f docker/Dockerfile .
docker run -v /path/to/backups:/backups mysql-db-backup
```

## How It Works

1. **Connect** to MySQL/MariaDB server
2. **List** all databases (excluding system databases: `mysql`, `information_schema`, `performance_schema`, `sys`)
3. **Dump** each database using `mysqldump` with optimized flags
4. **Compare** the new backup hash with the previous backup
5. **Skip** if identical (no changes detected)
6. **Compress** with gzip if new data is present
7. **Store** in organized directory structure: `<dumpdir>/<database>/<YYYYMMDDHHmm>.sql.gz`

## Backup Optimization

The tool uses SHA1 hash comparison to avoid storing duplicate backups when database content hasn't changed. This saves significant storage space for databases that don't change frequently.

The dump files are created **without comments** (via `--skip-comments`) to ensure hash comparison works correctly, as comments include timestamps that would always differ.

## Directory Structure

```
/path/to/backups/
├── database1/
│   ├── 202602251400.sql.gz
│   ├── 202602261400.sql.gz
│   └── 202602271400.sql.gz
├── database2/
│   ├── 202602251400.sql.gz
│   └── 202602271400.sql.gz  # 26th skipped - no changes
└── database3/
    └── 202602251400.sql.gz
```

## Troubleshooting

### Permission Issues

Ensure the backup directory is writable:

```bash
chmod 755 /path/to/backups
```

### MySQL Connection Issues

Test your connection:

```bash
mysql -h localhost -u your_user -p
```

### Missing Dependencies

Verify required executables are available:

```bash
which mysqldump gzip gunzip sha1sum
```

## License

GNU General Public License v3.0 or later (GPL-3.0-or-later)

## Author

Ben Johnson (ben@indietorrent.org)

Copyright (c) 2012, Ben Johnson
