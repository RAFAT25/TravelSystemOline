<?php

namespace Travel\Helpers;

/**
 * Response Helper - لتوحيد استجابات API
 */
class Response
{
    /**
     * استجابة نجاح
     */
    public static function success(array $data = [], string $message = null): void
    {
        $response = ['success' => true];
        
        if ($message) {
            $response['message'] = $message;
        }
        
        $response = array_merge($response, $data);
        
        self::send($response);
    }

    /**
     * استجابة خطأ
     */
    public static function error(string $error, int $httpCode = 400): void
    {
        http_response_code($httpCode);
        self::send([
            'success' => false,
            'error' => $error
        ]);
    }

    /**
     * خطأ التحقق من البيانات
     */
    public static function validationError(array $errors): void
    {
        http_response_code(422);
        self::send([
            'success' => false,
            'error' => reset($errors),
            'errors' => $errors
        ]);
    }

    /**
     * خطأ غير مصرح
     */
    public static function unauthorized(string $message = 'غير مصرح'): void
    {
        http_response_code(401);
        self::send([
            'success' => false,
            'error' => $message
        ]);
    }

    /**
     * غير موجود
     */
    public static function notFound(string $message = 'غير موجود'): void
    {
        http_response_code(404);
        self::send([
            'success' => false,
            'error' => $message
        ]);
    }

    /**
     * إرسال الاستجابة
     */
    private static function send(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
