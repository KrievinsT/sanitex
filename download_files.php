<?php

// SFTP connection details
$sftpHost = '80.232.192.201';
$sftpUsername = 'mkandersons';
$sftpPassword = 'toGPfm7f=R'; // or use SSH key authentication
$sftpPort = 22;


$uploadsDir = __DIR__ . '/uploads';

$remoteDir = '/';


$patterns = [
    'productInfo' => '/MKANDERSONS_ProductInfo_\d{4}-\d{2}-\d{2}\.csv/',
    'products' => '/MKANDERSONS_Products_\d{4}-\d{2}-\d{2}\.csv/',
    'stock' => '/MKANDERSONS_Stock_\d{4}-\d{2}-\d{2}_\d{4}\.csv/'
];


function clearUploadsFolder($uploadsDir) {
    if (!file_exists($uploadsDir)) {
        mkdir($uploadsDir, 0777, true);
        echo "Uploads folder created.\n";
        return;
    }

    $files = glob($uploadsDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    echo "Uploads folder cleared.\n";
}


function findLatestFile($files, $pattern) {
    $matchedFiles = array_filter($files, function($file) use ($pattern) {
        return preg_match($pattern, $file['filename']);
    });

    if (empty($matchedFiles)) {
        return null;
    }

    usort($matchedFiles, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });

    return $matchedFiles[0]['filename'];
}


function downloadLatestFiles($sftp, $remoteDir, $patterns, $uploadsDir) {
    $files = ssh2_sftp_nlist($sftp, $remoteDir);

    $latestFiles = [];
    foreach ($patterns as $key => $pattern) {
        $latestFile = findLatestFile(array_map(function($file) use ($sftp, $remoteDir) {
            return [
                'filename' => $file,
                'mtime' => ssh2_sftp_stat($sftp, "$remoteDir/$file")['mtime']
            ];
        }, $files), $pattern);

        if (!$latestFile) {
            throw new Exception("One or more required CSV files are missing.");
        }
        $latestFiles[$key] = $latestFile;
    }

    foreach ($latestFiles as $key => $file) {
        $remoteFilePath = "$remoteDir/$file";
        $localFilePath = "$uploadsDir/$key.csv";
        if (!ssh2_scp_recv($sftp, $remoteFilePath, $localFilePath)) {
            throw new Exception("Failed to download file: $file");
        }
        echo "Downloaded: $file\n";
    }
}


function main() {
    global $sftpHost, $sftpUsername, $sftpPassword, $sftpPort, $uploadsDir, $remoteDir, $patterns;

    // Connect to the SSH server
    $connection = ssh2_connect($sftpHost, $sftpPort);
    if (!$connection) {
        throw new Exception('Could not connect to the SSH server.');
    }

    // Authenticate
    if (!ssh2_auth_password($connection, $sftpUsername, $sftpPassword)) {
        throw new Exception('SSH authentication failed.');
    }

    // Initialize SFTP
    $sftp = ssh2_sftp($connection);
    if (!$sftp) {
        throw new Exception('Could not initialize SFTP.');
    }

    echo "SFTP connected.\n";

    clearUploadsFolder($uploadsDir);
    downloadLatestFiles($sftp, $remoteDir, $patterns, $uploadsDir);

    // Close the connection
    ssh2_disconnect($connection);
    echo "SFTP disconnected.\n";
}

try {
    main();
    echo "Files downloaded successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}