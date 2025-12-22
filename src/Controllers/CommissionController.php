<?php

namespace Travel\Controllers;

use Travel\Helpers\Response;
use Travel\Config\Database;
use PDO;
use Exception;

class CommissionController {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    /**
     * Get commissions for a specific partner
     * GET /api/commissions/partner/{partner_id}
     * Query params: ?status=Pending&from=2025-01-01&to=2025-12-31
     */
    public function getPartnerCommissions() {
        header('Content-Type: application/json; charset=utf-8');
        
        $partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0;
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        $from = isset($_GET['from']) ? trim($_GET['from']) : '';
        $to = isset($_GET['to']) ? trim($_GET['to']) : '';

        if ($partner_id <= 0) {
            Response::error("partner_id is required", 400);
            return;
        }

        try {
            $sql = "SELECT * FROM v_commission_summary WHERE partner_id = :pid";
            $params = [':pid' => $partner_id];

            if ($status !== '') {
                $sql .= " AND status = :status";
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
            $commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // حساب الإجماليات
            $total_commission = array_sum(array_column($commissions, 'commission_amount'));
            $total_revenue = array_sum(array_column($commissions, 'partner_revenue'));

            Response::success([
                "count" => count($commissions),
                "total_commission" => $total_commission,
                "total_partner_revenue" => $total_revenue,
                "commissions" => $commissions
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * Get daily commission summary
     * GET /api/commissions/daily-summary
     * Query params: ?date=2025-12-21
     */
    public function getDailySummary() {
        header('Content-Type: application/json; charset=utf-8');
        
        $date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');

        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM daily_commissions 
                WHERE commission_date = :date
            ");
            $stmt->execute([':date' => $date]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$summary) {
                Response::success([
                    "date" => $date,
                    "total_bookings" => 0,
                    "total_revenue" => 0,
                    "total_commission" => 0
                ]);
                return;
            }

            Response::success(["summary" => $summary]);

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * Get commission details for a specific booking
     * GET /api/commissions/booking/{booking_id}
     */
    public function getBookingCommission() {
        header('Content-Type: application/json; charset=utf-8');
        
        $booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

        if ($booking_id <= 0) {
            Response::error("booking_id is required", 400);
            return;
        }

        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM v_commission_summary 
                WHERE booking_id = :bid
            ");
            $stmt->execute([':bid' => $booking_id]);
            $commission = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$commission) {
                Response::notFound("Commission not found for this booking");
                return;
            }

            Response::success(["commission" => $commission]);

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * Mark commissions as paid
     * POST /api/commissions/mark-paid
     * Body: { "commission_ids": [1,2,3], "payment_method": "Bank Transfer", "notes": "..." }
     */
    public function markAsPaid() {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $commission_ids = isset($data['commission_ids']) && is_array($data['commission_ids']) 
            ? $data['commission_ids'] 
            : [];
        $payment_method = isset($data['payment_method']) ? trim($data['payment_method']) : '';
        $notes = isset($data['notes']) ? trim($data['notes']) : '';

        if (empty($commission_ids)) {
            Response::error("commission_ids array is required", 400);
            return;
        }

        try {
            $this->conn->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($commission_ids), '?'));
            
            $stmt = $this->conn->prepare("
                UPDATE commissions
                SET status = 'Paid',
                    payment_date = CURRENT_TIMESTAMP,
                    notes = COALESCE(NULLIF(?, ''), notes)
                WHERE commission_id IN ($placeholders)
                  AND status = 'Pending'
            ");

            $params = array_merge([$notes], $commission_ids);
            $stmt->execute($params);
            
            $updated_count = $stmt->rowCount();

            $this->conn->commit();

            Response::success([
                "updated_count" => $updated_count
            ], "تم تحديث حالة العمولات بنجاح");

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * Get commission statistics for a partner
     * GET /api/commissions/partner/{partner_id}/stats
     * Query params: ?month=12&year=2025
     */
    public function getPartnerStats() {
        header('Content-Type: application/json; charset=utf-8');
        
        $partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0;
        $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
        $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

        if ($partner_id <= 0) {
            Response::error("partner_id is required", 400);
            return;
        }

        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_bookings,
                    SUM(booking_amount) as total_revenue,
                    SUM(commission_amount) as total_commission,
                    SUM(partner_revenue) as total_partner_revenue,
                    COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_count,
                    COUNT(CASE WHEN status = 'Paid' THEN 1 END) as paid_count,
                    SUM(CASE WHEN status = 'Pending' THEN commission_amount ELSE 0 END) as pending_amount,
                    SUM(CASE WHEN status = 'Paid' THEN commission_amount ELSE 0 END) as paid_amount
                FROM commissions
                WHERE partner_id = :pid
                  AND EXTRACT(MONTH FROM created_at) = :month
                  AND EXTRACT(YEAR FROM created_at) = :year
            ");
            
            $stmt->execute([
                ':pid' => $partner_id,
                ':month' => $month,
                ':year' => $year
            ]);
            
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            Response::success([
                "partner_id" => $partner_id,
                "period" => "$year-$month",
                "stats" => $stats
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * Handle partial refund - recalculate commission
     * POST /api/commissions/partial-refund
     * Body: { "booking_id": 123, "new_amount": 500 }
     */
    public function handlePartialRefund() {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $booking_id = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
        $new_amount = isset($data['new_amount']) ? (float)$data['new_amount'] : 0;

        if ($booking_id <= 0 || $new_amount <= 0) {
            Response::error("booking_id and new_amount are required", 400);
            return;
        }

        try {
            $stmt = $this->conn->prepare("SELECT recalculate_commission(:bid, :amount)");
            $stmt->execute([
                ':bid' => $booking_id,
                ':amount' => $new_amount
            ]);
            
            $result = $stmt->fetchColumn();
            $result_data = json_decode($result, true);

            Response::send(json_decode($result, true));

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * Get refund commission reports
     * GET /api/commissions/refunds
     */
    public function getRefundReports() {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM v_refund_commissions
                ORDER BY created_at DESC
            ");
            $stmt->execute();
            $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success([
                "count" => count($refunds),
                "refunds" => $refunds
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
