-- ============================================
-- نظام الاستردادات الذكي للعمولات
-- Smart Refund Handling for Commissions
-- ============================================

-- 1. إضافة حالة 'Cancelled' لجدول commissions
-- Add 'Cancelled' status to commissions table

-- تحديث نوع البيانات للسماح بحالة جديدة
-- (إذا كان status من نوع ENUM، قد تحتاج لتعديل النوع)
-- إذا كان VARCHAR، هذا الأمر غير مطلوب

-- ============================================
-- 2. إنشاء Trigger للتعامل مع الاستردادات
-- Create trigger to handle refunds intelligently
-- ============================================

-- حذف الـ Trigger القديم إذا كان موجوداً
DROP TRIGGER IF EXISTS trg_handle_refund ON bookings;
DROP FUNCTION IF EXISTS handle_refund();

-- إنشاء الدالة
CREATE OR REPLACE FUNCTION handle_refund()
RETURNS TRIGGER AS $$
DECLARE
    v_trip_departure TIMESTAMP;
    v_commission_id BIGINT;
    v_commission_status VARCHAR(20);
    v_commission_amount NUMERIC;
BEGIN
    -- عند تغيير الحالة إلى Refunded
    IF NEW.payment_status = 'Refunded' AND (OLD.payment_status IS NULL OR OLD.payment_status != 'Refunded') THEN
        
        -- جلب موعد الرحلة
        SELECT t.departure_time INTO v_trip_departure
        FROM trips t
        WHERE t.trip_id = NEW.trip_id;
        
        -- التحقق من وجود عمولة
        SELECT commission_id, status, commission_amount 
        INTO v_commission_id, v_commission_status, v_commission_amount
        FROM commissions
        WHERE booking_id = NEW.booking_id
        LIMIT 1;
        
        IF v_commission_id IS NOT NULL THEN
            
            -- إذا كان الاسترداد قبل موعد الرحلة
            IF v_trip_departure > CURRENT_TIMESTAMP THEN
                
                -- إلغاء العمولة
                UPDATE commissions
                SET status = 'Cancelled',
                    notes = CONCAT(
                        COALESCE(notes, ''), 
                        E'\n[', 
                        TO_CHAR(CURRENT_TIMESTAMP, 'YYYY-MM-DD HH24:MI:SS'),
                        '] Cancelled due to refund before trip departure'
                    )
                WHERE commission_id = v_commission_id;
                
                -- خصم من daily_commissions
                UPDATE daily_commissions
                SET total_bookings = total_bookings - 1,
                    total_revenue = total_revenue - NEW.total_price,
                    total_commission = total_commission - v_commission_amount
                WHERE commission_date = CURRENT_DATE;
                
                -- تسجيل في logs (اختياري)
                RAISE NOTICE 'Commission % cancelled for booking % (refund before trip)', v_commission_id, NEW.booking_id;
                
            ELSE
                -- بعد الرحلة: العمولة تبقى ولكن نسجل الملاحظة
                UPDATE commissions
                SET notes = CONCAT(
                        COALESCE(notes, ''), 
                        E'\n[', 
                        TO_CHAR(CURRENT_TIMESTAMP, 'YYYY-MM-DD HH24:MI:SS'),
                        '] Refund issued after trip - commission retained'
                    )
                WHERE commission_id = v_commission_id;
                
                RAISE NOTICE 'Commission % retained for booking % (refund after trip)', v_commission_id, NEW.booking_id;
            END IF;
            
            -- إذا كانت العمولة مدفوعة بالفعل وتم الإلغاء
            IF v_commission_status = 'Paid' AND v_trip_departure > CURRENT_TIMESTAMP THEN
                -- تسجيل دين على الشريك (يمكن إضافة جدول partner_debts)
                RAISE WARNING 'Commission % was already paid but now cancelled - manual adjustment needed', v_commission_id;
            END IF;
            
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ربط الـ Trigger
CREATE TRIGGER trg_handle_refund
AFTER UPDATE OF payment_status ON bookings
FOR EACH ROW
EXECUTE FUNCTION handle_refund();

-- ============================================
-- 3. إنشاء View لتقارير الاستردادات
-- Create view for refund reports
-- ============================================

CREATE OR REPLACE VIEW v_refund_commissions AS
SELECT 
    c.commission_id,
    c.booking_id,
    b.booking_date,
    b.payment_status,
    c.partner_id,
    p.company_name AS partner_name,
    c.booking_amount,
    c.commission_amount,
    c.status AS commission_status,
    t.departure_time,
    CASE 
        WHEN b.payment_status = 'Refunded' AND t.departure_time > b.booking_date 
        THEN 'Before Trip'
        WHEN b.payment_status = 'Refunded' AND t.departure_time <= b.booking_date 
        THEN 'After Trip'
        ELSE 'N/A'
    END AS refund_timing,
    c.notes,
    c.created_at,
    u.full_name AS customer_name
FROM commissions c
JOIN bookings b ON b.booking_id = c.booking_id
JOIN partners p ON p.partner_id = c.partner_id
JOIN trips t ON t.trip_id = c.trip_id
JOIN users u ON u.user_id = b.user_id
WHERE b.payment_status = 'Refunded' OR c.status = 'Cancelled';

-- ============================================
-- 4. دالة لإعادة حساب العمولة (للاسترداد الجزئي)
-- Function to recalculate commission (for partial refunds)
-- ============================================

CREATE OR REPLACE FUNCTION recalculate_commission(
    p_booking_id BIGINT,
    p_new_amount NUMERIC
)
RETURNS JSON AS $$
DECLARE
    v_commission_id BIGINT;
    v_old_amount NUMERIC;
    v_commission_rate NUMERIC;
    v_new_commission NUMERIC;
    v_difference NUMERIC;
BEGIN
    -- جلب العمولة الحالية
    SELECT commission_id, booking_amount, commission_percentage
    INTO v_commission_id, v_old_amount, v_commission_rate
    FROM commissions
    WHERE booking_id = p_booking_id
    LIMIT 1;
    
    IF v_commission_id IS NULL THEN
        RETURN json_build_object('success', false, 'error', 'Commission not found');
    END IF;
    
    -- حساب العمولة الجديدة
    v_new_commission := p_new_amount * (v_commission_rate / 100);
    v_difference := v_old_amount - p_new_amount;
    
    -- تحديث العمولة
    UPDATE commissions
    SET booking_amount = p_new_amount,
        commission_amount = v_new_commission,
        partner_revenue = p_new_amount - v_new_commission,
        notes = CONCAT(
            COALESCE(notes, ''),
            E'\n[',
            TO_CHAR(CURRENT_TIMESTAMP, 'YYYY-MM-DD HH24:MI:SS'),
            '] Partial refund: ',
            v_difference,
            ' - New amount: ',
            p_new_amount
        )
    WHERE commission_id = v_commission_id;
    
    RETURN json_build_object(
        'success', true,
        'commission_id', v_commission_id,
        'old_amount', v_old_amount,
        'new_amount', p_new_amount,
        'new_commission', v_new_commission
    );
END;
$$ LANGUAGE plpgsql;

-- ============================================
-- 5. إنشاء Index للأداء
-- Create indexes for performance
-- ============================================

CREATE INDEX IF NOT EXISTS idx_commissions_status_cancelled 
ON commissions(status) WHERE status = 'Cancelled';

CREATE INDEX IF NOT EXISTS idx_bookings_refunded 
ON bookings(payment_status) WHERE payment_status = 'Refunded';

-- ============================================
-- تم الانتهاء من نظام الاستردادات الذكي
-- Smart refund system completed
-- ============================================

-- للاختبار:
-- 1. إنشاء حجز وتأكيده
-- 2. تغيير payment_status إلى 'Refunded'
-- 3. التحقق من تحديث commission.status

-- مثال:
-- UPDATE bookings SET payment_status = 'Refunded' WHERE booking_id = 123;
-- SELECT * FROM commissions WHERE booking_id = 123;
-- SELECT * FROM v_refund_commissions;
