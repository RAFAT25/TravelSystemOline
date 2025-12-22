-- Add 'Transfer' to payment_method_enum
-- Note: PostgreSQL 12+ supports adding enum values IF NOT EXISTS in some cases, 
-- but generally we check if it exists or use a DO block for safety.

DO $$ 
BEGIN 
    IF NOT EXISTS (SELECT 1 FROM pg_type t 
                  JOIN pg_enum e ON t.oid = e.enumtypid 
                  WHERE t.typname = 'payment_method_enum' AND e.enumlabel = 'Transfer') THEN
        ALTER TYPE payment_method_enum ADD VALUE 'Transfer';
    END IF;
END $$;
