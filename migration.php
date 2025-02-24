<?php

require 'config.php';
$config = require 'config.php';

$configs = [
    $sftpHost = $config['sftp_host'],
    $sftpPort = $config['sftp_port'] ?? 22, // Default to port 22
    $sftpUser = $config['sftp_user'],
    $sftpPass = $config['sftp_pass']
];

$localFolder = __DIR__ . "/data/";
$remoteFolder = "/"; // Adjust to actual remote path

// Ensure local folder exists
if (!is_dir($localFolder)) {
    mkdir($localFolder, 0777, true);
}

// File patterns to match the latest CSV files
$filePatterns = [
    'ProductInfo' => '/MKANDERSONS_ProductInfo_\d{4}-\d{2}-\d{2}\.csv/',
    'Products'    => '/MKANDERSONS_Products_\d{4}-\d{2}-\d{2}\.csv/',
    'Stock'       => '/MKANDERSONS_Stock_\d{4}-\d{2}-\d{2}_\d{4}\.csv/'
];

function listSftpFiles($config, $remoteFolder)
{
    $url = "sftp://{$config['host']}{$remoteFolder}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_PORT, $config['port']);
    curl_setopt($ch, CURLOPT_USERPWD, "{$config['username']}:{$config['password']}");
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_DIRLISTONLY, true); // List files only

    $result = curl_exec($ch);
    if ($result === false) {
        die("Error listing SFTP files: " . curl_error($ch) . "\n");
    }

    curl_close($ch);
    return explode("\n", trim($result)); // Return array of file names
}

function findLatestFile($files, $pattern)
{
    $matchingFiles = array_filter($files, fn($file) => preg_match($pattern, $file));

    if (empty($matchingFiles)) {
        return null;
    }

    // Sort by date (newest first)
    usort($matchingFiles, function ($a, $b) {
        preg_match('/\d{4}-\d{2}-\d{2}/', $a, $dateA);
        preg_match('/\d{4}-\d{2}-\d{2}/', $b, $dateB);
        return strtotime($dateB[0]) - strtotime($dateA[0]);
    });

    return reset($matchingFiles); // Return latest file
}

function downloadSftpFile($config, $remoteFile, $localFile)
{
    $url = "sftp://{$config['host']}$remoteFile";
    $fp = fopen($localFile, 'w');

    if (!$fp) {
        die("Error: Unable to create local file $localFile\n");
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_PORT, $config['port']);
    curl_setopt($ch, CURLOPT_USERPWD, "{$config['username']}:{$config['password']}");
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_VERBOSE, false);

    $result = curl_exec($ch);

    if ($result === false) {
        echo "Failed to download $remoteFile: " . curl_error($ch) . "\n";
    } else {
        echo "Downloaded: $remoteFile -> $localFile\n";
    }

    curl_close($ch);
    fclose($fp);
}

// Get list of files from SFTP
$files = listSftpFiles($config, $remoteFolder);

// Find and download the latest files
foreach ($filePatterns as $key => $pattern) {
    $latestFile = findLatestFile($files, $pattern);
    if ($latestFile) {
        downloadSftpFile($config, $remoteFolder . $latestFile, $localFolder . $key . ".csv");
    } else {
        echo "Error: No matching file found for $key\n";
    }
}

echo "Download process completed.\n";
?>
