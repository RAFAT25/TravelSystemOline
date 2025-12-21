-- ============================================
-- تحسينات نظام العمولات
-- Commission System Improvements
-- ============================================

-- 1. إصلاح جدول commissions
-- Fix commissions table structure

-- إضافة الأعمدة المفقودة
ALTER TABLE commissions 
ADD COLUMN IF NOT EXISTS commission_id SERIAL PRIMARY KEY,
ADD COLUMN IF NOT EXISTS booking_id BIGINT,
ADD COLUMN IF NOT EXISTS partner_id BIGINT,
ADD COLUMN IF NOT EXISTS trip_id BIGINT,
ADD COLUMN IF NOT EXISTS booking_amount NUMERIC(10,2),
ADD COLUMN IF NOT EXISTS partner_revenue NUMERIC(10,2),
ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'Pending',
ADD COLUMN IF NOT EXISTS payment_date TIMESTAMP,
ADD COLUMN IF NOT EXISTS calculated_by BIGINT,
ADD COLUMN IF NOT EXISTS notes TEXT;

-- إضافة Foreign Keys
ALTER TABLE commissions
ADD CONSTRAINT fk_commissions_booking 
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
ADD CONSTRAINT fk_commissions_partner 
    FOREIGN KEY (partner_id) REFERENCES partners(partner_id) ON DELETE CASCADE,
ADD CONSTRAINT fk_commissions_trip 
    FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE SET NULL,
ADD CONSTRAINT fk_commissions_calculated_by 
    FOREIGN KEY (calculated_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- إضافة Indexes للأداء
CREATE INDEX IF NOT EXISTS idx_commissions_booking ON commissions(booking_id);
CREATE INDEX IF NOT EXISTS idx_commissions_partner ON commissions(partner_id);
CREATE INDEX IF NOT EXISTS idx_commissions_status ON commissions(status);
CREATE INDEX IF NOT EXISTS idx_commissions_date ON commissions(created_at);
CREATE INDEX IF NOT EXISTS idx_commissions_trip ON commissions(trip_id);

-- ============================================
-- 2. إنشاء Trigger للحساب التلقائي
-- Create automatic calculation trigger
-- ============================================

-- حذف الـ Trigger القديم إذا كان موجوداً
DROP TRIGGER IF EXISTS trg_calculate_commission ON bookings;
DROP FUNCTION IF EXISTS calculate_commission();

-- إنشاء الدالة
CREATE OR REPLACE FUNCTION calculate_commission()
RETURNS TRIGGER AS $$
DECLARE
    v_partner_id BIGINT;
    v_trip_id BIGINT;
    v_commission_rate NUMERIC;
    v_commission_amount NUMERIC;
    v_partner_revenue NUMERIC;
    v_existing_commission BIGINT;
BEGIN
    -- التحقق من أن الحالة تغيرت إلى Paid
    IF NEW.payment_status = 'Paid' AND (OLD.payment_status IS NULL OR OLD.payment_status != 'Paid') THEN
        
        -- التحقق من عدم وجود عمولة مسبقة لهذا الحجز
        SELECT commission_id INTO v_existing_commission
        FROM commissions
        WHERE booking_id = NEW.booking_id
        LIMIT 1;
        
        -- إذا لم توجد عمولة مسبقة
        IF v_existing_commission IS NULL THEN
            
            -- جلب معلومات الشريك والرحلة
            SELECT t.partner_id, t.trip_id, p.commission_percentage
            INTO v_partner_id, v_trip_id, v_commission_rate
            FROM trips t
            JOIN partners p ON p.partner_id = t.partner_id
            WHERE t.trip_id = NEW.trip_id;
            
            -- التحقق من وجود بيانات
            IF v_partner_id IS NOT NULL THEN
                
                -- حساب العمولة
                v_commission_amount := NEW.total_price * (v_commission_rate / 100);
                v_partner_revenue := NEW.total_price - v_commission_amount;
                
                -- إدخال العمولة
                INSERT INTO commissions (
                    booking_id,
                    partner_id,
                    trip_id,
                    booking_amount,
                    commission_percentage,
                    commission_amount,
                    partner_revenue,
                    status,
                    created_at
                ) VALUES (
                    NEW.booking_id,
                    v_partner_id,
                    v_trip_id,
                    NEW.total_price,
                    v_commission_rate,
                    v_commission_amount,
                    v_partner_revenue,
                    'Pending',
                    CURRENT_TIMESTAMP
                );
                
                -- تحديث daily_commissions
                INSERT INTO daily_commissions (
                    commission_date,
                    total_bookings,
                    total_revenue,
                    total_commission,
                    created_at
                ) VALUES (
                    CURRENT_DATE,
                    1,
                    NEW.total_price,
                    v_commission_amount,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (commission_date) 
                DO UPDATE SET
                    total_bookings = daily_commissions.total_bookings + 1,
                    total_revenue = daily_commissions.total_revenue + EXCLUDED.total_revenue,
                    total_commission = daily_commissions.total_commission + EXCLUDED.total_commission;
                
            END IF;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ربط الـ Trigger
CREATE TRIGGER trg_calculate_commission
AFTER UPDATE OF payment_status ON bookings
FOR EACH ROW
EXECUTE FUNCTION calculate_commission();

-- ============================================
-- 3. إضافة Unique Constraint لـ daily_commissions
-- ============================================

-- إضافة constraint لمنع التكرار
ALTER TABLE daily_commissions
ADD CONSTRAINT unique_commission_date UNIQUE (commission_date);

-- ============================================
-- 4. إنشاء View للتقارير
-- ============================================

CREATE OR REPLACE VIEW v_commission_summary AS
SELECT 
    c.commission_id,
    c.booking_id,
    b.booking_date,
    c.partner_id,
    p.company_name AS partner_name,
    c.trip_id,
    t.departure_time,
    r.origin_city,
    r.destination_city,
    c.booking_amount,
    c.commission_percentage,
    c.commission_amount,
    c.partner_revenue,
    c.status,
    c.payment_date,
    c.created_at,
    u.full_name AS customer_name
FROM commissions c
JOIN bookings b ON b.booking_id = c.booking_id
JOIN partners p ON p.partner_id = c.partner_id
JOIN trips t ON t.trip_id = c.trip_id
JOIN routes r ON r.route_id = t.route_id
JOIN users u ON u.user_id = b.user_id;

-- ============================================
-- تم الانتهاء من التحسينات
-- Improvements completed
-- ============================================

-- للتحقق من نجاح التطبيق:
-- SELECT * FROM commissions LIMIT 5;
-- SELECT * FROM v_commission_summary LIMIT 5;
