-- Fix column types for cancellation rules to support partial hours (floats)
-- The error suggests these are currently INTEGER, which causes errors when passing values like 8.0675

ALTER TABLE cancel_policy_rules 
ALTER COLUMN min_hours_before_departure TYPE DECIMAL(5,2);

ALTER TABLE cancel_policy_rules 
ALTER COLUMN max_hours_before_departure TYPE DECIMAL(5,2);

-- Also ensure booking_cancellations history table has decimals
ALTER TABLE booking_cancellations
ALTER COLUMN hours_before_departure TYPE DECIMAL(5,2);
