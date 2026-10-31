<?php

function findDatabaseCli($name) {
    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $executable = $name . ($isWindows ? '.exe' : '');
    $override = getenv(strtoupper($name) . '_BIN');
    $candidates = $override ? [$override] : [];
    $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
        . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $executable;

    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
        if ($directory !== '') {
            $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $executable;
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
    }

    return false;
}

function databaseCliPrefix($binary) {
    if (DB_PASS !== '') {
        putenv('MYSQL_PWD=' . DB_PASS);
    }

    return escapeshellarg($binary)
        . ' --host=' . escapeshellarg(DB_HOST)
        . ' --port=' . (int) DB_PORT
        . ' --user=' . escapeshellarg(DB_USER);
}
