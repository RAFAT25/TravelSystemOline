<?php
/**
 * Application Configuration
 * Uses environment variables for sensitive data
 */

// Brevo Email Configuration
define('BREVO_EMAIL', getenv('BREVO_EMAIL') ?: 'your-email@example.com');
define('BREVO_API_KEY', getenv('BREVO_API_KEY') ?: '');

// Validate required config
if (empty(BREVO_API_KEY)) {
    error_log("Warning: BREVO_API_KEY is not set in environment variables");
}
