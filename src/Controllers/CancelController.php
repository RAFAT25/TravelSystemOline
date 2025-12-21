<?php

namespace Travel\Controllers;

use Travel\Config\Database;
use PDO;
use Exception;

class CancelController {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    // GET/POST /api/bookings/cancel-preview
    public function preview() {
        header('Content-Type: application/json; charset=utf-8');

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        // Support for both POST (JSON) and GET (Query Params)
        $booking_id = isset($data['booking_id']) ? (int)$data['booking_id'] : (isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0);

        if ($booking_id <= 0) {
            echo json_encode(["success" => false, "error" => "Invalid booking_id"], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            // 1) جلب بيانات الحجز + الرحلة + السياسة
            $sql = "
                SELECT 
                    b.booking_id,
                    b.total_price,
                    b.booking_status,
                    b.cancel_policy_id,
                    t.departure_time,
                    t.trip_id
                FROM bookings b
                JOIN trips t ON t.trip_id = b.trip_id
                WHERE b.booking_id = :bid
                LIMIT 1
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                echo json_encode(["success" => false, "error" => "Booking not found"], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($booking['booking_status'] === 'Cancelled') {
                echo json_encode(["success" => false, "error" => "Booking already cancelled"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $cancel_policy_id = (int)$booking['cancel_policy_id'];
            $total_price      = (float)$booking['total_price'];
            $departure_time   = $booking['departure_time'];

            // 2) حساب الساعات المتبقية قبل الانطلاق
            $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $dep  = new \DateTimeImmutable($departure_time, new \DateTimeZone('UTC'));
            $diff = $dep->getTimestamp() - $now->getTimestamp();
            $hours_before_departure = $diff / 3600.0;

            // 3) اختيار الـ rule المناسبة
            $sqlRule = "
                SELECT *
                FROM cancel_policy_rules
                WHERE cancel_policy_id = :cpid
                  AND is_active = TRUE
                  AND min_hours_before_departure <= :hours::NUMERIC
                  AND (max_hours_before_departure IS NULL OR :hours::NUMERIC < max_hours_before_departure)
                ORDER BY min_hours_before_departure DESC
                LIMIT 1
            ";
            $stmtRule = $this->conn->prepare($sqlRule);
            $stmtRule->execute([
                ':cpid'  => $cancel_policy_id,
                ':hours' => $hours_before_departure
            ]);
            $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                echo json_encode([
                    "success" => false,
                    "error"   => "No matching cancel rule for this time window",
                    "hours_before_departure" => $hours_before_departure
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $refund_percentage = (float)$rule['refund_percentage'];
            $cancellation_fee  = (float)$rule['cancellation_fee'];

            $refund_amount = round(($total_price * $refund_percentage / 100.0) - $cancellation_fee, 2);
            if ($refund_amount < 0) {
                $refund_amount = 0;
            }

            echo json_encode([
                "success" => true,
                "booking_id" => $booking_id,
                "trip_id"    => (int)$booking['trip_id'],
                "total_price" => $total_price,
                "hours_before_departure" => $hours_before_departure,
                "rule" => [
                    "cancel_policy_rule_id" => (int)($rule['cancel_policy_rule_id'] ?? $rule['id'] ?? 0),
                    "min_hours_before_departure" => (float)$rule['min_hours_before_departure'],
                    "max_hours_before_departure" => $rule['max_hours_before_departure'],
                    "refund_percentage"          => $refund_percentage,
                    "cancellation_fee"           => $cancellation_fee
                ],
                "calculated" => [
                    "refund_amount"      => $refund_amount,
                    "non_refundable_part"=> max(0, $total_price - $refund_amount)
                ]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    // POST /api/bookings/cancel
    public function confirm() {
        header('Content-Type: application/json; charset=utf-8');

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        $booking_id = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
        $reason     = isset($data['reason']) ? trim($data['reason']) : '';

        if ($booking_id <= 0) {
            echo json_encode(["success" => false, "error" => "Invalid booking_id"], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $this->conn->beginTransaction();

            // 1) جلب بيانات الحجز
            $sql = "
                SELECT 
                    b.booking_id,
                    b.total_price,
                    b.booking_status,
                    b.cancel_policy_id,
                    b.trip_id,
                    t.departure_time
                FROM bookings b
                JOIN trips t ON t.trip_id = b.trip_id
                WHERE b.booking_id = :bid
                FOR UPDATE
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception("Booking not found");
            }

            if ($booking['booking_status'] === 'Cancelled') {
                throw new Exception("Booking already cancelled");
            }

            $cancel_policy_id = (int)$booking['cancel_policy_id'];
            $total_price      = (float)$booking['total_price'];
            $departure_time   = $booking['departure_time'];
            $trip_id          = (int)$booking['trip_id'];

            // 2) حساب الساعات المتبقية
            $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $dep  = new \DateTimeImmutable($departure_time, new \DateTimeZone('UTC'));
            $diff = $dep->getTimestamp() - $now->getTimestamp();
            $hours_before_departure = $diff / 3600.0;

            // 3) اختيار الـ rule
            $sqlRule = "
                SELECT *
                FROM cancel_policy_rules
                WHERE cancel_policy_id = :cpid
                  AND is_active = TRUE
                  AND min_hours_before_departure <= :hours::NUMERIC
                  AND (max_hours_before_departure IS NULL OR :hours::NUMERIC < max_hours_before_departure)
                ORDER BY min_hours_before_departure DESC
                LIMIT 1
            ";
            $stmtRule = $this->conn->prepare($sqlRule);
            $stmtRule->execute([
                ':cpid'  => $cancel_policy_id,
                ':hours' => $hours_before_departure
            ]);
            $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                throw new Exception("No matching cancel rule for this time window");
            }

            $refund_percentage = (float)$rule['refund_percentage'];
            $cancellation_fee  = (float)$rule['cancellation_fee'];

            $refund_amount = round(($total_price * $refund_percentage / 100.0) - $cancellation_fee, 2);
            if ($refund_amount < 0) {
                $refund_amount = 0;
            }

            // 4) تحديث حالة الحجز + الركاب + المقاعد
            $stmtUpdateBooking = $this->conn->prepare("
                UPDATE bookings
                SET booking_status = 'Cancelled',
                    cancel_reason  = :reason,
                    cancel_timestamp = CURRENT_TIMESTAMP
                WHERE booking_id = :bid
            ");
            $stmtUpdateBooking->execute([
                ':reason' => $reason,
                ':bid'    => $booking_id
            ]);

            // تحديث الركاب
            $stmtUpdatePassengers = $this->conn->prepare("
                UPDATE passengers
                SET passenger_status = 'Cancelled'
                WHERE booking_id = :bid
            ");
            $stmtUpdatePassengers->execute([':bid' => $booking_id]);

            // تحرير المقاعد
            $stmtSeats = $this->conn->prepare("
                SELECT seat_id
                FROM passengers
                WHERE booking_id = :bid
            ");
            $stmtSeats->execute([':bid' => $booking_id]);
            $seatIds = $stmtSeats->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($seatIds)) {
                $inSeatIds = implode(',', array_fill(0, count($seatIds), '?'));
                $sqlFreeSeats = "UPDATE seats SET is_available = TRUE WHERE seat_id IN ($inSeatIds)";
                $stmtFreeSeats = $this->conn->prepare($sqlFreeSeats);
                $stmtFreeSeats->execute($seatIds);
            }

            // 5) إدخال سجل الإلغاء booking_cancellations
            $stmtInsertCancel = $this->conn->prepare("
                INSERT INTO booking_cancellations
                (booking_id, cancel_policy_id, cancel_policy_rule_id, refund_percentage, cancellation_fee, refund_amount, reason, hours_before_departure, created_at)
                VALUES
                (:bid, :cpid, :rule_id, :refund_pct, :fee, :refund_amt, :reason, :hours, CURRENT_TIMESTAMP)
            ");
            $stmtInsertCancel->execute([
                ':bid'       => $booking_id,
                ':cpid'      => $cancel_policy_id,
                ':rule_id'   => (int)($rule['cancel_policy_rule_id'] ?? $rule['id'] ?? 0),
                ':refund_pct'=> $refund_percentage,
                ':fee'       => $cancellation_fee,
                ':refund_amt'=> $refund_amount,
                ':reason'    => $reason,
                ':hours'     => $hours_before_departure
            ]);

            $this->conn->commit();

            echo json_encode([
                "success" => true,
                "booking_id" => $booking_id,
                "trip_id"    => $trip_id,
                "total_price" => $total_price,
                "refund_amount" => $refund_amount,
                "refund_percentage" => $refund_percentage,
                "cancellation_fee"  => $cancellation_fee
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            echo json_encode(["success" => false, "error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
