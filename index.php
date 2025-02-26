<?php

set_time_limit(0); 
ignore_user_abort(true);
ini_set('max_execution_time', 6300);


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Import Tool</title>
</head>
<body>
    <h2>Product Import Tool</h2>
    <button onclick="importProducts()">Import Products from CSV</button>
    <script src="script.js"></script>
    <script src="index.js"></script>
</body>
</html>