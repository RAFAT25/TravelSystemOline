<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Travel\Controllers\AuthController;
use Travel\Controllers\BookingController;
use Travel\Middleware\AuthMiddleware;
use Dotenv\Dotenv;

// Load env variables if .env exists (local dev)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Simple Router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Add CORS support
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Route Dispatcher
switch ($uri) {
    case '/api/login':
        if ($method === 'POST') {
            $controller = new AuthController();
            $controller->login();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings':
        if ($method === 'POST') {
            // Protect Route
            $middleware = new AuthMiddleware();
            $user = $middleware->validateToken(); // Will exit if invalid
            
            $controller = new BookingController();
            $controller->create();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/notifications/send-test':
        if ($method === 'POST') {
            $controller = new \Travel\Controllers\NotificationController();
            $controller->sendTest();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/notifications/send':
    case '/send_notification.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../send_notification.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/fcm/save-token':
    case '/save_fcm_token.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../save_fcm_token.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/trips/search':
    case '/serchTrip.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../serchTrip.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/seats/available':
    case '/get_available_seats.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../get_available_seats.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings/create':
    case '/create_booking.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../create_booking.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/payment/update':
    case '/payment_update.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../payment_update.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/auth/register':
    case '/register_user.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../register_user.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/auth/login':
    case '/login_user.php':
        if ($method === 'POST') {
            require_once __DIR__ . '/../login_user.php';
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/':
    case '/index.php':
         echo json_encode(["message" => "Welcome to Travel System API"]);
         break;

    default:
        http_response_code(404);
        echo json_encode(["error" => "Endpoint not found: " . $uri]);
        break;
}
