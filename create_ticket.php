<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'message' => 'Method not allowed']);
    exit;
}

// قراءة JSON من Flutter
$input = json_decode(file_get_contents('php://input'), true);

$user_id    = $input['user_id']    ?? null;
$issue_type = $input['issue_type'] ?? null;
$title      = $input['title']      ?? null;
$description= $input['description']?? null;

// تحقق من الحقول المطلوبة
if (!$user_id || !$issue_type || !$title || !$description) {
    http_response_code(400);
    echo json_encode(['status' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $sql = "INSERT INTO support_tickets
        (user_id, issue_type, title, description)
        VALUES (:user_id, :issue_type, :title, :description)
        RETURNING ticket_id, user_id, issue_type, title, description, status, priority, created_at";

    $stmt = $con->prepare($sql);
    $stmt->execute([
        ':user_id'    => $user_id,
        ':issue_type' => $issue_type,
        ':title'      => $title,
        ':description'=> $description,
    ]);

    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'  => true,
        'message' => 'Ticket created successfully',
        'data'    => $ticket,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Server error']);
}
