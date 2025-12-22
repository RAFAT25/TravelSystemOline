-- ============================================
-- نظام تتبع عمليات الاسترداد الفعلية
-- Refund Transaction Tracking System
-- ============================================

-- 1. إنشاء جدول refund_transactions
CREATE TABLE IF NOT EXISTS refund_transactions (
    refund_id SERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL REFERENCES bookings(booking_id) ON DELETE CASCADE,
    
    -- معلومات الدفع الأصلية
    original_payment_method VARCHAR(50),  -- كيف دفع العميل (Electronic, Cash, Kareemi)
    original_transaction_id VARCHAR(100), -- رقم المعاملة الأصلية
    
    -- معلومات الإرجاع
    refund_method VARCHAR(50) NOT NULL,   -- Bank Transfer, Kareemi, Cash, Same as Original
    refund_amount NUMERIC(10,2) NOT NULL,
    refund_fee NUMERIC(10,2) DEFAULT 0,   -- رسوم الإلغاء (إن وجدت)
    net_refund NUMERIC(10,2),             -- المبلغ الصافي المُرجع
    
    -- تفاصيل الحساب
    bank_name VARCHAR(100),
    bank_account VARCHAR(100),
    account_holder_name VARCHAR(100),
    kareemi_number VARCHAR(20),
    
    -- حالة الاسترداد
    refund_status VARCHAR(20) DEFAULT 'Pending', -- Pending, Processing, Completed, Failed, Cancelled
    refund_reference VARCHAR(100),        -- رقم معاملة الإرجاع
    
    -- Audit Trail
    initiated_by BIGINT REFERENCES users(user_id),     -- من بدأ الاسترداد
    processed_by BIGINT REFERENCES users(user_id),     -- من عالج الإرجاع
    completed_by BIGINT REFERENCES users(user_id),     -- من أكمل الإرجاع
    
    -- ملاحظات وتواريخ
    notes TEXT,
    customer_notes TEXT,                  -- ملاحظات للعميل
    internal_notes TEXT,                  -- ملاحظات داخلية
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processing_started_at TIMESTAMP,
    completed_at TIMESTAMP,
    
    -- Constraints
    CONSTRAINT chk_refund_amount CHECK (refund_amount > 0),
    CONSTRAINT chk_net_refund CHECK (net_refund >= 0)
);

-- Indexes للأداء
CREATE INDEX IF NOT EXISTS idx_refund_transactions_booking ON refund_transactions(booking_id);
CREATE INDEX IF NOT EXISTS idx_refund_transactions_status ON refund_transactions(refund_status);
CREATE INDEX IF NOT EXISTS idx_refund_transactions_created ON refund_transactions(created_at);
CREATE INDEX IF NOT EXISTS idx_refund_transactions_initiated_by ON refund_transactions(initiated_by);

-- ============================================
-- 2. Trigger لإنشاء سجل استرداد تلقائياً
-- ============================================

DROP TRIGGER IF EXISTS trg_create_refund_transaction ON bookings;
DROP FUNCTION IF EXISTS create_refund_transaction();

CREATE OR REPLACE FUNCTION create_refund_transaction()
RETURNS TRIGGER AS $$
DECLARE
    v_refund_exists BOOLEAN;
    v_cancel_policy_id BIGINT;
    v_departure_time TIMESTAMP;
    v_hours_before INT;
    v_refund_percentage NUMERIC;
    v_refund_fee NUMERIC;
    v_net_refund NUMERIC;
BEGIN
    -- عند تغيير الحالة إلى Refunded
    IF NEW.payment_status = 'Refunded' AND (OLD.payment_status IS NULL OR OLD.payment_status != 'Refunded') THEN
        
        -- التحقق من عدم وجود سجل استرداد مسبق
        SELECT EXISTS(
            SELECT 1 FROM refund_transactions WHERE booking_id = NEW.booking_id
        ) INTO v_refund_exists;
        
        IF NOT v_refund_exists THEN
            
            -- جلب معلومات سياسة الإلغاء والرحلة
            SELECT b.cancel_policy_id, t.departure_time
            INTO v_cancel_policy_id, v_departure_time
            FROM bookings b
            JOIN trips t ON t.trip_id = b.trip_id
            WHERE b.booking_id = NEW.booking_id;
            
            -- حساب الساعات المتبقية قبل الرحلة
            v_hours_before := EXTRACT(EPOCH FROM (v_departure_time - CURRENT_TIMESTAMP)) / 3600;
            
            -- جلب نسبة الاسترداد من سياسة الإلغاء
            SELECT refund_percentage INTO v_refund_percentage
            FROM cancel_policy_rules
            WHERE cancel_policy_id = v_cancel_policy_id
              AND hours_before_departure <= v_hours_before
            ORDER BY hours_before_departure DESC
            LIMIT 1;
            
            -- إذا لم توجد سياسة، استرداد كامل (100%)
            IF v_refund_percentage IS NULL THEN
                v_refund_percentage := 100;
            END IF;
            
            -- حساب رسوم الإلغاء والمبلغ الصافي
            v_refund_fee := NEW.total_price * ((100 - v_refund_percentage) / 100);
            v_net_refund := NEW.total_price - v_refund_fee;
            
            -- إنشاء سجل استرداد جديد مع الرسوم
            INSERT INTO refund_transactions (
                booking_id,
                original_payment_method,
                original_transaction_id,
                refund_method,
                refund_amount,
                refund_fee,
                net_refund,
                refund_status,
                customer_notes
            ) VALUES (
                NEW.booking_id,
                NEW.payment_method,
                NEW.gateway_transaction_id,
                'Pending',
                NEW.total_price,
                v_refund_fee,
                v_net_refund,
                'Pending',
                CASE 
                    WHEN v_refund_fee > 0 THEN
                        CONCAT('تم قبول طلب الاسترداد. رسوم الإلغاء: ', v_refund_fee, ' ريال. المبلغ المسترد: ', v_net_refund, ' ريال.')
                    ELSE
                        'تم قبول طلب الاسترداد. سيتم استرداد المبلغ كاملاً.'
                END
            );
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_create_refund_transaction
AFTER UPDATE OF payment_status ON bookings
FOR EACH ROW
EXECUTE FUNCTION create_refund_transaction();

-- ============================================
-- 3. View شامل لتقارير الاستردادات
-- ============================================

CREATE OR REPLACE VIEW v_refund_details AS
SELECT 
    rt.refund_id,
    rt.booking_id,
    b.booking_date,
    b.total_price AS booking_amount,
    
    -- معلومات العميل
    u.user_id,
    u.full_name AS customer_name,
    u.email AS customer_email,
    u.phone_number AS customer_phone,
    
    -- معلومات الدفع
    rt.original_payment_method,
    rt.original_transaction_id,
    
    -- معلومات الإرجاع
    rt.refund_method,
    rt.refund_amount,
    rt.refund_fee,
    rt.net_refund,
    rt.refund_status,
    rt.refund_reference,
    
    -- تفاصيل الحساب
    rt.bank_name,
    rt.bank_account,
    rt.account_holder_name,
    rt.kareemi_number,
    
    -- Audit Trail
    u1.full_name AS initiated_by_name,
    u2.full_name AS processed_by_name,
    u3.full_name AS completed_by_name,
    
    -- ملاحظات
    rt.customer_notes,
    rt.internal_notes,
    
    -- تواريخ
    rt.created_at,
    rt.processing_started_at,
    rt.completed_at,
    
    -- معلومات الرحلة
    t.trip_id,
    t.departure_time,
    r.origin_city,
    r.destination_city,
    p.company_name AS partner_name
    
FROM refund_transactions rt
JOIN bookings b ON b.booking_id = rt.booking_id
JOIN users u ON u.user_id = b.user_id
LEFT JOIN users u1 ON u1.user_id = rt.initiated_by
LEFT JOIN users u2 ON u2.user_id = rt.processed_by
LEFT JOIN users u3 ON u3.user_id = rt.completed_by
JOIN trips t ON t.trip_id = b.trip_id
JOIN routes r ON r.route_id = t.route_id
JOIN partners p ON p.partner_id = t.partner_id;

-- ============================================
-- 4. دالة لحساب رسوم الإلغاء
-- ============================================

CREATE OR REPLACE FUNCTION calculate_refund_fee(
    p_booking_id BIGINT
)
RETURNS NUMERIC AS $$
DECLARE
    v_total_price NUMERIC;
    v_departure_time TIMESTAMP;
    v_cancel_policy_id BIGINT;
    v_hours_before INT;
    v_refund_percentage NUMERIC;
    v_fee NUMERIC;
BEGIN
    -- جلب معلومات الحجز
    SELECT b.total_price, t.departure_time, b.cancel_policy_id
    INTO v_total_price, v_departure_time, v_cancel_policy_id
    FROM bookings b
    JOIN trips t ON t.trip_id = b.trip_id
    WHERE b.booking_id = p_booking_id;
    
    -- حساب الساعات المتبقية
    v_hours_before := EXTRACT(EPOCH FROM (v_departure_time - CURRENT_TIMESTAMP)) / 3600;
    
    -- جلب نسبة الاسترداد من سياسة الإلغاء
    SELECT refund_percentage INTO v_refund_percentage
    FROM cancel_policy_rules
    WHERE cancel_policy_id = v_cancel_policy_id
      AND hours_before_departure <= v_hours_before
    ORDER BY hours_before_departure DESC
    LIMIT 1;
    
    -- إذا لم توجد سياسة، استرداد كامل
    IF v_refund_percentage IS NULL THEN
        v_refund_percentage := 100;
    END IF;
    
    -- حساب الرسوم
    v_fee := v_total_price * ((100 - v_refund_percentage) / 100);
    
    RETURN v_fee;
END;
$$ LANGUAGE plpgsql;

-- ============================================
-- 5. دالة لتحديث حالة الاسترداد
-- ============================================

CREATE OR REPLACE FUNCTION update_refund_status(
    p_refund_id BIGINT,
    p_new_status VARCHAR(20),
    p_employee_id BIGINT,
    p_notes TEXT DEFAULT NULL
)
RETURNS JSON AS $$
DECLARE
    v_old_status VARCHAR(20);
BEGIN
    -- جلب الحالة القديمة
    SELECT refund_status INTO v_old_status
    FROM refund_transactions
    WHERE refund_id = p_refund_id;
    
    IF v_old_status IS NULL THEN
        RETURN json_build_object('success', false, 'error', 'Refund not found');
    END IF;
    
    -- تحديث بناءً على الحالة الجديدة
    CASE p_new_status
        WHEN 'Processing' THEN
            UPDATE refund_transactions
            SET refund_status = 'Processing',
                processed_by = p_employee_id,
                processing_started_at = CURRENT_TIMESTAMP,
                internal_notes = COALESCE(p_notes, internal_notes)
            WHERE refund_id = p_refund_id;
            
        WHEN 'Completed' THEN
            UPDATE refund_transactions
            SET refund_status = 'Completed',
                completed_by = p_employee_id,
                completed_at = CURRENT_TIMESTAMP,
                internal_notes = COALESCE(p_notes, internal_notes)
            WHERE refund_id = p_refund_id;
            
        WHEN 'Failed' THEN
            UPDATE refund_transactions
            SET refund_status = 'Failed',
                internal_notes = COALESCE(p_notes, internal_notes)
            WHERE refund_id = p_refund_id;
            
        ELSE
            RETURN json_build_object('success', false, 'error', 'Invalid status');
    END CASE;
    
    RETURN json_build_object(
        'success', true,
        'refund_id', p_refund_id,
        'old_status', v_old_status,
        'new_status', p_new_status
    );
END;
$$ LANGUAGE plpgsql;

-- ============================================
-- تم الانتهاء من نظام تتبع الاستردادات
-- Refund tracking system completed
-- ============================================

-- للاختبار:
-- SELECT * FROM refund_transactions;
-- SELECT * FROM v_refund_details;
-- SELECT calculate_refund_fee(123);
