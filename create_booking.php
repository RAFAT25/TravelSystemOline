<?php
header('Content-Type: application/json; charset=utf-8');
include "connect.php"; // اتصال PDO في $con

try {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    $user_id        = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $trip_id        = isset($data['trip_id']) ? (int)$data['trip_id'] : 0;
    $total_price    = isset($data['total_price']) ? (float)$data['total_price'] : 0;
    $payment_method = isset($data['payment_method']) ? trim($data['payment_method']) : 'Cash';
    $passengers     = isset($data['passengers']) && is_array($data['passengers']) ? $data['passengers'] : [];

    if ($user_id <= 0 || $trip_id <= 0 || empty($passengers)) {
        echo json_encode([
            "success" => false,
            "error"   => "user_id, trip_id و passengers مطلوبة"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $con->beginTransaction();

    // 1) جمع seat_code من الركاب
    $seatCodes = array_map(function ($p) {
        return isset($p['seat_code']) ? trim($p['seat_code']) : '';
    }, $passengers);
    $seatCodes = array_values(array_filter($seatCodes, fn($c) => $c !== ''));

    if (empty($seatCodes)) {
        throw new Exception("لا توجد مقاعد صحيحة في passengers");
    }

    // 2) جلب seat_id لكل مقعد في هذه الرحلة
    $inPlaceholders = implode(',', array_fill(0, count($seatCodes), '?'));
    $sqlSeats = "
        SELECT s.seat_id, s.seat_number
        FROM trips t
        JOIN buses b ON b.bus_id = t.bus_id
        JOIN seats s ON s.bus_id = b.bus_id
        WHERE t.trip_id = ?
          AND s.seat_number IN ($inPlaceholders)
    ";
    $stmtSeats = $con->prepare($sqlSeats);
    $params    = array_merge([$trip_id], $seatCodes);
    $stmtSeats->execute($params);
    $rowsSeats = $stmtSeats->fetchAll(PDO::FETCH_ASSOC);

    if (count($rowsSeats) !== count($seatCodes)) {
        throw new Exception("بعض أرقام المقاعد غير موجودة في هذه الرحلة");
    }

    $codeToId = [];
    foreach ($rowsSeats as $row) {
        $codeToId[$row['seat_number']] = (int)$row['seat_id'];
    }

    // 3) منع الحجز المزدوج للمقاعد
    $seatIds   = array_values(array_map(fn($code) => $codeToId[$code], $seatCodes));
    $inSeatIds = implode(',', array_fill(0, count($seatIds), '?'));

    $sqlCheck = "
        SELECT ps.seat_id
        FROM bookings b
        JOIN passengers ps ON ps.booking_id = b.booking_id
        WHERE b.trip_id = ?
          AND ps.seat_id IN ($inSeatIds)
        FOR UPDATE
    ";
    $stmtCheck   = $con->prepare($sqlCheck);
    $paramsCheck = array_merge([$trip_id], $seatIds);
    $stmtCheck->execute($paramsCheck);
    $taken = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($taken)) {
        $takenIds = implode(',', $taken);
        throw new Exception("بعض المقاعد المختارة محجوزة مسبقاً: seat_id = $takenIds");
    }

    // 4) إنشاء الحجز في bookings
    $sqlBooking = "
        INSERT INTO bookings (
            user_id,
            trip_id,
            total_price,
            booking_status,
            payment_method,
            payment_status,
            booking_date
        )
        VALUES (
            :user_id,
            :trip_id,
            :total_price,
            :booking_status,
            :payment_method,
            :payment_status,
            CURRENT_TIMESTAMP
        )
        RETURNING booking_id
    ";
    $stmtBooking = $con->prepare($sqlBooking);
    $stmtBooking->execute([
        ':user_id'        => $user_id,
        ':trip_id'        => $trip_id,
        ':total_price'    => $total_price,
        ':booking_status' => 'Pending', // booking_status_enum
        ':payment_method' => $payment_method, // payment_method_enum
        ':payment_status' => 'Unpaid',  // payment_status_enum
    ]);
    $booking_id = (int)$stmtBooking->fetchColumn();

    // 5) إدخال الركاب في passengers (مع id_image)
    $sqlPassenger = "
        INSERT INTO passengers (
            booking_id,
            full_name,
            id_number,
            seat_id,
            trip_id,
            gender,
            birth_date,
            phone_number,
            id_image
        )
        VALUES (
            :booking_id,
            :full_name,
            :id_number,
            :seat_id,
            :trip_id,
            :gender,
            :birth_date,
            :phone_number,
            :id_image
        )
    ";
    $stmtPassenger = $con->prepare($sqlPassenger);

    foreach ($passengers as $p) {
        $full_name    = isset($p['full_name']) ? trim($p['full_name']) : '';
        $id_number    = isset($p['id_number']) ? trim($p['id_number']) : '';
        $seat_code    = isset($p['seat_code']) ? trim($p['seat_code']) : '';
        $gender       = isset($p['gender']) ? trim($p['gender']) : null;
        $birth_date   = isset($p['birth_date']) ? trim($p['birth_date']) : null; // YYYY-MM-DD
        $phone_number = isset($p['phone_number']) ? trim($p['phone_number']) : null;
        $id_image     = isset($p['id_image']) ? trim($p['id_image']) : null;     // رابط أو مسار الصورة

        if ($full_name === '' || $seat_code === '') {
            throw new Exception("كل راكب يحتاج اسم كامل و seat_code");
        }
        if (!isset($codeToId[$seat_code])) {
            throw new Exception("مقعد غير معروف: $seat_code");
        }

        $seat_id = $codeToId[$seat_code];

        $stmtPassenger->execute([
            ':booking_id'   => $booking_id,
            ':full_name'    => $full_name,
            ':id_number'    => $id_number,
            ':seat_id'      => $seat_id,
            ':trip_id'      => $trip_id,
            ':gender'       => $gender,
            ':birth_date'   => $birth_date,
            ':phone_number' => $phone_number,
            ':id_image'     => $id_image,
        ]);
    }

    // 6) تحديث حالة المقاعد إلى غير متاحة is_available = FALSE
    $inSeatIdsForUpdate = implode(',', array_fill(0, count($seatIds), '?'));
    $sqlUpdateSeats = "
        UPDATE seats
        SET is_available = FALSE
        WHERE seat_id IN ($inSeatIdsForUpdate)
    ";
    $stmtUpdateSeats = $con->prepare($sqlUpdateSeats);
    $stmtUpdateSeats->execute($seatIds);

    $con->commit();

    echo json_encode([
        "success"     => true,
        "booking_id"  => $booking_id,
        "trip_id"     => $trip_id,
        "total_price" => $total_price,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($con->inTransaction()) {
        $con->rollBack();
    }
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
