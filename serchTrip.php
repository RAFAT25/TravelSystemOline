<?php
include "connect.php"; // تأكد أن $con هو اتصال PDO

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$from_stop = isset($data['from_stop']) ? $data['from_stop'] : '';
$to_city   = isset($data['to_city'])   ? $data['to_city']   : '';
$date      = isset($data['date'])      ? $data['date']      : '';
$bus_class = isset($data['bus_class']) ? $data['bus_class'] : '';

if (empty($from_stop) || empty($to_city) || empty($date) || empty($bus_class)) {
    echo json_encode([
        "success" => false,
        "error"   => "جميع الحقول مطلوبة"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// الاستعلام المعدل (إخراج جميع الحقول اللازمة)
$sql = "
SELECT
    t.trip_id,
    t.departure_time,
    t.arrival_time,
    t.base_price AS price_adult,
    (t.base_price - 50) AS price_child,
    r.origin_city,
    r.destination_city,
    rs_from.stop_name AS from_stop,
    rs_to.stop_name   AS to_stop,
    bu.model,
    bc.class_name     AS bus_class,
    p.company_name,
    (
        SELECT COUNT(*) FROM seats s
        WHERE s.bus_id = t.bus_id AND s.is_available IS TRUE
    ) AS availableSeats
FROM trips t
JOIN routes r            ON t.route_id = r.route_id
JOIN route_stops rs_from ON rs_from.route_id = r.route_id AND rs_from.stop_name = :from_stop
JOIN route_stops rs_to   ON rs_to.route_id = r.route_id AND rs_to.stop_name = :to_city
JOIN buses bu            ON t.bus_id = bu.bus_id
JOIN bus_classes bc      ON bu.bus_class_id = bc.bus_class_id
JOIN partners p          ON t.partner_id = p.partner_id
WHERE DATE(t.departure_time) = :date
  AND rs_from.stop_order < rs_to.stop_order
  AND bc.class_name = :bus_class
";

$stmt = $con->prepare($sql);
$stmt->execute([
    ':from_stop' => $from_stop,
    ':to_city'   => $to_city,
    ':date'      => $date,
    ':bus_class' => $bus_class
]);

$trips = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // حساب مدة الرحلة (duration)
    $dep = new DateTime($row['departure_time']);
    $arr = new DateTime($row['arrival_time']);
    $interval = $dep->diff($arr);
    $duration = $interval->h . " ساعات, " . $interval->i . " دقائق";

    // تجهيز الخريطة النهائية (متوافقة مع BusTrip بالكامل) - مع حماية availableSeats
    $trips[] = [
        "trip_id"         => $row['trip_id'],
        "departure_time"  => $row['departure_time'],
        "arrival_time"    => $row['arrival_time'],
        "origin_city"     => $row['origin_city'],
        "destination_city"=> $row['destination_city'],
        "bus_class"       => $row['bus_class'],
        "price_adult"     => $row['price_adult'],
        "price_child"     => $row['price_child'],
        // السطر التالي يضمن أن availableSeats لا تظهر null أبدًا
        "availableSeats"  => isset($row['availableSeats']) && $row['availableSeats'] !== null ? $row['availableSeats'] : 0,
        "company_name"    => $row['company_name'],
        "duration"        => $duration,
        // باقي الحقول إذا احتجتها (from_stop, to_stop, model...)
    ];
}

if (!empty($trips)) {
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
