<?php
// public/get_user_bookings.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $con = getConnection();

    // قراءة بيانات JSON من الطلب
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;

    if ($userId <= 0) {
        echo json_encode([
            "success" => false,
            "error"   => "رقم المستخدم غير صالح"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        SELECT
            u.user_id,
            u.full_name,
            b.booking_id,
            b.booking_status       AS booking_status,
            t.trip_id,
            t.departure_time,
            t.arrival_time,
            r.origin_city,
            r.destination_city,
            bc.class_name          AS bus_class,
            p.company_name,
            t.base_price
        FROM users u
        JOIN bookings b     ON b.user_id      = u.user_id
        JOIN trips t        ON t.trip_id      = b.trip_id
        JOIN routes r       ON t.route_id     = r.route_id
        JOIN buses bu       ON t.bus_id       = bu.bus_id
        JOIN bus_classes bc ON bu.bus_class_id = bc.bus_class_id
        JOIN partners p     ON t.partner_id   = p.partner_id
        WHERE u.user_id = :user_id
        ORDER BY b.booking_id DESC
    ";

    $stmt = $con->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success"  => true,
        "count"    => count($rows),
        "bookings" => $rows
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => "خطأ في السيرفر: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
