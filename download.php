<?php
    ob_clean();
    flush();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
        $filePath = 'uploads/Sales.csv'; // Path to the file
    
        if (!file_exists($filePath)) {
            die("Error: The file does not exist.");
        }
    
        // Set headers to force download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
    
        // Read the file and send it to the browser
        readfile($filePath);
        exit();
    } else {
        die("Unauthorized access!");
    }
    
?>
