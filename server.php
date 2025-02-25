<?php
require 'config.php';

$config = require 'config.php';

if (isset($_GET['action'])) {
    if ($_GET['action'] == 'insertTest') {
        echo json_encode(insertTestProduct());
    } elseif ($_GET['action'] == 'importCSV') {
        echo json_encode(importCSVFiles());
    } elseif ($_GET['action'] == 'insertSingleProduct') {
        echo json_encode(insertSingleProduct());
    }
}

// Insert a test product via API to Mozello
function insertTestProduct() {
    global $config;

    $testProduct = [
        "product" => [
            "handle" => "test-product-124",
            "category" => [
                "path" => [
                    ["lv" => "Profesionālā maltā kafija"]
                ]
            ],
            "title" => "Test Product",
            "description" => "This is a test product added via API.",
            "price" => 9.99,
            "sale_price" => 8.99,
            "stock" => 100,
            "sku" => "TEST123",
            "visible" => "FALSE",
            "featured" => "FALSE",
            "vendor" => "TestVendor",
            "pictures" => [
                [
                    "uid" => "image-001111111",
                    "url" => "https://epromo.imgix.net/image/209fcebf-c363-480f-a3be-897ba971323e.jpg"
                ]
            ]
        ]
    ];

    $ch = curl_init($config['mozello_api_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ApiKey ' . $config['mozello_api_secret']
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testProduct));
    
    $response = curl_exec($ch);
    curl_close($ch);

    return ["status" => "success", "response" => json_decode($response, true)];
}

// Insert a single product for testing
function insertSingleProduct() {
    global $config;

    // Hardcoded product data
    $product = [
        "handle" => "hardcoded-product-123",
        "category" => [
            "path" => [
                [
                    "lv" => "Profesionālā maltā kafija"
                ]
            ]
        ],
        "title" => "Hardcoded Product",
        "description" => "This is a hardcoded product for testing.",
        "price" => 19.99,
        "sale_price" => 17.99,
        "stock" => 50,
        "sku" => "HARDCODED123",
        "visible" => "FALSE",
        "featured" => "FALSE",
        "vendor" => "TestVendor",
        "pictures" => [] // Bildes tiks pievienotas vēlāk
    ];


    $imageUrl = "https://epromo.imgix.net/image/209fcebf-c363-480f-a3be-897ba971323e.jpg";

    
    $imageData = file_get_contents($imageUrl);
    if ($imageData === false) {
        return ["status" => "error", "message" => "Nevarēja nolasīt bildi no URL: $imageUrl"];
    }

    $base64Image = base64_encode($imageData);
    
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

    $uploadResponse = uploadImageToMozello($product['handle'], $base64Image);
    if (isset($uploadResponse['uid'])) {
        $product['pictures'][] = [
            "uid" => $uploadResponse['uid'],
            "url" => $uploadResponse['url'] 
        ];
    } else {
        return ["status" => "error", "message" => "Nevarēja augšupielādēt bildi uz Mozello", "response" => $uploadResponse];
    }

    
    $responseData = json_decode($response, true);
    if (isset($responseData['id'])) {
        return ["status" => "success", "message" => "Produkts veiksmīgi izveidots", "response" => $responseData];
    } else {
        return ["status" => "error", "message" => "Nevarēja izveidot produktu", "response" => $responseData];
    }
}

function importCSVFiles() {
    global $config;

    $localFolder = __DIR__ . "/uploads/";
    if (!is_dir($localFolder)) mkdir($localFolder, 0777, true);

    $csvFiles = ["ProductInfo.csv", "Products.csv", "Stock.csv"];
    $formattedData = [];

    // Parse CSV files
    foreach ($csvFiles as $file) {
        $localFile = $localFolder . $file;

        if (!file_exists($localFile)) {
            return ["status" => "error", "message" => "Local file not found: $file"];
        }

        if (($handle = fopen($localFile, "r")) !== FALSE) {
            $headers = fgetcsv($handle, 1000, ";");
            $headers = array_map('trim', $headers);

            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                $data = array_map('trim', $data);
                $formattedData[$file][] = array_combine($headers, $data);
            }
            fclose($handle);
        }
    }

   
    foreach ($formattedData['ProductInfo.csv'] as $productInfo) {


        // CSV category => [markup value, API category, is percentage?]
        $categoryMappings = [
            'Kafijas pupiņas' => [5.00, 'Kafijas pupiņas', false]
        ];

        // SKU or name => fixed markup in EUR
        $productFixedMarkups = [
            'ILLY' => 2.00, 
        ];

        foreach ($formattedData['ProductInfo.csv'] as $productInfo) {
            $csvCategory = $productInfo['Item category'];
            $productSku = $productInfo['INF_PREK'];
            $productName = $productInfo['Name'];

            
            if (!isset($categoryMappings[$csvCategory])) {
                continue; 
            }

            
            list($markupValue, $apiCategory, $isPercentage) = $categoryMappings[$csvCategory];

            
            $fixedMarkup = null;
            foreach ($productFixedMarkups as $key => $amount) {
                if (stripos($productSku, $key) !== false || stripos($productName, $key) !== false) {
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
        }


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
        if (!isset($responseData['id'])) {
            continue; // Skip this product if creation fails
        }

        // Step 3: Upload and associate the image with the created product
        $imageUrl = $productInfo["Photo_URL"];

        // Check if the image URL is valid
        if (empty($imageUrl) || filter_var($imageUrl, FILTER_VALIDATE_URL) === false) {
            continue; // Skip this product if the image URL is invalid
        }

        // Fetch the image data
        $imageData = file_get_contents($imageUrl);

        $base64Image = base64_encode($imageData);

        // Step 4: Upload the image and associate it with the product
        uploadImageToMozello($product['handle'], $base64Image);

    }

    return ["status" => "success", "message" => "Products processed successfully."];
}

function uploadImageToMozello($productHandle, $base64Image) {
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

