<?php
// public/update_profile.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $con = getConnection();

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    $userId          = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $phone           = trim($data['phone'] ?? '');
    $currentPassword = trim($data['current_password'] ?? '');
    $newPassword     = trim($data['new_password'] ?? '');

    if ($userId <= 0) {
        echo json_encode(["success" => false, "error" => "معرّف المستخدم غير صالح"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اجلب المستخدم الحالي
    $stmt = $con->prepare("SELECT user_id, phone_number, password_hash FROM users WHERE user_id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["success" => false, "error" => "المستخدم غير موجود"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fieldsToUpdate = [];
    $params = [':id' => $userId];

    // تحديث رقم الهاتف إن تم إرساله
    if ($phone !== '') {
        $fieldsToUpdate[] = "phone_number = :phone";
        $params[':phone'] = $phone;
    }

    // تحديث كلمة المرور إن تم إرسالها
    if ($newPassword !== '') {
        // تحقق من كلمة المرور الحالية
        if ($currentPassword === '') {
            echo json_encode(["success" => false, "error" => "يجب إدخال كلمة المرور الحالية"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            echo json_encode(["success" => false, "error" => "كلمة المرور الحالية غير صحيحة"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $fieldsToUpdate[] = "password_hash = :pass";
        $params[':pass'] = $newHash;
    }

    if (empty($fieldsToUpdate)) {
        echo json_encode(["success" => false, "error" => "لا توجد بيانات لتحديثها"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $setClause = implode(", ", $fieldsToUpdate);
    $up = $con->prepare("UPDATE users SET $setClause, updated_at = NOW() WHERE user_id = :id");
    $up->execute($params);

    echo json_encode([
        "success"    => true,
        "message"    => "تم تحديث البيانات بنجاح",
        "user_phone" => $phone !== '' ? $phone : $user['phone_number'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => "خطأ في السيرفر",
    ], JSON_UNESCAPED_UNICODE);
}
