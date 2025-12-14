<?php
/**
 * fcm_v1_manual.php
 * 
 * هذا الملف يوفر دالة لإرسال إشعارات Firebase Cloud Messaging (FCM)
 * باستخدام واجهة برمجة التطبيقات v1، مع المصادقة عبر حساب الخدمة (Service Account).
 * 
 * تم تطبيق حلول المشاكل المتعلقة بتحميل المفتاح الخاص (Private Key)
 * المخزن كمتغير بيئة JSON.
 */

// =============================================================================
// 1. وظيفة تحميل بيانات الاعتماد (مع الحل لمشكلة فواصل الأسطر)
// =============================================================================

/**
 * يقوم بتحميل بيانات اعتماد حساب الخدمة من متغير البيئة.
 * 
 * @return array بيانات الاعتماد مع المفتاح الخاص المعالج.
 * @throws Exception إذا كان متغير البيئة مفقودًا أو JSON غير صالح.
 */
function getServiceAccountCredentials() {
    // اسم متغير البيئة الذي يحتوي على JSON الخاص بحساب الخدمة
    $env_var_name = 'FIREBASE_SERVICE_ACCOUNT_JSON';
    $json_string = getenv($env_var_name);

    if (!$json_string) {
        throw new Exception("متغير البيئة {$env_var_name} غير موجود.");
    }

    $service_account = json_decode($json_string, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("خطأ في فك تشفير JSON: " . json_last_error_msg());
    }

    if (!isset($service_account['private_key'])) {
        throw new Exception("المفتاح الخاص 'private_key' غير موجود في ملف JSON.");
    }

    // الحل الأول: استبدال سلاسل \n النصية بفواصل أسطر حقيقية
    // هذا يضمن أن OpenSSL يمكنه قراءة المفتاح بتنسيق PEM الصحيح
    $privateKey = $service_account['private_key'];
    // التأكد من أن المفتاح يبدأ وينتهي بفاصل سطر حقيقي
    $privateKey = str_replace('\n', "\n", $privateKey);
    // إضافة فاصل سطر في النهاية إذا لم يكن موجودًا لضمان صحة تنسيق PEM
    if (substr($privateKey, -1) !== "\n") {
        $privateKey .= "\n";
    }
    $service_account['private_key'] = $privateKey;

    return $service_account;
}

// =============================================================================
// 2. وظيفة الحصول على رمز الوصول (Access Token)
// =============================================================================

/**
 * يقوم بإنشاء رمز JWT وتبادله مع Google للحصول على رمز الوصول (Access Token).
 * 
 * @return string رمز الوصول.
 * @throws Exception إذا فشلت عملية الحصول على الرمز.
 */
function getAccessToken() {
    $credentials = getServiceAccountCredentials();
    
    $client_email = $credentials['client_email'];
    $private_key_pem  = $credentials['private_key'];
    $token_uri    = $credentials['token_uri'];
    
    // الحل الثاني: تحميل المفتاح الخاص كـ OpenSSL resource
    $private_key_resource = openssl_pkey_get_private($private_key_pem);

    if ($private_key_resource === false) {
        // تشخيص خطأ OpenSSL التفصيلي
        $error_message = openssl_error_string();
        throw new Exception("فشل تحميل المفتاح الخاص كـ OpenSSL resource. تأكد من أن المفتاح بتنسيق PEM صحيح. خطأ OpenSSL: " . $error_message);
    }
    
    $now = time();
    $expires = $now + 3600; // ينتهي الرمز بعد ساعة واحدة (3600 ثانية)
    $scope = 'https://www.googleapis.com/auth/firebase.messaging';

    // 1. إنشاء رأس (Header ) رمز JWT
    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT'
    ];

    // 2. إنشاء حمولة (Payload) رمز JWT
    $payload = [
        'iss'   => $client_email,
        'scope' => $scope,
        'aud'   => $token_uri,
        'iat'   => $now,
        'exp'   => $expires
    ];

    // 3. ترميز الرأس والحمولة
    $base64UrlHeader  = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
    
    $signature_input = $base64UrlHeader . "." . $base64UrlPayload;

    // 4. توقيع رمز JWT باستخدام مورد المفتاح الخاص
    $signature = '';
    if (!openssl_sign($signature_input, $signature, $private_key_resource, 'sha256')) {
        throw new Exception("فشل توقيع JWT باستخدام المفتاح الخاص.");
    }

    // تحرير مورد المفتاح بعد استخدامه
    openssl_free_key($private_key_resource);
    
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    // 5. رمز JWT النهائي
    $jwt = $signature_input . "." . $base64UrlSignature;

    // 6. تبادل رمز JWT برمز الوصول (Access Token)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_uri);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
    ] ));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE );
    curl_close($ch);

    if ($http_code !== 200 ) {
        throw new Exception("فشل الحصول على رمز الوصول. رمز الاستجابة: {$http_code}. الرد: {$response}" );
    }

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
        throw new Exception("رمز الوصول مفقود في الرد: " . $response);
    }

    return $data['access_token'];
}

// =============================================================================
// 3. وظيفة إرسال الإشعار
// =============================================================================

/**
 * يرسل إشعار FCM v1 إلى رمز جهاز محدد.
 * 
 * @param string $token رمز الجهاز المستهدف.
 * @param string $title عنوان الإشعار.
 * @param string $body محتوى الإشعار.
 * @param array $data بيانات مخصصة (اختياري).
 * @return array استجابة API.
 * @throws Exception إذا فشل الإرسال.
 */
function sendFcmV1ToTokenManual($token, $title, $body, $data = []) {
    $credentials = getServiceAccountCredentials();
    $project_id = $credentials['project_id'];
    $access_token = getAccessToken();

    $url = "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send";

    // بناء حمولة الإشعار
    $message = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => $data,
            // يمكنك إضافة حقول أخرى مثل android, apns, webpush هنا
        ]
    ];

    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ];

    $ch = curl_init( );
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE );
    curl_close($ch);

    $result = json_decode($response, true);

    if ($http_code !== 200 ) {
        // في حالة فشل الإرسال، قم بإلقاء استثناء مع تفاصيل الخطأ
        $error_message = isset($result['error']['message']) ? $result['error']['message'] : 'خطأ غير معروف';
        throw new Exception("فشل إرسال إشعار FCM. رمز الاستجابة: {$http_code}. الرسالة: {$error_message}" );
    }

    return $result;
}

// =============================================================================
// 4. مثال على الاستخدام (كما طلب المستخدم)
// =============================================================================

// ضع FCM token لجهازك من Flutter
$testToken = 'd4B05-9oQVSAvz_GnRVtYy:APA91bEhYQZ63B85liQcEjDrX_1CJ1smi38BONdFnROJmjByW25pnOg00troDDPyOx4qZOcTvScr3jYC44mmaTOxj2TuehOFWR5HuxR8wqq27skorANZKIM';

try {
    $title = 'Test HTTP v1 (Fixed)';
    $body  = 'This is a test message from PHP with the private key fix applied.';

    $res = sendFcmV1ToTokenManual(
        $testToken,
        $title,
        $body,
        ['type' => 'test', 'status' => 'success']
    );

    echo '<h1>تم إرسال الإشعار بنجاح!</h1>';
    echo '<pre>';
    print_r($res);
    echo '</pre>';
} catch (Exception $e) {
    echo '<h1>خطأ في الإرسال:</h1>';
    echo 'Error: ' . $e->getMessage();
}
?>
