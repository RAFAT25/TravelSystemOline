<?php

namespace Travel\Controllers;

use Travel\Config\Database;
use Travel\Helpers\Response;
use Travel\Services\Whapi;
use Travel\Services\FcmService;
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

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    $user_id        = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $trip_id        = isset($data['trip_id']) ? (int)$data['trip_id'] : 0;
    $total_price    = isset($data['total_price']) ? (float)$data['total_price'] : 0;
    $payment_method = isset($data['payment_method']) ? trim($data['payment_method']) : 'Cash';
    $passengers     = (isset($data['passengers']) && is_array($data['passengers'])) ? $data['passengers'] : [];

    if ($user_id <= 0 || $trip_id <= 0 || empty($passengers)) {
        Response::error("Missing required fields: user_id, trip_id, passengers", 400);
        return;
    }

    try {
        $this->conn->beginTransaction();

        // 0) جلب partner_id من الرحلة
        $stmtTrip = $this->conn->prepare("
            SELECT partner_id, departure_time
            FROM trips
            WHERE trip_id = :trip_id
        ");
        $stmtTrip->execute([':trip_id' => $trip_id]);
        $tripRow = $stmtTrip->fetch(PDO::FETCH_ASSOC);

        if (!$tripRow) {
            throw new Exception("Trip not found.");
        }

        $partner_id     = (int)$tripRow['partner_id'];
        $departure_time = $tripRow['departure_time'];

        // 0.05) Get User Phone Number (for notifications)
        $stmtUser = $this->conn->prepare("SELECT phone_number, full_name FROM users WHERE user_id = :uid");
        $stmtUser->execute([':uid' => $user_id]);
        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
        $userPhone = $userRow['phone_number'] ?? '';
        $userName  = $userRow['full_name'] ?? 'Customer';

        // 0.1) جلب سياسة الإلغاء الافتراضية لهذا الشريك
        $stmtPolicy = $this->conn->prepare("
            SELECT cancel_policy_id
            FROM cancel_policies
            WHERE partner_id = :partner_id
              AND is_default = TRUE
              AND is_active = TRUE
            LIMIT 1
        ");
        $stmtPolicy->execute([':partner_id' => $partner_id]);
        $policyRow = $stmtPolicy->fetch(PDO::FETCH_ASSOC);

        if (!$policyRow) {
            throw new Exception("No active default cancel policy found for this partner.");
        }

        $cancel_policy_id = (int)$policyRow['cancel_policy_id'];

        // 1) استخراج أكواد المقاعد
        $seatCodes = array_map(fn($p) => trim($p['seat_code'] ?? ''), $passengers);
        $seatCodes = array_values(array_filter($seatCodes));

        if (empty($seatCodes)) {
            throw new Exception("No valid seat codes found.");
        }

        // 2) جلب الـ Seat IDs
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

        // 3) منع الحجز المزدوج
        $seatIds    = array_values($codeToId);
        $inSeatIds  = implode(',', array_fill(0, count($seatIds), '?'));
        $sqlCheck   = "SELECT ps.seat_id FROM bookings b 
                       JOIN passengers ps ON ps.booking_id = b.booking_id 
                       WHERE b.trip_id = ? AND ps.seat_id IN ($inSeatIds) FOR UPDATE";

        $stmtCheck = $this->conn->prepare($sqlCheck);
        $stmtCheck->execute(array_merge([$trip_id], $seatIds));
        if ($stmtCheck->fetch()) {
            throw new Exception("Some selected seats are already booked.");
        }

        // 4) إنشاء الحجز + ربطه بسياسة الإلغاء
        $sqlBooking = "INSERT INTO bookings 
            (user_id, trip_id, total_price, booking_status, payment_method, payment_status, booking_date, cancel_policy_id) 
            VALUES 
            (:uid, :tid, :price, 'Pending', :method, 'Unpaid', CURRENT_TIMESTAMP, :cancel_policy_id)
            RETURNING booking_id";

        $stmtBooking = $this->conn->prepare($sqlBooking);
        $stmtBooking->execute([
            ':uid'             => $user_id,
            ':tid'             => $trip_id,
            ':price'           => $total_price,
            ':method'          => $payment_method,
            ':cancel_policy_id'=> $cancel_policy_id
        ]);
        $booking_id = $stmtBooking->fetchColumn();

        // 5) إدخال الركاب
        $sqlPassenger = "INSERT INTO passengers 
            (booking_id, full_name, id_number, seat_id, trip_id, gender, birth_date, phone_number, id_image, passenger_status) 
            VALUES 
            (:bid, :name, :idnum, :sid, :tid, :gender, :bdate, :phone, :img, 'Active')";
        $stmtPassenger = $this->conn->prepare($sqlPassenger);

        foreach ($passengers as $p) {
            $stmtPassenger->execute([
                ':bid'   => $booking_id,
                ':name'  => $p['full_name'],
                ':idnum' => $p['id_number'] ?? '',
                ':sid'   => $codeToId[$p['seat_code']],
                ':tid'   => $trip_id,
                ':gender'=> $p['gender'] ?? null,
                ':bdate' => $p['birth_date'] ?? null,
                ':phone' => $p['phone_number'] ?? null,
                ':img'   => $p['id_image'] ?? null
            ]);
        }

        // 6) تحديث حالة المقاعد
        $sqlUpdateSeats = "UPDATE seats SET is_available = FALSE WHERE seat_id IN ($inSeatIds)";
        $stmtUpdateSeats = $this->conn->prepare($sqlUpdateSeats);
        $stmtUpdateSeats->execute($seatIds);

        $this->conn->commit();

        Response::success([
            "booking_id"   => $booking_id,
            "trip_id"      => $trip_id,
            "total_price"  => $total_price,
            "cancel_policy_id" => $cancel_policy_id
        ]);

        // --- Centralized Notifications ---
        $this->sendBookingNotifications($user_id, $booking_id, 'Unpaid', $total_price, $userPhone, $userName);



    } catch (Exception $e) {
        if ($this->conn->inTransaction()) {
            $this->conn->rollBack();
        }
        Response::error($e->getMessage(), 500);
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
            Response::error("booking_id and payment_status are required", 400);
            return;
        }

        $allowedStatus = ['Unpaid', 'Paid', 'Refunded'];
        if (!in_array($payment_status, $allowedStatus, true)) {
            Response::error("Invalid payment_status", 400);
            return;
        }

        $allowedMethods = ['Electronic', 'Cash', 'Kareemi', 'Transfer'];
        if ($payment_method !== '' && !in_array($payment_method, $allowedMethods, true)) {
            Response::error("Invalid payment_method", 400);
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
                 // Fetch User Info for Notification
                 $stmtU = $this->conn->prepare("
                    SELECT u.user_id, u.phone_number, u.full_name, b.total_price 
                    FROM bookings b
                    JOIN users u ON u.user_id = b.user_id
                    WHERE b.booking_id = :bid
                 ");
                 $stmtU->execute([':bid' => $booking_id]);
                 $rowU = $stmtU->fetch(PDO::FETCH_ASSOC);

                 if ($rowU) {
                     $uId    = (int)$rowU['user_id'];
                     $uPhone = $rowU['phone_number'];
                     $uName  = $rowU['full_name'];
                     $tPrice = $rowU['total_price'];
                     
                     // Trigger Notifications for Paid/Refunded
                     $this->sendBookingNotifications($uId, $booking_id, $payment_status, $tPrice, $uPhone, $uName);
                 }

                Response::success(["booking_id" => $booking_id]);
            } else {
                Response::error("Booking not found or no change made", 404);
            }
        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
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
            Response::error("Invalid User ID", 400);
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

            Response::success([
                "count"    => count($rows),
                "bookings" => $rows
            ]);

        } catch (Exception $e) {
            Response::error("Server Error: " . $e->getMessage(), 500);
    }
}

    public function confirmBooking($actor = null) {
        header('Content-Type: application/json; charset=utf-8');

        // 1. Authentication (Required for Audit Trail)
        // Token validation is now done at the routing level
        if (!$actor || !isset($actor['user_id'])) {
             Response::unauthorized("Invalid Token Payload: Missing user_id");
             return;
        }

        $employee_id = (int)$actor['user_id'];

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        $booking_id          = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
        $force_payment_status = isset($data['payment_status']) ? trim($data['payment_status']) : '';
        $notes               = isset($data['notes']) ? trim($data['notes']) : '';

        if ($booking_id <= 0) {
            Response::error("Invalid booking_id", 400);
            return;
        }

        $allowedPayment = ['Unpaid', 'Paid', 'Refunded'];
        if ($force_payment_status !== '' && !in_array($force_payment_status, $allowedPayment, true)) {
            Response::error("Invalid payment_status", 400);
            return;
        }

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare("
                SELECT b.booking_status, b.payment_status, b.total_price, u.user_id, u.phone_number, u.full_name
                FROM bookings b
                JOIN users u ON u.user_id = b.user_id
                WHERE b.booking_id = :bid
                FOR UPDATE
            ");
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception("Booking not found");
            }

            if ($booking['booking_status'] !== 'Pending') {
                throw new Exception("Only Pending bookings can be confirmed");
            }
            
            $oldStatus = $booking['booking_status'];

            $newPaymentStatus = $booking['payment_status'];
            if ($force_payment_status !== '') {
                $newPaymentStatus = $force_payment_status;
            }

            // --- Audit Trail Insert ---
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $stmtAudit = $this->conn->prepare("
                INSERT INTO booking_approvals 
                (booking_id, employee_id, action_type, old_status, new_status, notes, ip_address)
                VALUES 
                (:bid, :eid, 'CONFIRM_BOOKING', :old_s, 'Confirmed', :notes, :ip)
            ");
            $stmtAudit->execute([
                ':bid'     => $booking_id,
                ':eid'     => $employee_id,
                ':old_s'   => $oldStatus,
                ':notes'   => $notes ?: "Payment Status: $newPaymentStatus",
                ':ip'      => $ip_address
            ]);
            // --------------------------

            $stmtUpdate = $this->conn->prepare("
                UPDATE bookings
                SET booking_status = 'Confirmed',
                    payment_status = :pstatus
                WHERE booking_id = :bid
            ");

            $stmtUpdate->execute([
                ':pstatus' => $newPaymentStatus,
                ':bid'     => $booking_id
            ]);

            $this->conn->commit();

            // Trigger Notifications
            $this->sendBookingNotifications(
                $booking['user_id'], 
                $booking_id, 
                'Confirmed', // ✅ إرسال حالة التأكيد
                $booking['total_price'], 
                $booking['phone_number'], 
                $booking['full_name']
            );

            Response::success([
                "booking_id"     => $booking_id,
                "booking_status" => "Confirmed",
                "payment_status" => $newPaymentStatus
            ]);

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            Response::error($e->getMessage(), 500);
        }
    }

    private function sendBookingNotifications($userId, $bookingId, $status, $totalPrice, $userPhone, $userName) {
        $title = "";
        $body  = "";

        if ($status === 'Unpaid') {
            $title = "تم إنشاء الحجز بنجاح";
            $body  = "مرحبا {$userName}، تم حجز رحلتك رقم {$bookingId}. يرجى الدفع لإتمام التأكيد. المبلغ: {$totalPrice}";
        } elseif ($status === 'Paid') {
            $title = "تم الدفع بنجاح";
            $body  = "مرحبا {$userName}، تم استلام دفعة الحجز رقم {$bookingId} بنجاح. نتمنى لك رحلة سعيدة!";
        } elseif ($status === 'Refunded') {
            $title = "تم استرداد المبلغ";
            $body  = "مرحبا {$userName}، تم استرداد مبلغ الحجز رقم {$bookingId}.";
        } elseif ($status === 'Confirmed') {
            $title = "تم تأكيد الحجز";
            $body  = "مرحبا {$userName}، تم تأكيد حجزك رقم {$bookingId} بنجاح! نتمنى لك رحلة سعيدة.";
        } else {
            return;
        }

        // 1. Database Notification
        try {
            $stmtN = $this->conn->prepare("
                INSERT INTO notifications (user_id, title, message, type, related_id)
                VALUES (:uid, :title, :msg, 'booking', :rid)
            ");
            $stmtN->execute([
                ':uid'   => $userId,
                ':title' => $title,
                ':msg'   => $body,
                ':rid'   => $bookingId
            ]);
        } catch (\Throwable $e) { /* Ignore */ }

        // 2. WhatsApp Notification
        if (!empty($userPhone)) {
            try {
                Whapi::sendText($userPhone, $body);
            } catch (\Throwable $e) { /* Ignore */ }
        }

        // 3. FCM Notification
        try {
            // Fetch Token
            $stmtT = $this->conn->prepare("SELECT fcm_token FROM user_device_tokens WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
            $stmtT->execute([':uid' => $userId]);
            $tokenRow = $stmtT->fetch(PDO::FETCH_ASSOC);

            if ($tokenRow && !empty($tokenRow['fcm_token'])) {
                $fcm = new FcmService();
                $fcm->sendNotification($tokenRow['fcm_token'], $title, $body, ['booking_id' => (string)$bookingId]);
            }
        } catch (\Throwable $e) { /* Ignore */ }
    }

    /**
     * Submit payment proof (Transaction ID / Reference)
     * POST /api/bookings/submit-payment-proof
     */
    public function submitPaymentProof($actor) {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $booking_id = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
        $transaction_id = isset($data['transaction_id']) ? trim($data['transaction_id']) : '';
        $payment_method = isset($data['payment_method']) ? trim($data['payment_method']) : '';
        $note = isset($data['note']) ? trim($data['note']) : '';

        if ($booking_id <= 0 || empty($transaction_id)) {
            Response::error("booking_id and transaction_id are required", 400);
            return;
        }

        try {
            // Verify booking belongs to user
            $stmt = $this->conn->prepare("SELECT user_id, payment_status FROM bookings WHERE booking_id = :bid");
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                Response::notFound("Booking not found");
                return;
            }

            // Security check: Only customer who owns it can submit (or Admin/Employee)
            if ($booking['user_id'] != $actor['user_id'] && !in_array($actor['user_type'], ['Employee', 'Admin'])) {
                Response::unauthorized("Access denied");
                return;
            }

            if ($booking['payment_status'] === 'Paid') {
                Response::error("Booking is already marked as Paid", 409);
                return;
            }

            // Update booking with transaction ID and Method
            $stmtUpdate = $this->conn->prepare("
                UPDATE bookings 
                SET gateway_transaction_id = :tid,
                    payment_method = CASE 
                                        WHEN :method != '' THEN :method::payment_method_enum 
                                        ELSE payment_method 
                                     END,
                    notes = :note
                WHERE booking_id = :bid
            ");
            $stmtUpdate->execute([
                ':tid' => $transaction_id,
                ':method' => $payment_method,
                ':note' => $note ?: "Submitted by customer",
                ':bid' => $booking_id
            ]);

            Response::success([], "تم إرسال إثبات الدفع بنجاح. سيتم مراجعته من قبل الموظف.");

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}


