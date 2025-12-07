<?php
header('Content-Type: application/json; charset=utf-8');
include "connect.php";

try {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    $booking_id     = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
    $payment_status = isset($data['payment_status']) ? trim($data['payment_status']) : '';
    $payment_method = isset($data['payment_method']) ? trim($data['payment_method']) : '';
    $transaction_id = isset($data['transaction_id']) ? trim($data['transaction_id']) : '';

    if ($booking_id <= 0 || $payment_status === '') {
        echo json_encode([
            "success" => false,
            "error"   => "booking_id و payment_status مطلوبة"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $allowed = ['pending', 'paid', 'failed'];
    if (!in_array($payment_status, $allowed, true)) {
        echo json_encode([
            "success" => false,
            "error"   => "قيمة payment_status غير صحيحة"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $sql = "
        UPDATE bookings
        SET payment_status = :status,
            payment_method = COALESCE(NULLIF(:method, ''), payment_method),
            transaction_id = COALESCE(NULLIF(:txn, ''), transaction_id)
        WHERE booking_id = :booking_id
    ";

    $stmt = $con->prepare($sql);
    $stmt->execute([
        ':status'     => $payment_status,
        ':method'     => $payment_method,
        ':txn'        => $transaction_id,
        ':booking_id' => $booking_id,
    ]);

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            "success"    => true,
            "booking_id" => $booking_id,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "success" => false,
            "error"   => "لم يتم العثور على الحجز أو لا يوجد تغيير"
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
