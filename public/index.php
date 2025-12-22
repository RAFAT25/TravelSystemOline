<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Travel\Controllers\AuthController;
use Travel\Controllers\BookingController;
use Travel\Controllers\TripController;
use Travel\Controllers\NotificationController;
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
    case '/api/auth/login':
    case '/login_user.php':
        if ($method === 'POST') {
            $controller = new AuthController();
            $controller->login();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/auth/register':
    case '/register_user.php':
        if ($method === 'POST') {
            $controller = new AuthController();
            $controller->register();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings':
    case '/api/bookings/create':
    case '/create_booking.php':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $middleware->validateToken();
            
            $controller = new BookingController();
            $controller->create();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings/user':
    case '/get_user_bookings.php':
        if ($method === 'POST' || $method === 'GET') {
            // Optional: Add AuthMiddleware if security is required for this endpoint
            // $middleware = new AuthMiddleware();
            // $middleware->validateToken();

            $controller = new BookingController();
            $controller->getUserBookings();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/payment/update':
    case '/payment_update.php':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $middleware->validateToken();

            $controller = new BookingController();
            $controller->updatePayment();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings/cancel-preview':
        if ($method === 'GET' || $method === 'POST') {
            $controller = new \Travel\Controllers\CancelController();
            $controller->preview();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings/cancel':
        if ($method === 'POST') {
             // Optional: AuthMiddleware check if needed
            $controller = new \Travel\Controllers\CancelController();
            $controller->confirm();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/trips/search':
    case '/serchTrip.php':
        if ($method === 'POST' || $method === 'GET') {
            $controller = new TripController();
            $controller->search();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/seats/available':
    case '/get_available_seats.php':
        if ($method === 'POST' || $method === 'GET') {
            $controller = new TripController();
            $controller->getAvailableSeats();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/notifications/send':
    case '/send_notification.php':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $middleware->validateToken();

            $controller = new NotificationController();
            $controller->send();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/fcm/save-token':
    case '/save_fcm_token.php':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $middleware->validateToken();

            $controller = new NotificationController();
            $controller->saveToken();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/notifications/send-test':
        if ($method === 'POST') {
            $controller = new NotificationController();
            $controller->sendTest();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/support/ticket':
    case '/create_ticket.php':
        if ($method === 'POST') {
            $controller = new \Travel\Controllers\SupportController();
            $controller->createTicket();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings/confirm':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            // Require employee role
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\BookingController();
            $controller->confirmBooking($actor);
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/bookings/submit-payment-proof':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $controller = new \Travel\Controllers\BookingController();
            $controller->submitPaymentProof($actor);
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/support/faqs/list':
    case '/faqs_categories.php':
        if ($method === 'GET' || $method === 'POST') {
            // Allowing POST as well since some legacy clients might misuse it, but strictly it's a GET
            $controller = new \Travel\Controllers\SupportController();
            $controller->getFaqList();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/auth/update-profile':
    case '/update_profile.php':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();

            $controller = new AuthController();
            $controller->updateProfile($actor);
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/trips/search-ar':
    case '/get_available_trips_ar.php':
        if ($method === 'GET' || $method === 'POST') {
             // Allowing GET/POST as per original script usage might be needed, assuming POST based on typical API
            $controller = new TripController();
            $controller->searchArabic();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/webhooks/stripe':
        if ($method === 'POST') {
            $controller = new \Travel\Controllers\StripeController();
            $controller->handleWebhook();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/payment/stripe/create-session':
        if ($method === 'POST') {
            $controller = new \Travel\Controllers\StripeController();
            $controller->createSession();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/commissions/partner':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\CommissionController();
            $controller->getPartnerCommissions();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/commissions/daily-summary':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\CommissionController();
            $controller->getDailySummary();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/commissions/booking':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $controller = new \Travel\Controllers\CommissionController();
            $controller->getBookingCommission();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/commissions/mark-paid':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireAdmin($actor);
            
            $controller = new \Travel\Controllers\CommissionController();
            $controller->markAsPaid();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/commissions/partner/stats':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\CommissionController();
            $controller->getPartnerStats();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/commissions/partial-refund':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\CommissionController();
            $controller->handlePartialRefund();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/commissions/refunds':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\CommissionController();
            $controller->getRefundReports();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/refunds/process':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\RefundController();
            $controller->processRefund($actor);
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/refunds/complete':
        if ($method === 'POST') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\RefundController();
            $controller->completeRefund($actor);
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/refunds/list':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $roleMiddleware = new \Travel\Middleware\RoleMiddleware();
            $roleMiddleware->requireEmployee($actor);
            
            $controller = new \Travel\Controllers\RefundController();
            $controller->getRefunds();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/refunds/booking':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $controller = new \Travel\Controllers\RefundController();
            $controller->getRefundByBooking();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/refunds/calculate-fee':
        if ($method === 'GET') {
            $middleware = new AuthMiddleware();
            $actor = $middleware->validateToken();
            
            $controller = new \Travel\Controllers\RefundController();
            $controller->calculateFee();
        } else {
            http_response_code(405);
            echo json_encode(["error" => "Method not allowed"]);
        }
        break;

    case '/api/notifications/whatsapp':
    case '/sendwats.php':
        if ($method === 'POST') {
            // Optional: AuthMiddleware
            $controller = new NotificationController();
            $controller->sendWhatsApp();
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
