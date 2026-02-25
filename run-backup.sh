#!/bin/bash
#
# MySQL/MariaDB Backup Runner Script
#
# This script runs the backup process in a Docker container.
# Designed to be called from cron for scheduled backups.
#
# Usage:
#   ./run-backup.sh
#
# Cron example (daily at 2 AM):
#   0 2 * * * /path/to/mysql-db-backup/run-backup.sh >> /var/log/mysql-backup.log 2>&1
#
# Network Modes (configure via DOCKER_NETWORK_MODE in .env):
#   - "host" (default on Linux): Best for MySQL on localhost, requires 'backup'@'localhost' user
#   - "bridge" (default Docker): Better isolation, requires 'backup'@'%' or 'backup'@'172.%' user
#   - Custom network name: For multi-container setups

set -e

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Change to the project directory
cd "$SCRIPT_DIR"

# Check if .env file exists
if [ ! -f .env ]; then
    echo "Error: .env file not found in $SCRIPT_DIR"
    echo "Please create a .env file with your database credentials."
    echo "See .env.example or README.md for details."
    exit 1
fi

# Load environment variables to check network mode
source .env

# Check if Docker image exists
if ! docker image inspect mysql-db-backup >/dev/null 2>&1; then
    echo "Error: Docker image 'mysql-db-backup' not found."
    echo "Please build the image first:"
    echo "  docker build -t mysql-db-backup -f docker/Dockerfile ."
    exit 1
fi

# Ensure backup directory exists
mkdir -p "$SCRIPT_DIR/backups"

# Determine network mode (default to "host" for backward compatibility)
NETWORK_MODE="${DOCKER_NETWORK_MODE:-host}"

echo "========================================="
echo "MySQL/MariaDB Backup - $(date)"
echo "Network Mode: $NETWORK_MODE"
echo "========================================="

# Build docker run command based on network mode
DOCKER_CMD="docker run --rm"

# Add network configuration
if [ "$NETWORK_MODE" = "host" ]; then
    DOCKER_CMD="$DOCKER_CMD --network host"
elif [ "$NETWORK_MODE" = "bridge" ]; then
    # Use default bridge network (no --network flag needed)
    :
else
    # Custom network name
    DOCKER_CMD="$DOCKER_CMD --network $NETWORK_MODE"
fi

# Add environment and volume mounts
DOCKER_CMD="$DOCKER_CMD --env-file $SCRIPT_DIR/.env -v $SCRIPT_DIR/backups:/backups mysql-db-backup"

# Run the backup
eval $DOCKER_CMD

echo "========================================="
echo "Backup completed at $(date)"
echo "========================================="
