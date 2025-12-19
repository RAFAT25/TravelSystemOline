<?php

namespace Travel\Helpers;

/**
 * Validator Class - للتحقق من صحة البيانات
 */
class Validator
{
    private static array $errors = [];

    /**
     * التحقق من البريد الإلكتروني
     */
    public static function email(string $email): bool
    {
        if (empty($email)) {
            self::$errors['email'] = 'البريد الإلكتروني مطلوب';
            return false;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::$errors['email'] = 'صيغة البريد الإلكتروني غير صحيحة';
            return false;
        }
        return true;
    }

    /**
     * التحقق من كلمة المرور
     * - الحد الأدنى 8 أحرف
     * - يحتوي على رقم واحد على الأقل
     */
    public static function password(string $password, int $minLength = 8): bool
    {
        if (empty($password)) {
            self::$errors['password'] = 'كلمة المرور مطلوبة';
            return false;
        }
        if (strlen($password) < $minLength) {
            self::$errors['password'] = "كلمة المرور يجب أن تكون $minLength أحرف على الأقل";
            return false;
        }
        if (!preg_match('/[0-9]/', $password)) {
            self::$errors['password'] = 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل';
            return false;
        }
        return true;
    }

    /**
     * التحقق من رقم الهاتف اليمني
     * صيغ مقبولة: +967XXXXXXXXX, 967XXXXXXXXX, 7XXXXXXXX
     */
    public static function phone(string $phone): bool
    {
        if (empty($phone)) {
            self::$errors['phone'] = 'رقم الهاتف مطلوب';
            return false;
        }
        
        // إزالة المسافات
        $phone = preg_replace('/\s+/', '', $phone);
        
        // التحقق من الصيغة اليمنية
        $pattern = '/^(\+?967|0)?7[0-9]{8}$/';
        if (!preg_match($pattern, $phone)) {
            self::$errors['phone'] = 'رقم الهاتف غير صحيح';
            return false;
        }
        return true;
    }

    /**
     * التحقق من أن القيمة ليست فارغة
     */
    public static function required($value, string $fieldName): bool
    {
        if (empty($value) && $value !== '0' && $value !== 0) {
            self::$errors[$fieldName] = "$fieldName مطلوب";
            return false;
        }
        return true;
    }

    /**
     * التحقق من رقم موجب
     */
    public static function positiveInt($value, string $fieldName): bool
    {
        if (!is_numeric($value) || (int)$value <= 0) {
            self::$errors[$fieldName] = "$fieldName يجب أن يكون رقماً موجباً";
            return false;
        }
        return true;
    }

    /**
     * الحصول على الأخطاء
     */
    public static function getErrors(): array
    {
        return self::$errors;
    }

    /**
     * الحصول على أول خطأ
     */
    public static function getFirstError(): ?string
    {
        return !empty(self::$errors) ? reset(self::$errors) : null;
    }

    /**
     * مسح الأخطاء
     */
    public static function clearErrors(): void
    {
        self::$errors = [];
    }

    /**
     * هل يوجد أخطاء؟
     */
    public static function hasErrors(): bool
    {
        return !empty(self::$errors);
    }
}
