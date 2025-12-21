-- Create notifications table for user history
CREATE TABLE IF NOT EXISTS notifications (
    notification_id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(user_id),
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    type VARCHAR(50) DEFAULT 'general', -- e.g. booking, system, promo
    related_id INT DEFAULT NULL,        -- e.g. booking_id
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
