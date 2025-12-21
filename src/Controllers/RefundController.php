<?php

namespace Travel\Controllers;

use Travel\Config\Database;
use PDO;
use Exception;

class RefundController {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    /**
     * Process refund - add refund details
     * POST /api/refunds/process
     * Body: {
     *   "refund_id": 1,
     *   "refund_method": "Kareemi",
     *   "kareemi_number": "777123456",
     *   "notes": "..."
     * }
     */
    public function processRefund($actor) {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $refund_id = isset($data['refund_id']) ? (int)$data['refund_id'] : 0;
        $refund_method = isset($data['refund_method']) ? trim($data['refund_method']) : '';
        $bank_name = isset($data['bank_name']) ? trim($data['bank_name']) : '';
        $bank_account = isset($data['bank_account']) ? trim($data['bank_account']) : '';
        $account_holder = isset($data['account_holder_name']) ? trim($data['account_holder_name']) : '';
        $kareemi_number = isset($data['kareemi_number']) ? trim($data['kareemi_number']) : '';
        $notes = isset($data['notes']) ? trim($data['notes']) : '';

        if ($refund_id <= 0 || $refund_method === '') {
            echo json_encode([
                "success" => false,
                "error" => "refund_id and refund_method are required"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $allowed_methods = ['Bank Transfer', 'Kareemi', 'Cash', 'Same as Original'];
        if (!in_array($refund_method, $allowed_methods)) {
            echo json_encode([
                "success" => false,
                "error" => "Invalid refund_method. Allowed: " . implode(', ', $allowed_methods)
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $this->conn->beginTransaction();

            $employee_id = (int)$actor['user_id'];

            $stmt = $this->conn->prepare("
                UPDATE refund_transactions
                SET refund_method = :method,
                    bank_name = :bank_name,
                    bank_account = :bank_account,
                    account_holder_name = :account_holder,
                    kareemi_number = :kareemi,
                    refund_status = 'Processing',
                    processed_by = :employee_id,
                    processing_started_at = CURRENT_TIMESTAMP,
                    internal_notes = :notes,
                    customer_notes = 'جاري معالجة طلب الاسترداد. سيتم التحويل خلال 3-5 أيام عمل.'
                WHERE refund_id = :refund_id
                  AND refund_status = 'Pending'
            ");

            $stmt->execute([
                ':method' => $refund_method,
                ':bank_name' => $bank_name,
                ':bank_account' => $bank_account,
                ':account_holder' => $account_holder,
                ':kareemi' => $kareemi_number,
                ':employee_id' => $employee_id,
                ':notes' => $notes,
                ':refund_id' => $refund_id
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Refund not found or already processed");
            }

            $this->conn->commit();

            echo json_encode([
                "success" => true,
                "refund_id" => $refund_id,
                "status" => "Processing",
                "message" => "تم بدء معالجة الاسترداد بنجاح"
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

    /**
     * Complete refund - mark as completed
     * POST /api/refunds/complete
     * Body: { "refund_id": 1, "refund_reference": "TXN123456", "notes": "..." }
     */
    public function completeRefund($actor) {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $refund_id = isset($data['refund_id']) ? (int)$data['refund_id'] : 0;
        $refund_reference = isset($data['refund_reference']) ? trim($data['refund_reference']) : '';
        $notes = isset($data['notes']) ? trim($data['notes']) : '';

        if ($refund_id <= 0) {
            echo json_encode([
                "success" => false,
                "error" => "refund_id is required"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $employee_id = (int)$actor['user_id'];

            $stmt = $this->conn->prepare("
                UPDATE refund_transactions
                SET refund_status = 'Completed',
                    refund_reference = :reference,
                    completed_by = :employee_id,
                    completed_at = CURRENT_TIMESTAMP,
                    internal_notes = :notes,
                    customer_notes = CONCAT(
                        'تم إرجاع المبلغ بنجاح.',
                        CASE WHEN :reference != '' THEN CONCAT(' رقم المعاملة: ', :reference) ELSE '' END
                    )
                WHERE refund_id = :refund_id
                  AND refund_status = 'Processing'
            ");

            $stmt->execute([
                ':reference' => $refund_reference,
                ':employee_id' => $employee_id,
                ':notes' => $notes,
                ':refund_id' => $refund_id
            ]);

            if ($stmt->rowCount() === 0) {
                echo json_encode([
                    "success" => false,
                    "error" => "Refund not found or not in Processing status"
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                "success" => true,
                "refund_id" => $refund_id,
                "status" => "Completed",
                "message" => "تم إكمال عملية الاسترداد بنجاح"
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get all refund transactions
     * GET /api/refunds/list
     * Query: ?status=Pending&from=2025-01-01&to=2025-12-31
     */
    public function getRefunds() {
        header('Content-Type: application/json; charset=utf-8');

        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $from = isset($_GET['from']) ? trim($_GET['from']) : '';
        $to = isset($_GET['to']) ? trim($_GET['to']) : '';

        try {
            $sql = "SELECT * FROM v_refund_details WHERE 1=1";
            $params = [];

            if ($status !== '') {
                $sql .= " AND refund_status = :status";
                $params[':status'] = $status;
            }

            if ($from !== '') {
                $sql .= " AND created_at >= :from";
                $params[':from'] = $from;
            }

            if ($to !== '') {
                $sql .= " AND created_at <= :to";
                $params[':to'] = $to;
            }

            $sql .= " ORDER BY created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "count" => count($refunds),
                "refunds" => $refunds
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Get refund details for a specific booking
     * GET /api/refunds/booking/{booking_id}
     */
    public function getRefundByBooking() {
        header('Content-Type: application/json; charset=utf-8');

        $booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

        if ($booking_id <= 0) {
            echo json_encode([
                "success" => false,
                "error" => "booking_id is required"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM v_refund_details
                WHERE booking_id = :bid
            ");
            $stmt->execute([':bid' => $booking_id]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$refund) {
                echo json_encode([
                    "success" => false,
                    "error" => "Refund not found for this booking"
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                "success" => true,
                "refund" => $refund
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Calculate refund fee for a booking
     * GET /api/refunds/calculate-fee/{booking_id}
     */
    public function calculateFee() {
        header('Content-Type: application/json; charset=utf-8');

        $booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

        if ($booking_id <= 0) {
            echo json_encode([
                "success" => false,
                "error" => "booking_id is required"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $stmt = $this->conn->prepare("SELECT calculate_refund_fee(:bid)");
            $stmt->execute([':bid' => $booking_id]);
            $fee = $stmt->fetchColumn();

            // Get booking amount
            $stmt2 = $this->conn->prepare("SELECT total_price FROM bookings WHERE booking_id = :bid");
            $stmt2->execute([':bid' => $booking_id]);
            $total_price = $stmt2->fetchColumn();

            $net_refund = $total_price - $fee;

            echo json_encode([
                "success" => true,
                "booking_id" => $booking_id,
                "total_price" => $total_price,
                "refund_fee" => $fee,
                "net_refund" => $net_refund
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            echo json_encode([
                "success" => false,
                "error" => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
