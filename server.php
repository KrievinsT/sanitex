<?php
require 'config.php';

$config = require 'config.php';

if (isset($_GET['action'])) {
    if ($_GET['action'] == 'importCSV') {
        echo json_encode(importCSVFiles());
    } elseif ($_GET['action'] == 'insertSingleProduct') {
        echo json_encode(insertSingleProduct());
    }
}

function checkProductExists($productHandle)
{
    global $config;
    $ch = curl_init("https://api.mozello.com/v1/store/product/" . $productHandle . "/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ApiKey ' . $config['mozello_api_secret']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $responseData = json_decode($response, true);
        return $responseData; // Return product data if product exists
    } elseif ($httpCode == 404) {
        return false; // Product does not exist
    } else {
        // Handle other status codes if needed, for now treat as not exist for safety or log error
        return false;
    }
}

function updateProduct($productHandle, $productData)
{
    global $config;

    $ch = curl_init("https://api.mozello.com/v1/store/product/" . $productHandle . "/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ApiKey ' . $config['mozello_api_secret']
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["product" => $productData]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $responseData = json_decode($response, true);
        return ["status" => "success", "message" => "Product with handle '" . $productHandle . "' successfully updated.", "response" => $responseData];
    } else {
        return ["status" => "error", "message" => "Failed to update product with handle '" . $productHandle . "'. HTTP code: " . $httpCode, "response" => json_decode($response, true)];
    }
}


function importCSVFiles()
{
    global $config;
    

    $localFolder = __DIR__ . "/uploads/";
    if (!is_dir($localFolder))
        mkdir($localFolder, 0777, true);

    $csvFiles = ["ProductInfo.csv", "Products.csv", "Stock.csv"];
    $formattedData = [];

    
    foreach ($csvFiles as $file) {
        $localFile = $localFolder . $file;
        if (!file_exists($localFile)) {
            return ["status" => "error", "message" => "Local file not found: $file"];
        }
        if (($handle = fopen($localFile, "r")) !== FALSE) {
            $headers = fgetcsv($handle, 1000, ";");
            if ($headers === false) {
                fclose($handle);
                continue;
            }
            $headers = array_map('trim', $headers);
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                $data = array_map('trim', $data);
                if (count($headers) == count($data)) {
                    $formattedData[$file][] = array_combine($headers, $data);
                } else {
                    echo "Warning: Header and data row have different number of columns in file '$file'. Skipping row.\n";
                }
            }
            fclose($handle);
        }
    }

    // CSV category => [markup value, API category, is percentage?]

    $categoryMappings = [
        'Kafijas pupiņas' => [ 5.00, 'Kafijas pupiņas', false],
        'Baltais Cukurs' =>  [ 0.6,  'Cukuri', true],
        'Brūnais Cukurs' =>  [ 0.6,  'Cukuri', true]
    ];

    // SKU or name => fixed markup in EUR
    $productFixedMarkups = [
        'ILLY' => 2.00, 
    ];

    foreach ($formattedData['ProductInfo.csv'] as $productInfo) {
        
        $csvCategory = $productInfo['Subcategory'];
        $productSku = $productInfo['INF_PREK'];
        $productName = $productInfo['Name'];

        
        if (!isset($categoryMappings[$csvCategory])) {
            continue; 
        }

        
        [$markupValue, $apiCategory, $isPercentage] = array_values($categoryMappings[$csvCategory]);

        
        $fixedMarkup = null;
        foreach ($productFixedMarkups as $key => $amount) {
            if (str_contains($productSku, $key) || str_contains($productName, $key)) {
                $fixedMarkup = $amount;
                break;
            }
        }

        
        $product = [
            "handle" => $productSku,
            "category" => [
                "path" => [
                    [
                        "lv" => $apiCategory 
                    ]
                ]
            ],
            "title" => $productName,
            "description" => $productInfo['Description'],
            "price" => null,
            "sale_price" => null,
            "stock" => null,
            "sku" => $productSku,
            "visible" => "FALSE",
            "featured" => "FALSE",
            "vendor" => $productInfo['Brand'],
            "pictures" => [] // Initially empty
        ];

        
        foreach ($formattedData['Products.csv'] as $productPrice) {
            if ($productPrice['INF_PREK'] == $productSku) {
                $basePrice = (float)$productPrice['Kaina'];
                $discountedPrice = (float)$productPrice['LMKaina'];

                if ($fixedMarkup !== null) {
                    // Product-specific fixed markup
                    $product['price'] = $basePrice + $fixedMarkup;
                    $product['sale_price'] = $discountedPrice + $fixedMarkup;
                } elseif ($isPercentage) {
                    // Category-based percentage markup
                    $product['price'] = $basePrice + ($basePrice * $markupValue);
                    $product['sale_price'] = $discountedPrice + ($basePrice * $markupValue);
                } else {
                    // Category-based fixed markup
                    $product['price'] = $basePrice + $markupValue;
                    $product['sale_price'] = $discountedPrice + $markupValue;
                }
                break;
            }
        }

        
        foreach ($formattedData['Stock.csv'] as $productStock) {
            if ($productStock['INF_PREK'] == $productSku) {
                $product['stock'] = (int)$productStock['PC1'];
                break;
            }
        }
        

        $productHandle = $product['handle'];
        if ($existingProductData = checkProductExists($productHandle)) {
            echo "Product with handle '" . $productHandle . "' already exists. Updating product from CSV.\n";
            $updateResult = updateProduct($productHandle, $product);
            if ($updateResult['status'] == 'success') {
                error_log("Product with handle '" . $productHandle . "' already exists");
                echo "Product with handle '" . $productHandle . "' successfully updated from CSV.\n";
            } else {
                echo "Failed to update product with handle '" . $productHandle . "' from CSV. Error: " . $updateResult['message'] . "\n";
                continue;
            }
        } else {
            // Step 2: Create the product in Mozello
            $ch = curl_init($config['mozello_api_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: ApiKey ' . $config['mozello_api_secret']
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["product" => $product]));

            $response = curl_exec($ch);
            curl_close($ch);

            $responseData = json_decode($response, true);

            // Corrected check to look for "error": false for success
            if (isset($responseData['error']) && $responseData['error'] === false) {
                // Step 3: Upload and associate the image with the created product
                $imageUrl = $productInfo["Photo_URL"];
                if (empty($imageUrl) || filter_var($imageUrl, FILTER_VALIDATE_URL) === false) {
                    continue; // Skip this product if the image URL is invalid
                }

                // Fetch the image data
                $imageData = file_get_contents($imageUrl);
                $base64Image = base64_encode($imageData);

                // Step 4: Upload the image and associate it with the product
                uploadImageToMozello($product['handle'], $base64Image);
            } else {
                echo "Failed to create product with handle '" . $productHandle . "'. API response indicates error. Skipping image upload.\n";
                continue; // Skip image upload for this product
            }
        }


    }

    return ["status" => "success", "message" => "Products processed successfully."];
}

function uploadImageToMozello($productHandle, $base64Image)
{
    global $config;
    $uniqueFilename = uniqid("product_", true) . ".jpg";

    $payload = [
        "picture" => [
            "filename" => $uniqueFilename,
            "data" => $base64Image
        ]
    ];

    $ch = curl_init("https://api.mozello.com/v1/store/product/$productHandle/picture/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ApiKey ' . $config['mozello_api_secret']
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);

    curl_close($ch);

    return json_decode($response, true);
}