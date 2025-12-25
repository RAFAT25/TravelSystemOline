<?php

namespace Travel\Controllers;

use Travel\Helpers\Response;
use Travel\Config\Database;
use PDO;
use Exception;

class CancelController {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    /**
     * معاينة الإلغاء (Preview)
     * هدفها إخبار المستخدم بالمبلغ المسترد قبل التنفيذ
     */
    public function preview() {
        header('Content-Type: application/json; charset=utf-8');

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        $booking_id = isset($data['booking_id']) ? (int)$data['booking_id'] : (isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0);

        if ($booking_id <= 0) {
            Response::error("Invalid booking_id", 400);
            return;
        }

        try {
            // 1) جلب بيانات الحجز والرحلة
            $sql = "SELECT b.*, t.departure_time, t.trip_id FROM bookings b 
                    JOIN trips t ON t.trip_id = b.trip_id 
                    WHERE b.booking_id = :bid LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                Response::notFound("Booking not found");
                return;
            }

            if ($booking['booking_status'] === 'Cancelled') {
                Response::error("Booking already cancelled", 409);
                return;
            }

            // حساب الوقت المتبقي بالساعات
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $dep = new \DateTimeImmutable($booking['departure_time'], new \DateTimeZone('UTC'));
            $hours_before_departure = ($dep->getTimestamp() - $now->getTimestamp()) / 3600.0;

            // استخلاص البيانات الأساسية
            $total_price = (float)$booking['total_price'];
            $payment_status = $booking['payment_status'] ?? 'Unpaid';
            $cancel_policy_id = (int)$booking['cancel_policy_id'];

            // المنطق: إذا كان الحجز غير مدفوع أو فات وقت الاسترداد
            $refund_percentage = 0;
            $cancellation_fee  = 0;
            $refund_amount     = 0;
            $rule_id           = 0;

            if ($payment_status !== 'Unpaid') {
                // البحث عن قاعدة الإلغاء المناسبة
                $sqlRule = "SELECT * FROM cancel_policy_rules 
                            WHERE cancel_policy_id = :cpid AND is_active = TRUE 
                            AND min_hours_before_departure <= :hours 
                            AND (max_hours_before_departure IS NULL OR :hours < max_hours_before_departure)
                            ORDER BY min_hours_before_departure DESC LIMIT 1";
                $stmtRule = $this->conn->prepare($sqlRule);
                $stmtRule->execute([':cpid' => $cancel_policy_id, ':hours' => $hours_before_departure]);
                $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);

                if ($rule) {
                    $rule_id = (int)($rule['cancel_policy_rule_id'] ?? $rule['id'] ?? 0);
                    $refund_percentage = (float)$rule['refund_percentage'];
                    $cancellation_fee  = (float)$rule['cancellation_fee'];
                    $refund_amount = max(0, round(($total_price * $refund_percentage / 100.0) - $cancellation_fee, 2));
                } else {
                    // إذا لم توجد قاعدة (قريب جداً من الرحلة) -> استرداد صفر ورسوم كاملة
                    $cancellation_fee = $total_price;
                }
            }

            Response::success([
                "booking_id" => $booking_id,
                "total_price" => $total_price,
                "hours_before_departure" => round($hours_before_departure, 2),
                "rule" => [
                    "cancel_policy_rule_id" => $rule_id,
                    "refund_percentage" => $refund_percentage,
                    "cancellation_fee" => $cancellation_fee
                ],
                "calculated" => [
                    "refund_amount" => $refund_amount,
                    "non_refundable_part" => max(0, $total_price - $refund_amount)
                ]
            ]);

        } catch (Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    /**
     * تأكيد الإلغاء الفعلي (Confirm)
     */
    public function confirm() {
        header('Content-Type: application/json; charset=utf-8');
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $booking_id = isset($data['booking_id']) ? (int)$data['booking_id'] : 0;
        $reason = $data['reason'] ?? 'Customer request';

        try {
            $this->conn->beginTransaction();

            // 1) جلب البيانات وقفل السجل (FOR UPDATE)
            $stmt = $this->conn->prepare("SELECT b.*, t.departure_time FROM bookings b JOIN trips t ON t.trip_id = b.trip_id WHERE b.booking_id = :bid FOR UPDATE");
            $stmt->execute([':bid' => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking || $booking['booking_status'] === 'Cancelled') {
                throw new Exception("Booking invalid or already cancelled");
            }

            // 2) حساب الحسبة (نفس منطق الـ Preview تماماً)
            $total_price = (float)$booking['total_price'];
            $dep = new \DateTimeImmutable($booking['departure_time'], new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $hours = ($dep->getTimestamp() - $now->getTimestamp()) / 3600.0;

            $refund_amount = 0; $refund_pct = 0; $fee = $total_price; $rule_id = 0;

            if ($booking['payment_status'] !== 'Unpaid') {
                $stmtRule = $this->conn->prepare("SELECT * FROM cancel_policy_rules WHERE cancel_policy_id = :cpid AND is_active = TRUE AND min_hours_before_departure <= :h AND (max_hours_before_departure IS NULL OR :h < max_hours_before_departure) ORDER BY min_hours_before_departure DESC LIMIT 1");
                $stmtRule->execute([':cpid' => $booking['cancel_policy_id'], ':h' => $hours]);
                $r = $stmtRule->fetch(PDO::FETCH_ASSOC);

                if ($r) {
                    $rule_id = (int)($r['cancel_policy_rule_id'] ?? $r['id'] ?? 0);
                    $refund_pct = (float)$r['refund_percentage'];
                    $fee = (float)$r['cancellation_fee'];
                    $refund_amount = max(0, round(($total_price * $refund_pct / 100.0) - $fee, 2));
                }
            }

            // 3) تحديث الحجز والركاب والمقاعد
            $new_p_status = ($booking['payment_status'] === 'Paid') ? 'Refunded' : $booking['payment_status'];
            
            $this->conn->prepare("UPDATE bookings SET booking_status='Cancelled', payment_status=:ps, cancel_reason=:re, cancel_timestamp=NOW() WHERE booking_id=:bid")->execute([':ps'=>$new_p_status, ':re'=>$reason, ':bid'=>$booking_id]);
            $this->conn->prepare("UPDATE passengers SET passenger_status='Cancelled' WHERE booking_id=:bid")->execute([':bid'=>$booking_id]);
            
            // تحرير المقاعد
            $stmtS = $this->conn->prepare("SELECT seat_id FROM passengers WHERE booking_id=:bid");
            $stmtS->execute([':bid'=>$booking_id]);
            $seats = $stmtS->fetchAll(PDO::FETCH_COLUMN);
            if($seats) {
                $ids = implode(',', $seats);
                $this->conn->query("UPDATE seats SET is_available=TRUE WHERE seat_id IN ($ids)");
            }

            // 4) تسجيل في جدول الإلغاءات
            $sqlIns = "INSERT INTO booking_cancellations (booking_id, cancel_policy_id, cancel_policy_rule_id, refund_percentage, cancellation_fee, refund_amount, reason, hours_before_departure) 
                       VALUES (:bid, :cpid, :rid, :rp, :cf, :ra, :reason, :h)";
            $this->conn->prepare($sqlIns)->execute([
                ':bid'=>$booking_id, ':cpid'=>$booking['cancel_policy_id'], ':rid'=>$rule_id, 
                ':rp'=>$refund_pct, ':cf'=>$fee, ':ra'=>$refund_amount, ':reason'=>$reason, ':h'=>$hours
            ]);

            $this->conn->commit();
            Response::success(["refund_amount" => $refund_amount, "status" => "Cancelled"]);

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) $this->conn->rollBack();
            Response::error($e->getMessage(), 500);
        }
    }
}