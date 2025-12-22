<?php

namespace Travel\Controllers;

use Travel\Helpers\Response;
use Travel\Config\Database;
use PDO;
use Exception;
use RuntimeException;

/**
 * StripeController - Standalone Webhook Handler
 * Handles Stripe events without requiring the Stripe PHP SDK.
 */
class StripeController {
    private $conn;
    private $webhook_secret;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
        
        // جلب مفتاح الربط من البيئة
        $this->webhook_secret = getenv('STRIPE_WEBHOOK_SECRET');
        
        if (empty($this->webhook_secret)) {
            // CRITICAL: Prevent operation if secret is missing
            throw new RuntimeException("CRITICAL: STRIPE_WEBHOOK_SECRET environment variable is missing.");
        }
    }

    /**
     * Create a Stripe Checkout Session
     * POST /api/payment/stripe/create-session
     */
    public function createSession() {
        header('Content-Type: application/json');

        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $booking_id = $data['booking_id'] ?? null;

        if (!$booking_id) {
            Response::error("booking_id is required", 400);
            return;
        }

        try {
            // 1. جلب بيانات الحجز من القاعدة
            $stmt = $this->conn->prepare("SELECT total_price, booking_status FROM bookings WHERE booking_id = :bid");
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                Response::notFound("Booking not found");
                return;
            }

            // 2. إعداد طلب Stripe عبر CURL
            $stripe_secret = getenv('STRIPE_SECRET_KEY') ?: 'sk_test_secret';
            $amount = (int)($booking['total_price'] * 100); // تحويل للقروش (Cents)
            
            $postFields = http_build_query([
                'payment_method_types[]' => 'card',
                'line_items[0][price_data][currency]' => 'usd',
                'line_items[0][price_data][product_data][name]' => "Travel Booking #$booking_id",
                'line_items[0][price_data][unit_amount]' => $amount,
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => 'https://your-domain.com/success?booking_id=' . $booking_id,
                'cancel_url' => 'https://your-domain.com/cancel',
                'metadata[booking_id]' => $booking_id
            ]);

            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
            curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret . ':');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);

            if ($httpCode !== 200) {
                Response::error($result['error']['message'] ?? 'Stripe API Error', 502);
                return;
            }

            Response::success([
                "url" => $result['url'],
                "session_id" => $result['id']
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * POST /api/webhooks/stripe
     */
    public function handleWebhook() {
        header('Content-Type: application/json');

        $payload = file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (empty($payload) || empty($sig_header)) {
            Response::error("Empty payload or signature", 400);
            return;
        }

        // 1. التحقق من التوقيع الرقمي (Signature Verification) يدوياً
        if (!$this->verifySignature($payload, $sig_header)) {
            Response::error("Invalid signature", 400);
            return;
        }

        $event = json_decode($payload, true);
        $eventType = $event['type'] ?? '';

        try {
            switch ($eventType) {
                case 'checkout.session.completed':
                    $session = $event['data']['object'];
                    $this->handlePaymentSuccess($session);
                    break;
                
                // يمكن إضافة حالات أخرى هنا (مثل دفع فاشل)
            }

            Response::success();
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * التوثيق اليدوي لتوقيع Stripe
     */
    private function verifySignature($payload, $sig_header) {
        $parts = explode(',', $sig_header);
        $timestamp = null;
        $signatures = [];

        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) continue;
            
            if (trim($kv[0]) === 't') $timestamp = trim($kv[1]);
            if (trim($kv[0]) === 'v1') $signatures[] = trim($kv[1]);
        }

        if (is_null($timestamp) || empty($signatures)) return false;

        // التحقق من الوقت (مثلاً: السماح بـ 5 دقائق فرق)
        if (abs(time() - (int)$timestamp) > 300) return false;

        // حساب الـ Hash المتوقع
        $signed_payload = $timestamp . '.' . $payload;
        $expected_signature = hash_hmac('sha256', $signed_payload, $this->webhook_secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected_signature, $sig)) return true;
        }

        return false;
    }

    /**
     * معالجة الدفع الناجح وتحديث الحجز
     */
    private function handlePaymentSuccess($session) {
        $booking_id = $session['metadata']['booking_id'] ?? null;
        $transaction_id = $session['id'] ?? '';

        if (!$booking_id) return;

        // استخدام دالة updatePayment الموجودة في BookingController أو تحديث مباشر هنا
        // نختار التحديث المباشر لضمان الاستقلالية في الويب هوك
        $stmt = $this->conn->prepare("
            UPDATE bookings 
            SET payment_status = 'Paid',
                payment_method = 'Electronic',
                gateway_transaction_id = :txn,
                payment_timestamp = CURRENT_TIMESTAMP
            WHERE booking_id = :bid AND payment_status != 'Paid'
        ");
        
        $stmt->execute([
            ':txn' => $transaction_id,
            ':bid' => $booking_id
        ]);
        
        // النظام سيقوم تلقائياً بتفعيل Triggers العمولات والإشعارات 
        // لأننا حددنا الحالة إلى Paid
    }
}
