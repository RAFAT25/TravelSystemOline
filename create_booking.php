<?php
header('Content-Type: application/json; charset=utf-8');

// الاتصال بقاعدة البيانات
require_once 'connect.php'; // يحتوي على $con = new PDO(...);

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        echo json_encode([
            'success' => false,
            'error'   => 'بيانات غير صالحة (JSON)'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // استلام البيانات من Flutter
    $user_id        = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $trip_id        = isset($data['trip_id']) ? (int)$data['trip_id'] : 0;
    $total_price    = isset($data['total_price']) ? (float)$data['total_price'] : 0;
    $payment_method = isset($data['payment_method']) ? $data['payment_method'] : 'Cash'; // افتراضياً كاش
    $passengers     = isset($data['passengers']) && is_array($data['passengers']) ? $data['passengers'] : [];

    /*
      مثال لـ passengers:
      "passengers": [
        {"name": "أحمد", "seat_id": 5},
        {"name": "خالد", "seat_id": 6}
      ]
    */

    if ($user_id <= 0 || $trip_id <= 0 || empty($passengers)) {
        echo json_encode([
            'success' => false,
            'error'   => 'user_id أو trip_id أو قائمة الركاب غير صحيحة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // بدء Transaction
    $con->beginTransaction();

    // إنشاء Booking بحالة Pending + Unpaid (لم يتم الانتقال لشاشة الدفع بعد)
    $sqlBooking = "
        INSERT INTO Bookings
            (user_id, trip_id, total_price, booking_status, payment_method, payment_status, booking_date)
        VALUES
            (:user_id, :trip_id, :total_price, 'Pending', :payment_method, 'Unpaid', NOW())
    ";
    $stmtBooking = $con->prepare($sqlBooking);
    $stmtBooking->execute([
        ':user_id'        => $user_id,
        ':trip_id'        => $trip_id,
        ':total_price'    => $total_price,
        ':payment_method' => $payment_method,
    ]);

    $booking_id = (int)$con->lastInsertId();

    // إدخال الركاب والمقاعد
    $sqlPassenger = "
        INSERT INTO Passengers
            (booking_id, name, seat_id, trip_id)
        VALUES
            (:booking_id, :name, :seat_id, :trip_id)
    ";
    $stmtPassenger = $con->prepare($sqlPassenger);

    foreach ($passengers as $p) {
        $name    = isset($p['name'])    ? trim($p['name'])    : '';
        $seat_id = isset($p['seat_id']) ? (int)$p['seat_id']  : 0;

        if ($name === '' || $seat_id <= 0) {
            $con->rollBack();
            echo json_encode([
                'success' => false,
                'error'   => 'بيانات راكب غير صالحة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $stmtPassenger->execute([
                ':booking_id' => $booking_id,
                ':name'       => $name,
                ':seat_id'    => $seat_id,
                ':trip_id'    => $trip_id,
            ]);
        } catch (PDOException $e) {
            // PostgreSQL: كود الخطأ 23505 يعني unique_violation
            if ($e->getCode() === '23505') {
                // حجز مكرر لنفس المقعد (trip_id, seat_id) بسبب uq_trip_seat
                $con->rollBack();
                echo json_encode([
                    'success' => false,
                    'error'   => 'هذا المقعد تم حجزه بالفعل من قبل عميل آخر. الرجاء تحديث المقاعد واختيار مقعد آخر.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                $con->rollBack();
                echo json_encode([
                    'success' => false,
                    'error'   => 'خطأ أثناء حفظ بيانات الركاب.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    // إكمال المعاملة
    $con->commit();

    echo json_encode([
        'success'    => true,
        'booking_id' => $booking_id,
        'message'    => 'تم حجز المقاعد بنجاح، والحجز في حالة انتظار الدفع'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if ($con->inTransaction()) {
        $con->rollBack();
    }

    echo json_encode([
        'success' => false,
        'error'   => 'خطأ في قاعدة البيانات.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($con->inTransaction()) {
        $con->rollBack();
    }

    echo json_encode([
        'success' => false,
        'error'   => 'خطأ غير متوقع.'
    ], JSON_UNESCAPED_UNICODE);
}
