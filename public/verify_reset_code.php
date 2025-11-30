<?php
require_once __DIR__ . '/../controllers/AuthController.php';

header('Content-Type: application/json; charset=utf-8');

$input = file_get_contents('php://input');
$data  = json_decode($input, true) ?: [];

$auth   = new AuthController();
$result = $auth->verifyResetCode($data);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
