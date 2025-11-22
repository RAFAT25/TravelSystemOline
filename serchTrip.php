<?php
include "connect.php"; // يجب أن يحتوي اتصال PDO باسم $con

$input = file_get_contents('php://input');
$data = json_decode($input, true);

// استقبل المتغيرات
$from_stop   = isset($data['from_stop'])   ? $data['from_stop']   : '';
$to_city     = isset($data['to_city'])     ? $data['to_city']     : '';
$date        = isset($data['date'])        ? $data['date']        : '';
$bus_class   = isset($data['bus_class'])   ? $data['bus_class']   : '';

// تحقق من البيانات المطلوبة
if (empty($from_stop) || empty($to_city) || empty($date) || empty($bus_class)) {
    echo json_encode([
        "success" => false,
        "error"   => "جميع الحقول مطلوبة"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// الاستعلام
$sql = "
SELECT
    t.trip_id,
    t.departure_time,
    t.arrival_time,
    t.base_price,
    r.origin_city,
    r.destination_city,
    rs_from.stop_name   AS from_stop,
    rs_to.stop_name     AS to_stop,
    bu.model,
    bc.class_name       AS bus_class,
    p.company_name
FROM Trips t
JOIN Routes r         ON t.route_id = r.route_id
JOIN RouteStops rs_from ON rs_from.route_id = r.route_id AND rs_from.stop_name = :from_stop
JOIN RouteStops rs_to   ON rs_to.route_id = r.route_id AND rs_to.stop_name = :to_city
JOIN Buses bu         ON t.bus_id = bu.bus_id
JOIN BusClasses bc    ON bu.bus_class_id = bc.bus_class_id
JOIN Partners p       ON t.partner_id = p.partner_id
WHERE DATE(t.departure_time) = :date
  AND rs_from.stop_order < rs_to.stop_order
  AND bc.class_name = :bus_class
";

// نفذ الاستعلام مع التصريحات
$stmt = $con->prepare($sql);
$stmt->execute([
    ':from_stop' => $from_stop,
    ':to_city'   => $to_city,
    ':date'      => $date,
    ':bus_class' => $bus_class
]);

$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// النتائج
if ($trips) {
    echo json_encode([
        "success" => true,
        "trips"   => $trips
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "success" => false,
        "error"   => "لا توجد رحلات مطابقة"
    ], JSON_UNESCAPED_UNICODE);
}
?>
