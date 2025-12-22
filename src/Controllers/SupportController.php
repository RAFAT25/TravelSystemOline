<?php

namespace Travel\Controllers;

use Travel\Helpers\Response;
use Travel\Config\Database;
use PDO;
use Exception;

class SupportController {
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
    }

    public function createTicket() {
        header('Content-Type: application/json; charset=utf-8');
        
        $input = json_decode(file_get_contents('php://input'), true);

        $user_id    = $input['user_id']    ?? null;
        $issue_type = $input['issue_type'] ?? null;
        $title      = $input['title']      ?? null;
        $description= $input['description']?? null;

        if (!$user_id || !$issue_type || !$title || !$description) {
            Response::error("Missing required fields", 400);
            return;
        }

        try {
            $sql = "INSERT INTO support_tickets
                (user_id, issue_type, title, description)
                VALUES (:user_id, :issue_type, :title, :description)
                RETURNING ticket_id, user_id, issue_type, title, description, status, priority, created_at";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':user_id'    => $user_id,
                ':issue_type' => $issue_type,
                ':title'      => $title,
                ':description'=> $description,
            ]);

            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            Response::success(['data' => $ticket], "Ticket created successfully");

        } catch (Exception $e) {
            Response::error('Server error: ' . $e->getMessage(), 500);
        }
    }

    public function getFaqCategories() {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $sql = "
                SELECT category, COUNT(*) AS count
                FROM faqs
                WHERE is_active = TRUE
                GROUP BY category
                ORDER BY category ASC
            ";
            
            $stmt = $this->conn->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::success(['data' => $rows]);

        } catch (Exception $e) {
            Response::error('Server error: ' . $e->getMessage(), 500);
        }
    }
    public function getFaqList() {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $sql = "
            SELECT 
                faq_id    AS id,
                category,
                question,
                answer
            FROM faqs
            WHERE is_active = TRUE
            ORDER BY category ASC, faq_id ASC
        ";

        $stmt = $this->conn->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success(['data' => $rows]);

    } catch (Exception $e) {
        Response::error('Server error: ' . $e->getMessage(), 500);
    }
}

}
