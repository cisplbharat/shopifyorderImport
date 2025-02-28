<?php

class OrderManager
{
    private static $stickyApiUrl = ''; // sticky api yurl
    private static $stickyCredentials = ""; // crm credentials 
    private static $shopifyUrl = '';// shopify url
    private static $accessToken = '';  //put shopify access Token

    // Fetch order details from Sticky API
    public static function getOrderDetails($orderId)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => self::$stickyApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['order_id' => [$orderId]]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => self::$stickyCredentials
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }

    // Fetch product variant ID from Shopify using SKU
    public static function getVariantIdFromShopify($sku)
    {
        $shopifyProductUrl = 'https://937c3df-2.myshopify.com/admin/api/2025-01/products.json?sku=' . $sku;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $shopifyProductUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Shopify-Access-Token: ' . self::$accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        $data = json_decode($response, true);
        if (!empty($data['products'])) {
            foreach ($data['products'] as $product) {
                foreach ($product['variants'] as $variant) {
                    if ($variant['sku'] === $sku) {
                        return $variant['id'];
                    }
                }
            }
        }

        return null;
    }

    // Create an order in Shopify
    public static function createShopifyOrder($products, $customerEmail, $billingAddress, $shippingAddress)
    {
        $lineItems = array_map(function ($product) {
            $variantId = self::getVariantIdFromShopify($product['sku']);
            if ($variantId) {
                return [
                    'variant_id' => $variantId,
                    'quantity' => $product['product_qty'],
                    'price' => $product['price'],
                    'title' => $product['name']
                ];
            }
            return null;
        }, $products);

        // Filter out any null values
        $lineItems = array_filter($lineItems);

        // Calculate the total price
        $totalPrice = array_reduce($lineItems, function ($sum, $item) {
            return $sum + ($item['price'] * $item['quantity']);
        }, 0);

        $orderData = [
            'order' => [
                'line_items' => $lineItems,
                'email' => $customerEmail,
                'financial_status' => 'paid',
                'total_price' => $totalPrice,  // Update the total price
                'status' => 'open',
                'send_receipt' => true,
                'billing_address' => $billingAddress,
                'shipping_address' => $shippingAddress,
                'transactions' => [
                    [
                        'kind' => 'sale',
                        'status' => 'success',
                        'amount' => $totalPrice  // Ensure the transaction amount is set
                    ]
                ]
            ]
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => self::$shopifyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Shopify-Access-Token: ' . self::$accessToken,
                'Content-Type: application/json'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($orderData)
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }

    // Fetch product details from Shopify using SKU ID
    public static function getProductInfoFromShopify($sku)
    {
        $shopifyProductUrl = 'https://937dc3f-2.myshopify.com/admin/api/2025-01/products.json?sku=' . $sku;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $shopifyProductUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Shopify-Access-Token: ' . self::$accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($curl);
        $data=json_decode($response, true);
        curl_close($curl);
        echo "dataresp<pre";
        print_r($data);
        echo "</pre>";

        return json_decode($response, true);
    }

 

    // Merge products from parent and sub-orders
    public static function mergeProducts($parentOrderId)
    {
        $parentOrder = self::getOrderDetails($parentOrderId);
       

        $mergedProducts = [];

        if ($parentOrder && isset($parentOrder['order_id'], $parentOrder['parent_id']) && $parentOrder['order_id'] == $parentOrder['parent_id']) {
            $mergedProducts = array_merge($mergedProducts, $parentOrder['products'] ?? []);

            foreach ($parentOrder['systemNotes'] ?? [] as $note) {
                if (preg_match('/New Order Id (\d+)/', $note, $matches)) {
                    $subOrderId = $matches[1];
                    $subOrder = self::getOrderDetails($subOrderId);

                    if ($subOrder && isset($subOrder['order_id'])) {
                        $mergedProducts = array_merge($mergedProducts, $subOrder['products'] ?? []);
                    }
                }
            }
        }

        return $mergedProducts;
    }

    // Main process to create Shopify order
    public static function processOrder($parentOrderId)
    {
        // Log the order ID with date and time
        $logMessage = date('Y-m-d H:i:s') . " - Processing order ID: " . $parentOrderId . PHP_EOL;
        file_put_contents('order_log.txt', $logMessage, FILE_APPEND);

        // Delay the process by 10 minutes
        //sleep(300);

        $parentOrder = self::getOrderDetails($parentOrderId);

        // Extract billing and shipping addresses from parent order
        $billingAddress = [
            'first_name' => $parentOrder['billing_first_name'] ?? '',
            'last_name' => $parentOrder['billing_last_name'] ?? '',
            'address1' => $parentOrder['billing_street_address'] ?? '',
            'phone' => $parentOrder['billing_phone'] ?? '',
            'city' => $parentOrder['billing_city'] ?? '',
            'province' => $parentOrder['billing_state'] ?? '',
            'country' => $parentOrder['billing_country'] ?? '',
            'zip' => $parentOrder['billing_postcode'] ?? ''
        ];

        $shippingAddress = [
            'first_name' => $parentOrder['shipping_first_name'] ?? '',
            'last_name' => $parentOrder['shipping_last_name'] ?? '',
            'address1' => $parentOrder['shipping_street_address'] ?? '',
            'phone' => $parentOrder['shipping_phone'] ?? '',
            'city' => $parentOrder['shipping_city'] ?? '',
            'province' => $parentOrder['shipping_state'] ?? '',
            'country' => $parentOrder['shipping_country'] ?? '',
            'zip' => $parentOrder['shipping_postcode'] ?? ''
        ];

        $customerEmail = $parentOrder['email_address'] ?? '';

        $mergedProducts = self::mergeProducts($parentOrderId);
          echo "<pre>mergedProducts\n";
            print_r($mergedProducts);
            echo "</pre>";

        if (!empty($mergedProducts)) {
            $shopifyResponse = self::createShopifyOrder($mergedProducts, $customerEmail, $billingAddress, $shippingAddress);
            echo "<pre>Shopify Order Creation Response:\n";
            print_r($shopifyResponse);
            echo "</pre>";

        } else {
            echo "No products found to create Shopify order.";
        }
    }
}

// Example usage
$parentOrderId = $_GET['order_id'];  // Replace with actual parent order ID

OrderManager::processOrder($parentOrderId);