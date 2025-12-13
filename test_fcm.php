<?php
require 'fcm_v1_manual.php';

$testToken = 'ضع_هنا_توكن_جهازك';

try {
    $res = sendFcmV1ToTokenManual(
        $testToken,
        'اختبار HTTP v1',
        'هذه رسالة تجريبية من PHP',
        ['type' => 'test']
    );

    echo '<pre>';
    print_r($res);
    echo '</pre>';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
