<?php
require 'fcm_v1_manual.php';

// ضع هنا FCM token لجهازك من تطبيق Flutter
$testToken = 'd4B05-9oQVSAvz_GnRVtYy:APA91bEhYQZ63B85liQcEjDrX_1CJ1smi38BONdFnROJmjByW25pnOg00troDDPyOx4qZOcTvScr3jYC44mmaTOxj2TuehOFWR5HuxR8wqq27skorANZKIM';

try {
    $title = 'Test HTTP v1';
    $body  = 'This is a test message from PHP';

    $res = sendFcmV1ToTokenManual(
        $testToken,
        $title,
        $body,
        ['type' => 'test']
    );

    echo '<pre>';
    print_r($res);
    echo '</pre>';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
