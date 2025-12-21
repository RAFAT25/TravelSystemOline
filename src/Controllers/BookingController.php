<?php

namespace Travel\Controllers;

use Travel\Config\Database;
use PDO;
use Exception;

class BookingController {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    public function create() {
        header('Content-Type: application/json; charset=utf-8');
        
        // Note: Middleware has already validated the token, but we can access user info if passed
        // For now, we trust the input per the old logic, but it's recommended to use the ID from the token
        
        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        // Basic validation
        $user_id        = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $trip_id        = isset($data['trip_id']) ? (int)$data['trip_id'] : 0;
        $total_price    = isset($data['total_price']) ? (float)$data['total_price'] : 0;
        $payment_method = isset($data['payment_method']) ? trim($data['payment_method']) : 'Cash';
        $passengers     = (isset($data['passengers']) && is_array($data['passengers'])) ? $data['passengers'] : [];

        if ($user_id <= 0 || $trip_id <= 0 || empty($passengers)) {
            echo json_encode([
                "success" => false,
                "error"   => "Missing required fields: user_id, trip_id, passengers",
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $this->conn->beginTransaction();

            // 1. Extract and validate seat codes
            $seatCodes = array_map(fn($p) => trim($p['seat_code'] ?? ''), $passengers);
            $seatCodes = array_values(array_filter($seatCodes));

            if (empty($seatCodes)) {
                throw new Exception("No valid seat codes found.");
            }

            // 2. Fetch Seat IDs
            $inPlaceholders = implode(',', array_fill(0, count($seatCodes), '?'));
            $sqlSeats = "SELECT s.seat_id, s.seat_number FROM trips t 
                         JOIN buses b ON b.bus_id = t.bus_id 
                         JOIN seats s ON s.bus_id = b.bus_id 
                         WHERE t.trip_id = ? AND s.seat_number IN ($inPlaceholders)";
            
            $stmtSeats = $this->conn->prepare($sqlSeats);
            $stmtSeats->execute(array_merge([$trip_id], $seatCodes));
            $rowsSeats = $stmtSeats->fetchAll(PDO::FETCH_ASSOC);

            if (count($rowsSeats) !== count($seatCodes)) {
                throw new Exception("Some seat numbers do not exist for this trip.");
            }

            $codeToId = [];
            foreach ($rowsSeats as $row) {
                $codeToId[$row['seat_number']] = (int)$row['seat_id'];
            }

            // 3. Double Booking Check (FOR UPDATE)
            $seatIds = array_values($codeToId);
            $inSeatIds = implode(',', array_fill(0, count($seatIds), '?'));
            $sqlCheck = "SELECT ps.seat_id FROM bookings b 
                         JOIN passengers ps ON ps.booking_id = b.booking_id 
                         WHERE b.trip_id = ? AND ps.seat_id IN ($inSeatIds) FOR UPDATE";
            
            $stmtCheck = $this->conn->prepare($sqlCheck);
            $stmtCheck->execute(array_merge([$trip_id], $seatIds));
            if ($stmtCheck->fetch()) {
                throw new Exception("Some selected seats are already booked.");
            }

            // 4. Create Booking
            $sqlBooking = "INSERT INTO bookings (user_id, trip_id, total_price, booking_status, payment_method, payment_status, booking_date) 
                           VALUES (:uid, :tid, :price, 'Pending', :method, 'Unpaid', CURRENT_TIMESTAMP) RETURNING booking_id";
            $stmtBooking = $this->conn->prepare($sqlBooking);
            $stmtBooking->execute([
                ':uid' => $user_id, ':tid' => $trip_id, ':price' => $total_price, ':method' => $payment_method
            ]);
            $booking_id = $stmtBooking->fetchColumn();

            // 5. Insert Passengers
            $sqlPassenger = "INSERT INTO passengers (booking_id, full_name, id_number, seat_id, trip_id, gender, birth_date, phone_number, id_image) 
                             VALUES (:bid, :name, :idnum, :sid, :tid, :gender, :bdate, :phone, :img)";
            $stmtPassenger = $this->conn->prepare($sqlPassenger);

            foreach ($passengers as $p) {
                $stmtPassenger->execute([
                    ':bid' => $booking_id,
                    ':name' => $p['full_name'],
                    ':idnum' => $p['id_number'] ?? '',
                    ':sid' => $codeToId[$p['seat_code']],
                    ':tid' => $trip_id,
                    ':gender' => $p['gender'] ?? null,
                    ':bdate' => $p['birth_date'] ?? null,
                    ':phone' => $p['phone_number'] ?? null,
                    ':img' => $p['id_image'] ?? null // Still base64 for now as per user request not to change DB schema yet
                ]);
            }

            // 6. Update Seat Status
            $sqlUpdateSeats = "UPDATE seats SET is_available = FALSE WHERE seat_id IN ($inSeatIds)";
            $stmtUpdateSeats = $this->conn->prepare($sqlUpdateSeats);
            $stmtUpdateSeats->execute($seatIds);

            $this->conn->commit();

            echo json_encode([
                "success" => true,
                "booking_id" => $booking_id,
                "trip_id" => $trip_id,
                "total_price" => $total_price
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            echo json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function updatePayment() {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        $booking_id     = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
        $payment_status = isset($data['payment_status']) ? trim($data['payment_status']) : '';
        $payment_method = isset($data['payment_method']) ? trim($data['payment_method']) : '';
        $transaction_id = isset($data['transaction_id']) ? trim($data['transaction_id']) : '';

        if ($booking_id <= 0 || $payment_status === '') {
            echo json_encode([
                "success" => false,
                "error"   => "booking_id and payment_status are required"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $allowedStatus = ['Unpaid', 'Paid', 'Refunded'];
        if (!in_array($payment_status, $allowedStatus, true)) {
            echo json_encode(["success" => false, "error" => "Invalid payment_status"], JSON_UNESCAPED_UNICODE);
            return;
        }

        $allowedMethods = ['Electronic', 'Cash', 'Kareemi'];
        if ($payment_method !== '' && !in_array($payment_method, $allowedMethods, true)) {
            echo json_encode(["success" => false, "error" => "Invalid payment_method"], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $sql = "
                UPDATE bookings
                SET payment_status = :status::payment_status_enum,
                    payment_method = COALESCE(
                                        NULLIF(:method, '')::payment_method_enum,
                                        payment_method
                                     ),
                    payment_timestamp = CASE 
                                            WHEN :status = 'Paid' THEN CURRENT_TIMESTAMP 
                                            ELSE payment_timestamp 
                                        END,
                    gateway_transaction_id = COALESCE(NULLIF(:txn, ''), gateway_transaction_id)
                WHERE booking_id = :booking_id
            ";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':status'     => $payment_status,
                ':method'     => $payment_method,
                ':txn'        => $transaction_id,
                ':booking_id' => $booking_id,
            ]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(["success" => true, "booking_id" => $booking_id], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(["success" => false, "error" => "Booking not found or no change made"], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getUserBookings() {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Try to get user_id from token if authenticated, then from input (POST), then from query params (GET)
        $userId = 0;
        if (isset($data['user_id'])) {
            $userId = (int)$data['user_id'];
        } elseif (isset($_GET['user_id'])) {
            $userId = (int)$_GET['user_id'];
        }

        if ($userId <= 0) {
            echo json_encode([
                "success" => false,
                "error"   => "Invalid User ID"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $sql = "
                SELECT
                    u.user_id,
                    u.full_name,
                    b.booking_id,
                    b.booking_status,
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

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success"  => true,
                "count"    => count($rows),
                "bookings" => $rows
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "error"   => "Server Error: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
