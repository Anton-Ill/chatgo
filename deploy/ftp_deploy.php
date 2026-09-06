<?php

declare(strict_types=1);

/**
 * Скрипт синхронизации файлов Chatgo через FTP.
 * Использование: php deploy/ftp_deploy.php [--dry-run]
 */

$ftpHost = '62.109.25.205';
$ftpPort = 21;
$ftpUser = 'chatgo';
$ftpPass = '3WMd3aNwcetRf5y7';

$isDryRun = in_array('--dry-run', $argv, true);

echo "Connecting to FTP {$ftpHost}:{$ftpPort}...\n";
$conn = ftp_connect($ftpHost, $ftpPort, 15);
if (!$conn) {
    echo "ERROR: Failed to connect to FTP server.\n";
    exit(1);
}

if (!ftp_login($conn, $ftpUser, $ftpPass)) {
    echo "ERROR: FTP login failed.\n";
    ftp_close($conn);
    exit(1);
}

ftp_pasv($conn, true);
echo "FTP login successful. Passive mode enabled.\n";

$baseDir = dirname(__DIR__);
$exclude = [
    '.git',
    '.osp',
    '.env',
    'logs',
    'deploy',
    'tests',
    'сервер.txt',
    'Инфа о проекте.txt',
    'Скрины конкурентов',
];

function uploadDirectory($conn, string $localDir, string $remoteDir, array $exclude, bool $dryRun, string $rootLocal): void
{
    $items = scandir($localDir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $localPath = $localDir . DIRECTORY_SEPARATOR . $item;
        $relativeLocal = ltrim(str_replace('\\', '/', substr($localPath, strlen($rootLocal))), '/');

        // Проверка исключений
        $skip = false;
        foreach ($exclude as $pattern) {
            if ($relativeLocal === $pattern || str_starts_with($relativeLocal, $pattern . '/')) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        $remotePath = $remoteDir . '/' . $item;

        if (is_dir($localPath)) {
            if (!$dryRun) {
                @ftp_mkdir($conn, $remotePath);
            }
            uploadDirectory($conn, $localPath, $remotePath, $exclude, $dryRun, $rootLocal);
        } else {
            echo "Uploading: {$relativeLocal} -> {$remotePath}... ";
            if ($dryRun) {
                echo "[DRY RUN]\n";
            } else {
                $success = ftp_put($conn, $remotePath, $localPath, FTP_BINARY);
                echo $success ? "[OK]\n" : "[FAIL]\n";
            }
        }
    }
}

echo "Starting sync (Root: {$baseDir})...\n";
uploadDirectory($conn, $baseDir, '', $exclude, $isDryRun, $baseDir);

ftp_close($conn);
echo "Sync completed!\n";
