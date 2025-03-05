<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
        $zipFile = 'uploads.zip'; // Name of the ZIP file to create
        $folderToZip = 'uploads'; // Folder to be zipped

        // Create a new ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            // Add files to the zip
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderToZip),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($folderToZip) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            // Send the ZIP file for download
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);

            // Delete the ZIP file after download
            unlink($zipFile);
        } else {
            echo 'Failed to create ZIP file.';
        }
    } else {
        echo 'Unauthorized access!';
    }