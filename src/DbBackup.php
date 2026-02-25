<?php

namespace Indietorrent\MysqlDbBackup;

use mysqli;
use Exception;

/**
 * MySQL Database Backup Tool
 *
 * Automates the routine export of MySQL/MariaDB databases with intelligent
 * deduplication and compression.
 *
 * @author Ben Johnson (ben@indietorrent.org)
 * @copyright Copyright (c) 2012, Ben Johnson
 * @license GNU General Public License, Version 3 (GPLv3)
 */
class DbBackup
{
    /**
     * @var array Configuration settings
     */
    private array $config;

    /**
     * @var mysqli Database connection
     */
    private mysqli $mysqli;

    /**
     * @var array System databases to exclude from backups
     */
    private const SYSTEM_DATABASES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys'
    ];

    /**
     * Constructor
     *
     * @param string|null $configFile Path to configuration file
     * @throws Exception If configuration cannot be loaded or database connection fails
     */
    public function __construct(?string $configFile = null)
    {
        $this->loadConfiguration($configFile);
        $this->connectToDatabase();
    }

    /**
     * Load configuration from file or environment variables
     *
     * @param string|null $configFile Path to configuration file
     * @throws Exception If configuration is invalid
     */
    private function loadConfiguration(?string $configFile): void
    {
        $config = [];

        // Try to load from INI file if provided
        if ($configFile !== null && file_exists($configFile)) {
            $config = parse_ini_file($configFile, true);
            if ($config === false) {
                throw new Exception(
                    'Configuration values could not be read from "' . $configFile . '"; ' .
                    'ensure that the file exists and contains valid configuration parameters'
                );
            }
        }

        // Use environment variables as fallback or override
        $this->config = [
            'hostname' => $config['connection']['hostname'] ?? getenv('DB_HOST') ?: 'localhost',
            'username' => $config['connection']['username'] ?? getenv('DB_USERNAME') ?: '',
            'password' => $config['connection']['password'] ?? getenv('DB_PASSWORD') ?: null,
            'dumpdir' => $config['backup']['dumpdir'] ?? getenv('BACKUP_DIR') ?: '/backups',
        ];

        // Ensure password is null if empty (for socket-based auth)
        if (empty($this->config['password'])) {
            $this->config['password'] = null;
        }

        // Validate required configuration
        if (empty($this->config['username'])) {
            throw new Exception('Database username must be specified in config file or DB_USERNAME environment variable');
        }

        if (empty($this->config['dumpdir'])) {
            throw new Exception('Backup directory must be specified in config file or BACKUP_DIR environment variable');
        }
    }

    /**
     * Connect to the MySQL database
     *
     * @throws Exception If connection fails
     */
    private function connectToDatabase(): void
    {
        $this->mysqli = new mysqli(
            $this->config['hostname'],
            $this->config['username'],
            $this->config['password']
        );

        if ($this->mysqli->connect_error) {
            throw new Exception(
                'Connect Error (' . $this->mysqli->connect_errno . ') ' . $this->mysqli->connect_error
            );
        }
    }

    /**
     * Run the backup process for all databases
     *
     * @throws Exception If backup process fails
     */
    public function run(): void
    {
        $databases = $this->getDatabases();

        foreach ($databases as $database) {
            if ($this->isSystemDatabase($database)) {
                echo 'Skipping system database: ' . $database . PHP_EOL;
                continue;
            }

            echo 'Processing database: ' . $database . PHP_EOL;

            try {
                $this->backupDatabase($database);
            } catch (Exception $e) {
                echo 'Error backing up database "' . $database . '": ' . $e->getMessage() . PHP_EOL;
                throw $e; // Re-throw to exit on error
            }
        }

        // Connection will be closed automatically by destructor
    }

    /**
     * Get list of all databases
     *
     * @return array List of database names
     * @throws Exception If query fails
     */
    private function getDatabases(): array
    {
        $databases = [];
        $result = $this->mysqli->query('SHOW DATABASES');

        if ($result === false) {
            throw new Exception('Failed to query databases: ' . $this->mysqli->error);
        }

        while ($row = $result->fetch_assoc()) {
            $databases[] = $row['Database'];
        }

        return $databases;
    }

    /**
     * Check if a database is a system database
     *
     * @param string $database Database name
     * @return bool True if system database
     */
    private function isSystemDatabase(string $database): bool
    {
        return in_array($database, self::SYSTEM_DATABASES, true);
    }

    /**
     * Backup a single database
     *
     * @param string $database Database name
     * @throws Exception If backup fails
     */
    private function backupDatabase(string $database): void
    {
        $targetDir = $this->config['dumpdir'] . DIRECTORY_SEPARATOR . $database;
        $this->ensureDirectoryExists($targetDir);

        $dumpFileName = $targetDir . DIRECTORY_SEPARATOR . date('YmdHi') . '.sql';

        // Build mysqldump command
        // --skip-comments: Ensures hash checks work correctly (comments include timestamps)
        // --single-transaction: Ensures consistency without locking tables
        $cmd = sprintf(
            'mysqldump --skip-comments --add-drop-table --default-character-set=utf8 ' .
            '--extended-insert --host=%s --quick --quote-names --routines --set-charset ' .
            '--single-transaction --triggers --tz-utc --verbose --user=%s',
            escapeshellarg($this->config['hostname']),
            escapeshellarg($this->config['username'])
        );

        if (!empty($this->config['password'])) {
            $cmd .= ' --password=' . escapeshellarg($this->config['password']);
        }

        $cmd .= ' ' . escapeshellarg($database) . ' > ' . escapeshellarg($dumpFileName);

        // Execute dump command
        $output = [];
        $retVal = 0;
        exec($cmd, $output, $retVal);

        // Verify dump succeeded
        if ($retVal !== 0 || !file_exists($dumpFileName)) {
            throw new Exception(
                'The database "' . $database . '" could not be dumped. ' .
                'Exit code: ' . $retVal . '. ' .
                'Output: ' . implode("\n", $output)
            );
        }

        echo 'The database "' . $database . '" was dumped successfully to ' . $dumpFileName . PHP_EOL;

        // Check if this backup is identical to the previous one
        if ($this->isDuplicateBackup($targetDir, $dumpFileName)) {
            echo 'This dump matches the previous dump exactly (checked by SHA1 hash); ' .
                 'discarding this backup, as it contains nothing new' . PHP_EOL;
            unlink($dumpFileName);
            return;
        }

        // Compress the backup file
        $gzipped = $this->gzipFile($dumpFileName);

        if ($gzipped === false) {
            echo 'gzipping "' . $dumpFileName . '" failed; keeping uncompressed file as a fall-back' . PHP_EOL;
        } else {
            echo 'File was gzipped successfully to ' . $gzipped . PHP_EOL;
        }
    }

    /**
     * Ensure a directory exists, creating it if necessary
     *
     * @param string $directory Directory path
     * @throws Exception If directory cannot be created
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            $created = mkdir($directory, 0777, true);
            if ($created === false) {
                throw new Exception(
                    'The directory "' . $directory . '" does not exist and could not be created; ' .
                    'please check the permissions'
                );
            }
        }
    }

    /**
     * Check if the new backup is identical to the previous backup
     *
     * @param string $targetDir Directory containing backups
     * @param string $newFile Path to new backup file
     * @return bool True if backup is a duplicate
     */
    private function isDuplicateBackup(string $targetDir, string $newFile): bool
    {
        // Get existing files, sorted descending by name (newest first)
        $existing = array_diff(scandir($targetDir, SCANDIR_SORT_DESCENDING), ['.', '..']);

        // Need at least 2 files to compare (the new one and a previous one)
        if (count($existing) < 2) {
            return false;
        }

        // Find the most recent .gz file (the previous backup)
        $oldFile = null;
        foreach (array_slice($existing, 0, 2) as $file) {
            $fullPath = $targetDir . DIRECTORY_SEPARATOR . $file;
            if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'gz') {
                $oldFile = $fullPath;
                break;
            }
        }

        if ($oldFile === null) {
            return false;
        }

        echo 'More than one backup exists; checking hashes to see if this backup differs from the last...' . PHP_EOL;

        // Get hash of previous backup (uncompressed)
        $cmd = 'gunzip ' . escapeshellarg($oldFile) . ' --to-stdout | sha1sum -';
        $oldHash = system($cmd, $oldRetVal);

        if ($oldRetVal !== 0) {
            echo 'Warning: Could not get hash of previous backup' . PHP_EOL;
            return false;
        }

        // Get hash of new backup
        $cmd = 'sha1sum ' . escapeshellarg($newFile);
        $newHash = system($cmd, $newRetVal);

        if ($newRetVal !== 0) {
            echo 'Warning: Could not get hash of new backup' . PHP_EOL;
            return false;
        }

        // Compare first 40 characters (the actual hash, excluding filename)
        if (substr($oldHash, 0, 40) === substr($newHash, 0, 40)) {
            return true;
        }

        echo 'The two files are not the same; this backup contains new information' . PHP_EOL;
        return false;
    }

    /**
     * Compress a file using gzip
     *
     * @param string $file Path to file to compress
     * @return string|false Path to compressed file, or false on failure
     */
    private function gzipFile(string $file): string|false
    {
        $cmd = 'gzip ' . escapeshellarg($file);

        $output = [];
        $retVal = 0;
        exec($cmd, $output, $retVal);

        if ($retVal !== 0) {
            return false;
        }

        return $file . '.gz';
    }

    /**
     * Destructor - ensures database connection is closed
     */
    public function __destruct()
    {
        if (isset($this->mysqli)) {
            $this->mysqli->close();
        }
    }
}
