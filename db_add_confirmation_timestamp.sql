-- Add confirmation_timestamp to bookings table for the confirm logic
ALTER TABLE bookings 
ADD COLUMN IF NOT EXISTS confirmation_timestamp TIMESTAMP DEFAULT NULL;
