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
}
