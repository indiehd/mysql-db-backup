<?php

namespace Indietorrent\MysqlDbBackup;

/**
 * @author Ben Johnson (ben@indietorrent.org)
 * @copyright Copyright (c) 2012, Ben Johnson
 * @license GNU General Public License, Version 3 (GPLv3)
 */

$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'db-backup.ini';

$conf = parse_ini_file($configFile, TRUE);

if ($conf === FALSE) {
    die('Configuration values could not be read from "' . $configFile . '"; ensure that the file exists and contains valid configuration parameters' . PHP_EOL);
}

// Use environment variables if credentials are not provided in the ini file
$hostname = $conf['connection']['hostname'] ?? getenv('DB_HOST');
$username = $conf['connection']['username'] ?? getenv('DB_USERNAME');
$password = $conf['connection']['password'] ?? getenv('DB_PASSWORD');
$dumpdir = $conf['backup']['dumpdir'] ?? '/backups';

if (empty($password)) {
    $password = NULL;
}

var_dump($conf, $hostname, $username, $password); // Debug line to print credentials

$mysqli = new \mysqli($hostname, $username, $password);

if ($mysqli->connect_error) {
    die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

$q = 'SHOW DATABASES';

$r = $mysqli->query($q);

while ($row = $r->fetch_assoc()) {
    $targetDir = $dumpdir . DIRECTORY_SEPARATOR . $row['Database'];

    if (!is_dir($targetDir)) {
        $madeDir = mkdir($targetDir);

        if ($madeDir === FALSE) {
            echo 'The directory "' . $targetDir . '" does not exist and could not be created; please check the permissions';
            exit;
        }
    }

    $dumpFileName = $targetDir . DIRECTORY_SEPARATOR . date('YmdHi') . '.sql';

    $cmd = 'mysqldump --skip-comments --add-drop-table --default-character-set=utf8 --extended-insert --host=' . $hostname . ' --quick --quote-names --routines --set-charset --single-transaction --triggers --tz-utc --verbose --user=' . $username;

    if (!empty($password)) {
        $cmd .= ' --password=\'' . $password . '\'';
    }

    $cmd .= ' "' . $row['Database'] . '" > "' . $dumpFileName . '"';

    $return = system($cmd);

    if ($return != 0 || !file_exists($dumpFileName)) {
        echo 'The database "' . $row['Database'] . '" could not be dumped; exiting to prevent unexpected results' . PHP_EOL;
        exit;
    } else {
        echo 'The database "' . $row['Database'] . '" was dumped successfully to ' . $dumpFileName . PHP_EOL;

        $newFile = $dumpFileName;

        $existing = array_diff(scandir($targetDir, 1), array('..', '.'));

        if (!empty($existing) && is_array($existing) && count($existing) > 1) {
            for ($i = 0; $i < 2; $i++) {
                $thisFile = $targetDir . DIRECTORY_SEPARATOR . $existing[$i];

                if (pathinfo($thisFile, PATHINFO_EXTENSION) === 'gz') {
                    echo 'More than one backup exists; checking hashes to see if this backup differs from the last...' . PHP_EOL;

                    $oldFile = $thisFile;

                    break;
                }
            }

            if (isset($oldFile)) {
                $cmd = 'gunzip "' . $oldFile . '" --to-stdout | sha1sum -';

                echo "Unpacking previous backup and acquiring hash with '" . $cmd . "'..." . PHP_EOL;

                $oldHash = system($cmd);

                $cmd = 'sha1sum "' . $newFile . '"';

                echo "Checking new file hash with '" . $cmd . "'..." . PHP_EOL;

                $newHash = system($cmd);

                if (substr($oldHash, 0, 40) === substr($newHash, 0, 40)) {
                    echo 'This dump matches the previous dump exactly (checked by SHA1 hash); discarding this backup, as it contains nothing new' . PHP_EOL;

                    unlink($newFile);

                    continue;
                } else {
                    echo 'The two files are not the same; this backup contains new information' . PHP_EOL;
                }
            }
        }

        $gzipped = gzipFile($newFile);

        if ($gzipped === FALSE) {
            echo 'gzipping "' . $newFile . '" failed; keeping uncompressed file as a fall-back' . PHP_EOL;
        } else {
            echo 'File was gzipped successfully to ' . $gzipped . PHP_EOL;
        }
    }
}

$mysqli->close();

function gzipFile($file) {
    $cmd = 'gzip "' . $file . '"';

    $return = system($cmd);

    if ($return != 0) {
        return FALSE;
    } else {
        return $file . '.gz';
    }
}
