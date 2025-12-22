-- 0. Handle Legacy Schema (if exists)
DO $$
BEGIN
    -- Make 'days_before_trip' nullable if it exists
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cancel_policies' AND column_name = 'days_before_trip') THEN
        ALTER TABLE cancel_policies ALTER COLUMN days_before_trip DROP NOT NULL;
        ALTER TABLE cancel_policies ALTER COLUMN days_before_trip SET DEFAULT 0;
    END IF;

    -- Make 'refund_percentage' nullable if it exists (legacy column, replaced by rules)
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cancel_policies' AND column_name = 'refund_percentage') THEN
        ALTER TABLE cancel_policies ALTER COLUMN refund_percentage DROP NOT NULL;
        ALTER TABLE cancel_policies ALTER COLUMN refund_percentage SET DEFAULT 0;
    END IF;

     -- Make 'cancellation_fee' nullable if it exists (legacy column, replaced by rules)
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'cancel_policies' AND column_name = 'cancellation_fee') THEN
        ALTER TABLE cancel_policies ALTER COLUMN cancellation_fee DROP NOT NULL;
        ALTER TABLE cancel_policies ALTER COLUMN cancellation_fee SET DEFAULT 0;
    END IF;
END $$;

-- 1. Add passenger_status to passengers table
ALTER TABLE passengers 
ADD COLUMN IF NOT EXISTS passenger_status VARCHAR(20) DEFAULT 'Active';

-- 2. Create cancel_policies table (if not exists)
CREATE TABLE IF NOT EXISTS cancel_policies (
    cancel_policy_id SERIAL PRIMARY KEY,
    partner_id INT NOT NULL,
    policy_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Create cancel_policy_rules table
CREATE TABLE IF NOT EXISTS cancel_policy_rules (
    cancel_policy_rule_id SERIAL PRIMARY KEY,
    cancel_policy_id INT NOT NULL REFERENCES cancel_policies(cancel_policy_id),
    min_hours_before_departure DECIMAL(5,2) NOT NULL, -- e.g. 24.00
    max_hours_before_departure DECIMAL(5,2),          -- e.g. 48.00 (NULL means infinity)
    refund_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    cancellation_fee DECIMAL(10,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Add cancel_policy_id to bookings table
ALTER TABLE bookings 
ADD COLUMN IF NOT EXISTS cancel_policy_id INT;

ALTER TABLE bookings 
ADD COLUMN IF NOT EXISTS cancel_reason TEXT;

ALTER TABLE bookings 
ADD COLUMN IF NOT EXISTS cancel_timestamp TIMESTAMP;

-- 5. Create booking_cancellations table (History)
CREATE TABLE IF NOT EXISTS booking_cancellations (
    cancellation_id SERIAL PRIMARY KEY,
    booking_id INT NOT NULL REFERENCES bookings(booking_id),
    cancel_policy_id INT,
    cancel_policy_rule_id INT,
    refund_percentage DECIMAL(5,2),
    cancellation_fee DECIMAL(10,2),
    refund_amount DECIMAL(10,2),
    reason TEXT,
    hours_before_departure DECIMAL(5,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Insert dummy policy for testing (Optional - Adjust Partner ID as needed)
-- We supply default values for legacy columns (0) just in case the ALTER failed or triggers enforce it.
INSERT INTO cancel_policies (partner_id, policy_name, is_default)
SELECT 1, 'Standard Cancellation Policy', TRUE
WHERE NOT EXISTS (SELECT 1 FROM cancel_policies WHERE partner_id = 1 AND is_default = TRUE);
-- Note: If previous inserts failed, this will now succeed. 
-- If legacy columns still require values despite ALTER, we might need to be explicit, but DROP NOT NULL is preferred.

-- Insert rules for that policy
-- Rule 1: > 48 hours => 90% refund
INSERT INTO cancel_policy_rules (cancel_policy_id, min_hours_before_departure, refund_percentage)
SELECT cancel_policy_id, 48, 90
FROM cancel_policies WHERE partner_id = 1 AND is_default = TRUE
LIMIT 1;

-- Rule 2: 24-48 hours => 50% refund
INSERT INTO cancel_policy_rules (cancel_policy_id, min_hours_before_departure, max_hours_before_departure, refund_percentage)
SELECT cancel_policy_id, 24, 48, 50
FROM cancel_policies WHERE partner_id = 1 AND is_default = TRUE
LIMIT 1;

-- Rule 3: < 24 hours => 0% refund
INSERT INTO cancel_policy_rules (cancel_policy_id, min_hours_before_departure, max_hours_before_departure, refund_percentage)
SELECT cancel_policy_id, 0, 24, 0
FROM cancel_policies WHERE partner_id = 1 AND is_default = TRUE
LIMIT 1;
