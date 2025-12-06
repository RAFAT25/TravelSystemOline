<?php
// get_available_trips_ar.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $con = getConnection(); // اتصال PDO

    $sql = "
    SELECT
        p.company_name              AS \"اسم_الشركة\",
        r.origin_city               AS \"مدينة_الانطلاق\",
        r.destination_city          AS \"مدينة_الوصول\",
        t.departure_time            AS \"وقت_المغادرة\",
        t.arrival_time              AS \"وقت_الوصول\",
        t.status                    AS \"حالة_الرحلة\",
        bc.class_name               AS \"نوع_الباص\",
        b.model                     AS \"موديل_الباص\",
        STRING_AGG(rs.stop_name, ' -> ' ORDER BY rs.stop_order) AS \"محطات_الطريق\",
        COALESCE(total_seats.total_capacity, 0)                 AS \"اجمالي_المقاعد\",
        COALESCE(total_seats.total_capacity, 0)
          - COALESCE(used_seats.used_count, 0)                  AS \"المقاعد_المتاحة\",
        t.base_price                 AS \"السعر\"
    FROM trips t
    JOIN partners     p  ON t.partner_id   = p.partner_id
    JOIN routes       r  ON t.route_id     = r.route_id
    JOIN buses        b  ON t.bus_id       = b.bus_id
    JOIN bus_classes  bc ON b.bus_class_id = bc.bus_class_id
    JOIN route_stops  rs ON rs.route_id    = r.route_id

    -- السعة الكلية
    LEFT JOIN LATERAL (
        SELECT COUNT(*) AS total_capacity
        FROM seats s
        WHERE s.bus_id = b.bus_id
    ) total_seats ON TRUE

    -- المقاعد المحجوزة فعلياً
    LEFT JOIN LATERAL (
        SELECT COUNT(DISTINCT ps.seat_id) AS used_count
        FROM bookings b2
        JOIN passengers ps ON ps.booking_id = b2.booking_id
        WHERE
            b2.trip_id = t.trip_id
            AND b2.booking_status IN ('Confirmed')   -- غيّرها لقيمة الحالة المؤكدة عندك
    ) used_seats ON TRUE

    WHERE
        DATE(t.departure_time) >= CURRENT_DATE      -- اليوم أو أكبر
        AND t.status = 'Scheduled'                  -- عدّل لاسم حالة الرحلة المتاحة عندك
    GROUP BY
        p.company_name,
        r.origin_city,
        r.destination_city,
        t.departure_time,
        t.arrival_time,
        t.status,
        bc.class_name,
        b.model,
        total_seats.total_capacity,
        used_seats.used_count,
        t.base_price
    ORDER BY t.departure_time
    ";

    $stmt = $con->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count'   => count($rows),
        'trips'   => $rows,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'SERVER_ERROR',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
