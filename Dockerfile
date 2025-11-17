# استخدام صورة رسمية لـ PHP مع Apache (الإصدار 8.1 كمثال)
FROM php:8.1-apache

# نسخ جميع ملفات مشروعك إلى مجلد الويب الافتراضي في الحاوية
COPY . /var/www/html/

# تثبيت امتدادات PHP المتطلبة، مثل الاتصال بقاعدة البيانات MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# فتح منفذ 80 لاستقبال طلبات الـ HTTP
EXPOSE 80

# تشغيل Apache في الوضع الأمامي للحفاظ على الحاوية فعالة
CMD ["apache2-foreground"]
