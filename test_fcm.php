<?php
require 'fcm_v1_manual.php';

$testToken = 'd4B05-9oQVSAvz_GnRVtYy:APA91bEhYQZ63B85liQcEjDrX_1CJ1smi38BONdFnROJmjByW25pnOg00troDDPyOx4qZOcTvScr3jYC44mmaTOxj2TuehOFWR5HuxR8wqq27skorANZKIM';

try {
    $title = 'اختبار HTTP v1';
    $body  = 'هذه رسالة تجريبية من PHP';

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
