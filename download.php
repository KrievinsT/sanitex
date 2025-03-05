<?php
    ob_clean();
    flush();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
        $zipFile = 'uploads.zip'; // Name of the ZIP file to create
        $folderToZip = 'uploads'; // Folder to be zipped

        if (!is_dir($folderToZip)) {
            die("Error: Uploads folder does not exist!");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderToZip),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            $fileAdded = false; // Track if files were added

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($folderToZip) + 1);

                    $zip->addFile($filePath, $relativePath);
                    $fileAdded = true;
                }
            }

            $zip->close();

            if (!$fileAdded) {
                unlink($zipFile); // Delete empty ZIP
                die("Error: No files in the uploads folder.");
            }

            // Send ZIP file to the browser
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);

            // Delete ZIP after download
            unlink($zipFile);
            exit();
        } else {
            die("Error: Failed to create ZIP file.");
        }
    } else {
        die("Error: Unauthorized access!");
    }
?>
