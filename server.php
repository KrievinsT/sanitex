<?php
require 'config.php';

$config = require 'config.php';

ini_set('max_execution_time', 3600);

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

    $csvFiles = ["ProductInfo.csv", "Products.csv", "Stock.csv", "Sales.csv"];
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

    // CSV category => [markup value| API category | is percentage? |  keywords (optional) | use Subcategory (true) or Item Category (false)]
    $categoryMappings = [
        'Kafijas pupiņas' => [5.00, 'Kafijas pupiņas', false, [], true], 
        'Baltais cukurs' => [0.6, 'Cukuri', true, [], true], 
        'Brūnais cukurs' => [0.6, 'Cukuri', true, ['demerara', 'muskovado'], true], 
        'Veģetāriešiem, vegāniem' => [0.6, 'Piena produkti', true, ['dzēriens'], true], 
        'Saldais krējums' => [0.6, 'Piens', true, ['Kafijas krējums'], true], 
        'Ilgtermiņa piens (UHT)' => [0.6, 'Piens', true, ['Piens'], true], 
        'Maltās kafijas' => [0.5, 'Profesionālā maltā kafija', true, [], true],
        'Šķīstošā kafija' => [0.5, 'Šķīstošā kafija', true, [], true],
        'Kapučīno' => [0.5, 'Šķīstošā kafija', true, ['Jacobs Latte', 'Jacobs 2in1', 'Jacobs 3IN1', 'NESCAFE Classic 3 in 1', 'MOKATE 3in1 Latte', 'NESCAFE Strong 3 in 1,'], true],
        'Beramās tējas' => [0.7, 'Tējas beramās', true, [], true],
        'Kafijas kapsulas' => [0.5, 'Kafijas kapsulas', true, [], false],
        'Tējas maisiņos' => [0.7, 'Tējas Loyd', true, [], true],
    ];

    
    $productFixedMarkups = [
        'ILLY' => 2.00,
    ];

    
    $batchSize = 100;
    $productChunks = array_chunk($formattedData['ProductInfo.csv'], $batchSize); 

    foreach ($productChunks as $batch) {
        foreach ($batch as $productInfo) {
            
            $csvSubcategory = $productInfo['Subcategory'];   
            $csvMainCategory = $productInfo['Item Category']; 
            $productSku = $productInfo['INF_PREK'];
            $productName = $productInfo['Name'];

            $matchedCategory = null;
            foreach ($categoryMappings as $csvCategory => $categoryData) {
                [$markupValue, $apiCategory, $isPercentage, $keywords, $useSubcategory] = array_values($categoryData + [null, null, null, [], true]);

                $categoryMatch = $useSubcategory ? $csvSubcategory : $csvMainCategory;

                if ($categoryMatch === $csvCategory) {
                    $matchedCategory = [$markupValue, $apiCategory, $isPercentage, $keywords];
                    break;
                }
            }

            if (!$matchedCategory) {
                continue;
            }

            [$markupValue, $apiCategory, $isPercentage, $keywords] = $matchedCategory;

           
            if (!empty($keywords)) {
                $matched = false;
                foreach ($keywords as $keyword) {
                    if (stripos($productName, $keyword) !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue; 
                }
            }

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
                "visible" => true,
                "featured" => "FALSE",
                "vendor" => $productInfo['Brand'],
                "pictures" => [] 
            ];

           
            foreach ($formattedData['Products.csv'] as $productPrice) {
                if ($productPrice['INF_PREK'] == $productSku) {
                    $basePrice = (float)$productPrice['Kaina'];

                    if ($fixedMarkup !== null) {
                        $product['price'] = $basePrice + $fixedMarkup;
                    } elseif ($isPercentage) {
                        $product['price'] = $basePrice + ($basePrice * $markupValue);
                    } else {
                        $product['price'] = $basePrice + $markupValue;
                    }
                    break;
                }
            }

            foreach ($formattedData['Sales.csv'] as $saleprice) {
                if ($saleprice['INF_PREK'] == $productSku) {
                    $sale = $saleprice['PC1'];
                    $product['sale_price'] = $product['price'] - $sale;
                }
            }

            // Get stock data
            foreach ($formattedData['Stock.csv'] as $productStock) {
                if ($productStock['INF_PREK'] == $productSku) {
                    $product['stock'] = (int)$productStock['PC1'];
                    break;
                }
            }
    


            // Check if the product exists and update it if necessary
            $productHandle = $product['handle'];
            if ($existingProductData = checkProductExists($productHandle)) {
                echo "Product with handle '" . $productHandle . "' already exists. Updating product from CSV.\n";
                $updateResult = updateProduct($productHandle, $product);
                if ($updateResult['status'] == 'success') {
                    error_log("Product with handle '" . $productHandle . "' already exists");
                    echo "Product with handle '" . $productHandle . "' successfully updated from CSV.\n";
                } else {
                    echo "Failed to update product with handle '" . $productHandle . "'. Error: " . $updateResult['message'] . "\n";
                    continue;
                }
            } else {
                //Create the product in Mozello
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

                if (isset($responseData['error']) && $responseData['error'] === false) {
                    // Step 3: Upload and associate the image with the created product
                    $imageUrl = $productInfo["Photo_URL"];
                    if (empty($imageUrl) || filter_var($imageUrl, FILTER_VALIDATE_URL) === false) {
                        continue; // Skip this product if the image URL is invalid
                    }

                    // Fetch the image data
                    $imageData = file_get_contents($imageUrl);
                    $base64Image = base64_encode($imageData);

                    //Upload the image and associate it with the product
                    uploadImageToMozello($product['handle'], $base64Image);
                } else {
                    echo "Failed to create product with handle '" . $productHandle . "'. API response indicates error. Skipping image upload.\n";
                    continue;
                }
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