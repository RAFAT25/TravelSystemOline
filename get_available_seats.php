<?php
include "connect.php"; // نفس الاتصال الذي تستخدمه هنا (PDO في المتغير $con)

// استلام trip_id (يمكن GET أو JSON)
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

$trip_id = isset($data['trip_id']) ? (int)$data['trip_id'] : 0;
// أو لو أسهل لك: $trip_id = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0;

if ($trip_id <= 0) {
    echo json_encode([
        "success" => false,
        "error"   => "trip_id مطلوب"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$sql = "
SELECT
    s.seat_id,
    s.seat_number
FROM trips t
JOIN buses b   ON b.bus_id = t.bus_id
JOIN seats s   ON s.bus_id = b.bus_id
WHERE
    t.trip_id = :trip_id
    AND s.seat_id NOT IN (
        SELECT ps.seat_id
        FROM bookings b
        JOIN passengers ps ON ps.booking_id = b.booking_id
        WHERE b.trip_id = t.trip_id
    )
ORDER BY s.seat_number;
";

$stmt = $con->prepare($sql);
$stmt->execute([':trip_id' => $trip_id]);

$seats = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($seats)) {
    echo json_encode([
        "success" => true,
        "seats"   => $seats
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "success" => true,
        "seats"   => []   // لا توجد مقاعد متاحة
    ], JSON_UNESCAPED_UNICODE);
}
