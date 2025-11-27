<?php
// public/register.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../controllers/AuthController.php';

$input = file_get_contents('php://input');
$data  = json_decode($input, true) ?? [];

$auth = new AuthController();
$result = $auth->register($data);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
