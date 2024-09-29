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

// Función para obtener la fecha y hora actual o ajustada
function getFormattedDateTime($modifier = null) {
    $date = new DateTime();
    if ($modifier) {
        $date->modify($modifier);
    }
    return $date->format('Y-m-d\TH:i:s.v\Z');
}

// Función para hacer solicitudes a la API de WooCommerce
function makeWooCommerceRequest($client, $method, $endpoint, $data = []) {
    $url = "https://sominastock.com/wp-json/wc/v3/$endpoint";
    $consumerKey = $_ENV['CONSUMERKEY'];
    $consumerSecret = $_ENV['CONSUMERSECRET'];

    try {
        $response = $client->request($method, $url, [
            'auth' => [$consumerKey, $consumerSecret],
            'json' => $data
        ]);
        return json_decode($response->getBody(), true);
    } catch (RequestException $e) {
        echo "Error in WooCommerce API request: " . $e->getMessage() . PHP_EOL;
        return null;
    }
}

// Función para obtener producto por SKU desde WooCommerce
function getProductBySku($client, $sku) {
    echo "Fetching product with SKU: $sku" . PHP_EOL;
    $products = makeWooCommerceRequest($client, 'GET', 'products', ['sku' => $sku]);
    
    if ($products && count($products) > 0) {
        echo "Product found: " . $products[0]['name'] . PHP_EOL;
        return $products[0];
    } else {
        echo "Product not found for SKU: $sku" . PHP_EOL;
        return null;
    }
}

// Función para crear una orden en WooCommerce
function createOrder($client, $products, $customerDetails) {
    $lineItems = array_map(function($product) {
        return [
            'product_id' => $product['productId'],
            'quantity' => $product['productQuantity']
        ];
    }, $products);

    $orderData = [
        'payment_method' => 'bacs',
        'payment_method_title' => 'Direct Bank Transfer',
        'set_paid' => true,
        'billing' => $customerDetails,
        'line_items' => $lineItems,
        'shipping_lines' => [
            [
                'method_id' => 'flat_rate',
                'method_title' => 'Flat Rate',
                'total' => '0.00'
            ]
        ]
    ];

    $order = makeWooCommerceRequest($client, 'POST', 'orders', $orderData);
    
    if ($order) {
        echo "Order created successfully with ID: " . $order['id'] . PHP_EOL;
        updateOrderStatus($client, $order['id'], 'on-hold');
        return $order['id'];
    }

    return null;
}

// Función para actualizar el estado de la orden en WooCommerce
function updateOrderStatus($client, $orderId, $status) {
    echo "Updating order status to '$status'..." . PHP_EOL;
    makeWooCommerceRequest($client, 'PUT', "orders/{$orderId}", ['status' => $status]);
    echo "Order status updated to '$status' successfully." . PHP_EOL;
}

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
        
        // Procesar los resultados
        processOrders($client, $data['results']);

    } catch (RequestException $e) {
        echo "Error fetching data from Mercado Libre: " . $e->getMessage() . PHP_EOL;
    }
}

// Procesar las órdenes de Mercado Libre y crear órdenes en WooCommerce
function processOrders($client, $orders) {
    if (!$orders || count($orders) == 0) {
        echo "No orders found within the specified date range." . PHP_EOL;
        return;
    }

    echo "Orders found: " . count($orders) . PHP_EOL;
    $products = [];
    $customerDetails = [];

    foreach ($orders as $order) {
        foreach ($order['order_items'] as $item) {
            if (!empty($item['item']['seller_sku'])) {
                list($sku, $qty) = explode('*', $item['item']['seller_sku']);
                $product = getProductBySku($client, $sku);

                if ($product) {
                    $customerDetails[] = getCustomerDetails($order);
                    $products[] = ['productId' => $product['id'], 'productQuantity' => (int) $qty];
                }
            }
        }
    }

    if (count($products) > 0 && !empty($customerDetails)) {
        createOrder($client, $products, $customerDetails[0]);
    } else {
        echo "No valid products or customer details found to create an order." . PHP_EOL;
    }
}

// Función para obtener los detalles del cliente
function getCustomerDetails($order) {
    return [
        'first_name' => $order['buyer']['nickname'],
        'last_name' => '',
        'address_1' => 'Agencia mercadolibre',
        'city' => 'Bogota',
        'state' => 'BO',
        'postcode' => '110111',
        'country' => 'CO',
        'email' => 'mercadolibre@gmail.com.co',
        'phone' => '1234567890'
    ];
}

// Construir la URL para la solicitud a la API de Mercado Libre
$fromDate = getFormattedDateTime('-5 minutes');
$currentDate = getFormattedDateTime();
$url = "https://api.mercadolibre.com/orders/search?seller=289940107&order.date_created.from={$fromDate}&order.date_created.to={$currentDate}";

// Obtener el token de acceso desde las variables de entorno
$accessToken = $_ENV['ACCESS_TOKEN'];

// Función para registrar la hora de ejecución en el archivo storyCrons.txt
function logExecutionTime() {
    // Obtener la fecha y hora actual en formato ISO 8601 con milisegundos
    $currentDateTime = (new DateTime())->format('Y-m-d H:i:s.v');

    // Definir el nombre del archivo
    $filename = 'storyCrons.txt';

    // Crear el mensaje con la hora de ejecución
    $logMessage = "Execution Time: ". $currentDateTime
                                    ."SKU" . $sku
                                    ."Orden" . $orderId
                                    . PHP_EOL;

    // Escribir o agregar el mensaje al archivo (APPEND asegura que no borre las ejecuciones anteriores)
    file_put_contents($filename, $logMessage, FILE_APPEND);
}

// Llamar a la función para obtener datos de la API de Mercado Libre
fetchMercadoLibreData($client, $url, $accessToken);
logExecutionTime();

?>
