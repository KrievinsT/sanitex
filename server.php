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


function importCSVFiles() {
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
        'Brūnais cukurs' => [0.6, 'Cukuri', true, ['DAN SUKKER', 'DIAMANT'], true],
        'Nespresso' => [0.5, 'Kafijas kapsulas', true, ['kapsulas', 'Kafijas kapsulas'], true],
        'Dolce Gusto' => [0.5, 'Kafijas kapsulas', true, ['kapsulas', 'Kafijas kapsulas'], true],
        'Citas' => [0.5, 'Kafijas kapsulas', true, ['kapsulas', 'Kafijas kapsulas'], true],
        'Kakao' => [0.5, 'Kakao', true, [], true],
        'Medus' => [0.6, 'Medus', true, [], true], 
        'Piena produkti un olas' => [0.6, 'Piena produkti', true, ['OATLY', 'DEARY', 'ALPRO', 'VITASI', 'MARGE', 'VALIO', 'BRIDGE'], false], 
        'Saldais krējums' => [0.6, 'Piens', true, ['Kafijas krējums'], true], 
        'Šokolādes konfektes' => [0.4, 'Saldumi', true, ['MARGE', 'ROSHEN', 'PERGALE', 'LAIMA', 'Regina', 'PURE', 'Migle', ], true], 
        'Karameles' => [0.4, 'Saldumi', true, ['Gotiņa'], true], 
        'Sīrupi, biezsulas' => [0.6, 'Sīrupi', true, [], true], 
        'Ilgtermiņa piens (UHT)' => [0.6, 'Piens', true, ['Piens'], true], 
        'Maltās kafijas' => [0.5, 'Maltā kafija', true, [], true],
        'Šķīstošā kafija' => [0.5, 'Šķīstošā kafija', true, [], true],
        'Kapučīno' => [0.5, 'Šķīstošā kafija', true, ['Jacobs Latte', 'Jacobs 2in1', 'Jacobs 3IN1', 'NESCAFE Classic 3 in 1', 'MOKATE 3in1 Latte', 'NESCAFE Strong 3 in 1,'], true],
        'Beramās tējas' => [0.7, 'Tējas beramās', true, [], true],
        'Tējas maisiņos' => [0.7, 'Tēja paciņās', true, [], true],

    ];

    $allowedProductSkus = [
        "C001665", "C004170", "C004218", "C056222", "C056497", "C058417", "C059950", "C059951", "C061619",
        "F001413", "F009456", "F010176", "U01200S", "U01200U", "U054AXB", "U054AXD", "U124K42", "U131931",
        "U18C153", "U18C155", "U18C159", "U18C15H", "U18C15K", "U18C15L", "U18C15P", "U18C15T", "U18C15U",
        "U18C15V", "U18C168", "U228482", "U228484", "U2284FC", "U233521", "U233522", "U233523", "U233525",
        "U233526", "U233527", "U233530", "U233531", "U233532", "U233533", "U234130", "U234131", "U234132",
        "U23642F", "U23642G", "U236444", "U236447", "U236448", "U2364CF", "U2364E3", "U2364E4", "U2365CJ",
        "U236771", "U236772", "U236992", "U236993", "U236994", "U236995", "U23A377", "U23A378", "U23A379",
        "U23A37L", "U23A384", "U23A38B", "U23A38I", "U23A38N", "U23A38O", "U23A38P", "U23A38Q", "U23A38R",
        "U23A38S", "U23A38V", "U23A38W", "U23A38Z", "U23A39K", "U23A39L", "U23A40D", "U23A40E", "U23A40F",
        "U23A40H", "U23A40Y", "U23A40J", "U23A40K", "U23A40M", "U23A40P", "U23A40R", "U23A40U", "U23A40W",
        "U23A41E", "U23A41H", "U23A41J", "U23A41L", "U23A41Q", "U23A41T", "U23A41V", "U23A41W", "U23A41X",
        "U23A42K", "U23A42L", "U23A42M", "U23A42R", "U23A42S", "U23B400", "U23B401", "U23B406", "U23B416",
        "U23B418", "U23B419", "U23B420", "U23B422", "U23H127", "U23H128", "U23H131", "U23H136", "U23H138",
        "U23H141", "U23H143", "U23H153", "U23H154", "U23H155", "U23H156", "U23H157", "U23H158", "U23H190",
        "U23H191", "U23H192", "U23H193", "U23H194", "U23H195", "U23H196", "U26A874", "U363361", "U36336N",
        "U407775", "U4313A1", "U431607", "U431608", "U4323EC", "U432811", "U432812", "U432813", "U43281T",
        "U4328JF", "U4328P0", "U4328P3", "U4328P4", "U4328P5", "U922A12", "U922A14", "U922AA2", "U922AC3",
        "U922B02", "U99L121", "U99L12A", "U99L12E", "U99L13H", "U99L54D", "U9C2H9Y", "U9H12S3", "V13114U",
        "V2216A8", "V228483", "V228493", "V22849L", "V2292E0", "V23111A", "V23111B", "V231771", "V231775",
        "V231912", "V23191Z", "V2319C7", "V2319C8", "V2319C9", "V2319D1", "V2319D6", "V2319D7", "V2319D8",
        "V2319E8", "V2319E9", "V2319F1", "V2319F2", "V231G13", "V231G14", "V23291F", "V23291S", "V232963",
        "V232964", "V23299C", "V23299J", "V23311B", "V23311D", "V23311E", "V23389A", "V233910", "V233911",
        "V233912", "V233913", "V233931", "V233936", "V2339PA", "V23462C", "V23933R", "V23933T", "V239593",
        "V23959J", "V23959T", "V24181E", "V24181F", "V24181G", "V24181H", "V24181Y", "V24181J", "V24181T",
        "V24181W", "V24182A", "V24182B", "V24182E", "V24182F", "V24182H", "V24182K", "V2419A8", "V2419B1",
        "V2419B2", "V2419B3", "V2419B8", "V2419B9", "V2419C1", "V2419C3", "V2419C4", "V2419C5", "V2419C6",
        "V2419C7", "V28B21S", "V28C122", "V28C91U", "V53919S", "V95Y1Y1", "V95Y1Y2", "V95Y1Y3", "V95Y1Q1",
        "V95Y1Q2", "V95Y1Q3", "V95Y1Q4", "V95Y1Q5", "V95Y1Q6", "V95Y1Q9", "V95Y1W1", "V95Y1W2"
    ];

    
    $productFixedMarkups = [
        'ILLY' => 2.00,
    ];

    
    $batchSize = 100;
    $productChunks = array_chunk($formattedData['ProductInfo.csv'], $batchSize); 
    
    
    foreach ($productChunks as $batch) {
        foreach ($batch as $productInfo) {

        $productSku = $productInfo['INF_PREK']; 
    
        // Check if the product SKU is in the allowed list
        if (!in_array($productSku, $allowedProductSkus)) {
            echo "Skipping SKU: " . $productSku . "\n";
            continue; 
        }
    
        $csvSubcategory = $productInfo['Subcategory'];   
        $csvMainCategory = $productInfo['Item Category']; 
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
    
        $basePrice = $product['price'];
        $product['sale_price'] = $basePrice; 
    
        foreach ($formattedData['Sales.csv'] as $saleprice) {
            if ($saleprice['INF_PREK'] == $productSku && !empty($saleprice['PROMO_PRICE_PVM'])) {
                $sale = (float)$saleprice['PROMO_PRICE_PVM'];
    
                if ($fixedMarkup !== null) {
                    $product['sale_price'] = $sale + $fixedMarkup;
                } elseif ($isPercentage) {
                    $product['sale_price'] = $sale * (1 + $markupValue);
                } else {
                    $product['sale_price'] = $sale + $markupValue;
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