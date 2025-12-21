-- Create booking_approvals table for audit trail (Best Practice Strategy)
CREATE TABLE IF NOT EXISTS booking_approvals (
    approval_id      SERIAL PRIMARY KEY,
    booking_id       INT NOT NULL REFERENCES bookings(booking_id) ON DELETE CASCADE,
    employee_id      INT NOT NULL REFERENCES users(user_id), -- Link to the user who performed the action
    action_type      VARCHAR(50) NOT NULL,  -- e.g. 'CONFIRM', 'CANCEL', 'REFUND'
    old_status       VARCHAR(50),
    new_status       VARCHAR(50),
    notes            TEXT,
    ip_address       VARCHAR(45),           -- IPv4 or IPv6 address for security tracking
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Index for faster searching by booking or date
CREATE INDEX IF NOT EXISTS idx_approvals_booking ON booking_approvals(booking_id);
CREATE INDEX IF NOT EXISTS idx_approvals_employee ON booking_approvals(employee_id);
