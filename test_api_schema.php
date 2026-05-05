<?php
require_once 'c:\xampp\htdocs\peptide\wp-load.php';

$server = rest_get_server();
$routes = $server->get_routes();
$route = $routes['/wc/store/v1/checkout'] ?? null;

if ($route) {
    foreach ($route as $handler) {
        if (isset($handler['methods']) && in_array('POST', $handler['methods'])) {
            echo "POST /wc/store/v1/checkout Schema:\n";
            foreach (['billing_address', 'shipping_address'] as $arg) {
                if (isset($handler['args'][$arg])) {
                    echo "--- $arg ---\n";
                    print_r($handler['args'][$arg]);
                }
            }
        }
    }
} else {
    echo "Route /wc/store/v1/checkout not found.\n";
}
