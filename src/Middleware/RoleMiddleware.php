<?php

namespace Travel\Middleware;

class RoleMiddleware {
    
    /**
     * Require specific user roles to access an endpoint
     * 
     * @param array $actor - The decoded JWT token data
     * @param array $allowedRoles - Array of allowed user types (e.g., ['Employee', 'Admin'])
     * @return void - Exits with 403 if unauthorized
     */
    public function requireRole($actor, $allowedRoles) {
        if (!isset($actor['user_type'])) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "error" => "Access denied: User type not found in token"
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        if (!in_array($actor['user_type'], $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "error" => "Access denied: Insufficient permissions. Required roles: " . implode(', ', $allowedRoles)
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    /**
     * Require employee role (Employee or Admin)
     * 
     * @param array $actor - The decoded JWT token data
     * @return void - Exits with 403 if unauthorized
     */
    public function requireEmployee($actor) {
        $this->requireRole($actor, ['Employee', 'Admin']);
    }

    /**
     * Require admin role only
     * 
     * @param array $actor - The decoded JWT token data
     * @return void - Exits with 403 if unauthorized
     */
    public function requireAdmin($actor) {
        $this->requireRole($actor, ['Admin']);
    }

    /**
     * Require customer role
     * 
     * @param array $actor - The decoded JWT token data
     * @return void - Exits with 403 if unauthorized
     */
    public function requireCustomer($actor) {
        $this->requireRole($actor, ['Customer']);
    }
}
