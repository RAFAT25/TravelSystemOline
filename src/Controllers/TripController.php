<?php

namespace Travel\Controllers;

use Travel\Helpers\Response;
use Travel\Config\Database;
use PDO;
use DateTime;
use Exception;

class TripController {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    public function search() {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Support for both POST (JSON) and GET (Query Params)
        $from_stop = $data['from_stop'] ?? ($_GET['from_stop'] ?? '');
        $to_city   = $data['to_city']   ?? ($_GET['to_city']   ?? '');
        $date      = $data['date']      ?? ($_GET['date']      ?? '');
        $bus_class = $data['bus_class'] ?? ($_GET['bus_class'] ?? '');

        if (empty($from_stop) || empty($to_city) || empty($date) || empty($bus_class)) {
            Response::error("All fields are required", 400);
            return;
        }

        try {
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
                ) AS count
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

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':from_stop' => $from_stop,
                ':to_city'   => $to_city,
                ':date'      => $date,
                ':bus_class' => $bus_class
            ]);

            $trips = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $availableSeats = isset($row['count']) && $row['count'] !== null ? intval($row['count']) : 0;
                $dep = new DateTime($row['departure_time']);
                $arr = new DateTime($row['arrival_time']);
                $interval = $dep->diff($arr);
                $duration = $interval->h . " hours, " . $interval->i . " minutes";

                $trips[] = [
                    "trip_id"          => $row['trip_id'],
                    "departure_time"   => $row['departure_time'],
                    "arrival_time"     => $row['arrival_time'],
                    "origin_city"      => $row['origin_city'],
                    "destination_city" => $row['destination_city'],
                    "bus_class"        => $row['bus_class'],
                    "price_adult"      => $row['price_adult'],
                    "price_child"      => $row['price_child'],
                    "availableSeats"   => $availableSeats,
                    "company_name"     => $row['company_name'],
                    "duration"         => $duration,
                ];
            }

            if (!empty($trips)) {
                Response::success(["trips" => $trips]);
            } else {
                Response::error("No matching trips found", 404);
            }
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function getAvailableSeats() {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        // Support for both POST (JSON) and GET (Query Params)
        $trip_id = isset($data['trip_id']) ? (int)$data['trip_id'] : (isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0);

        if ($trip_id <= 0) {
            Response::error("trip_id is required", 400);
            return;
        }

        try {
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

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':trip_id' => $trip_id]);
            $seats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success(["seats" => $seats ?: []]);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
