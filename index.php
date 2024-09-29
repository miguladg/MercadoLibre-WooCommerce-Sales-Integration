<?php
require 'vendor/autoload.php'; // Cargar dependencias de Composer
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Cargar variables de entorno
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Inicializar cliente HTTP
$client = new Client();

// Obtener la fecha y hora actual en formato ISO 8601 con milisegundos y zona horaria Z
function getCurrentDateTime() {
    return (new DateTime())->format('Y-m-d\TH:i:s.v\Z');
}

// Calcular la fecha y hora de hace 1 hora en formato ISO 8601 con milisegundos y zona horaria Z
function getDateMinus1Hour() {
    $date = new DateTime();
    $date->modify('-5 minutes');
    return $date->format('Y-m-d\TH:i:s.v\Z');
}

// Función para obtener producto por SKU desde WooCommerce
function getProductBySku($client, $sku) {
    $urlProducts = 'https://sominastock.com/wp-json/wc/v3/products';
    $consumerKey = $_ENV['CONSUMERKEY'];
    $consumerSecret = $_ENV['CONSUMERSECRET'];

    try {
        echo "Fetching product with SKU: $sku" . PHP_EOL;
        $response = $client->request('GET', $urlProducts, [
            'auth' => [$consumerKey, $consumerSecret],
            'query' => ['sku' => $sku]
        ]);

        $data = json_decode($response->getBody(), true);

        if (count($data) > 0) {
            echo "Product found: " . $data[0]['name'] . PHP_EOL;
            return $data[0];
        } else {
            echo "Product not found for SKU: $sku" . PHP_EOL;
            return null;
        }
    } catch (RequestException $e) {
        echo "Error fetching product by SKU: " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

// Función para crear una orden en WooCommerce
function createOrder($client, $products, $customerDetails) {
    $urlW = 'https://sominastock.com/wp-json/wc/v3/orders';
    $consumerKey = $_ENV['CONSUMERKEY'];
    $consumerSecret = $_ENV['CONSUMERSECRET'];

    $lineItems = array_map(function($product) {
        return [
            'product_id' => $product['productId'],
            'quantity' => $product['productQuantity']
        ];
    }, $products);

    $dataW = [
        'payment_method' => 'bacs',
        'payment_method_title' => 'Direct Bank Transfer',
        'set_paid' => true,
        'billing' => [
            'first_name' => $customerDetails['firstName'],
            'last_name' => $customerDetails['lastName'],
            'address_1' => $customerDetails['address'],
            'city' => $customerDetails['city'],
            'state' => $customerDetails['state'],
            'postcode' => $customerDetails['postcode'],
            'country' => $customerDetails['country'],
            'email' => $customerDetails['email'],
            'phone' => $customerDetails['phone']
        ],
        'line_items' => $lineItems,
        'shipping_lines' => [
            [
                'method_id' => 'flat_rate',
                'method_title' => 'Flat Rate',
                'total' => '0.00'
            ]
        ]
    ];

    try {
        echo "Creating order in WooCommerce..." . PHP_EOL;
        $response = $client->request('POST', $urlW, [
            'auth' => [$consumerKey, $consumerSecret],
            'json' => $dataW
        ]);

        $responseData = json_decode($response->getBody(), true);
        echo "Order created successfully with ID: " . $responseData['id'] . PHP_EOL;

        // Actualizar estado de la orden a 'on-hold'
        updateOrderStatus($client, $responseData['id'], 'on-hold');
        return $responseData['id'];
    } catch (RequestException $e) {
        echo "Error creating order: " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

// Función para actualizar el estado de la orden en WooCommerce
function updateOrderStatus($client, $orderId, $status) {
    $urlW = "https://sominastock.com/wp-json/wc/v3/orders/{$orderId}";
    $consumerKey = $_ENV['CONSUMERKEY'];
    $consumerSecret = $_ENV['CONSUMERSECRET'];

    $dataW = [
        'status' => $status
    ];

    try {
        echo "Updating order status to '$status'..." . PHP_EOL;
        $client->request('PUT', $urlW, [
            'auth' => [$consumerKey, $consumerSecret],
            'json' => $dataW
        ]);
        echo "Order status updated to '$status' successfully." . PHP_EOL;
    } catch (RequestException $e) {
        echo "Error updating order status: " . $e->getMessage() . PHP_EOL;
    }
}

// Construir la URL para la solicitud a la API de Mercado Libre
$fromDate = getDateMinus1Hour();
$currentDate = getCurrentDateTime();
$url = "https://api.mercadolibre.com/orders/search?seller=289940107&order.date_created.from={$fromDate}&order.date_created.to={$currentDate}";

// Obtener el token de acceso desde las variables de entorno
$accessToken = $_ENV['ACCESS_TOKEN'];

// Función para obtener datos de la API de Mercado Libre
function fetchMercadoLibreData($client, $url, $accessToken) {
    try {
        echo "Fetching orders from Mercado Libre..." . PHP_EOL;
        $response = $client->request('GET', $url, [
            'headers' => [
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json'
            ]
        ]);

        $data = json_decode($response->getBody(), true);

        // Procesar y filtrar los resultados
        if (isset($data['results']) && count($data['results']) > 0) {
            echo "Orders found: " . count($data['results']) . PHP_EOL;

            $filteredResults = array_map(function($result) {
                return [
                    'buyer_nickname' => $result['buyer']['nickname'],
                    'items' => array_map(function($item) {
                        return [
                            'title' => $item['item']['title'],
                            'unit_price' => $item['unit_price'],
                            'quantity' => $item['quantity'],
                            'seller_sku' => $item['item']['seller_sku']
                        ];
                    }, $result['order_items']),
                    'payments' => array_map(function($payment) {
                        return [
                            'total_paid_amount' => $payment['total_paid_amount'],
                            'date_approved' => $payment['date_approved']
                        ];
                    }, $result['payments'])
                ];
            }, $data['results']);

            // Ajustar cantidades de los ítems basado en el SKU del vendedor
            foreach ($filteredResults as &$order) {
                foreach ($order['items'] as &$item) {
                    if (!empty($item['seller_sku'])) {
                        list($sku, $qty) = explode('*', $item['seller_sku']);
                        if ($qty && is_numeric($qty)) {
                            $item['quantity'] = (int) $qty;
                        }
                        $item['seller_sku'] = $sku;
                    } else {
                        echo "Warning: SKU not available for item: {$item['title']}" . PHP_EOL;
                    }
                }
            }

            $products = [];
            $customerDetails = [];

            // Procesar cada orden y obtener detalles del producto
            foreach ($filteredResults as $order) {
                foreach ($order['items'] as $item) {
                    if (!empty($item['seller_sku'])) {
                        $product = getProductBySku($client, $item['seller_sku']);
                        if ($product) {
                            $customerDetails[] = [
                                'firstName' => $order['buyer_nickname'],
                                'lastName' => '',
                                'address' => 'Agencia mercadolibre',
                                'city' => 'Bogota',
                                'state' => 'BO',
                                'postcode' => '110111',
                                'country' => 'CO',
                                'email' => 'mercadolibre@gmail.com.co',
                                'phone' => '1234567890'
                            ];

                            $products[] = ['productId' => $product['id'], 'productQuantity' => $item['quantity']];
                        }
                    }
                }
            }

            // Crear una orden si hay productos válidos y detalles del cliente
            if (count($products) > 0 && !empty($customerDetails)) {
                createOrder($client, $products, $customerDetails[0]);
            } else {
                echo "No valid products or customer details found to create an order." . PHP_EOL;
            }

        } else {
            echo "No orders found within the specified date range." . PHP_EOL;
        }

    } catch (RequestException $e) {
        echo "Error fetching data from Mercado Libre: " . $e->getMessage() . PHP_EOL;
    }
}

// Llamar a la función para obtener datos de la API de Mercado Libre
fetchMercadoLibreData($client, $url, $accessToken);

?>