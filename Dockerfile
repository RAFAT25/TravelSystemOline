FROM php:8.1-apache

# تثبيت مكتبة تطوير PostgreSQL (libpq-dev) اللازمة لتفعيل امتدادات pgsql و pdo_pgsql
RUN apt-get update && apt-get install -y libpq-dev

# تثبيت امتدادات PHP لدعم قواعد البيانات (MySQL - اختياري، PostgreSQL - أساسي هنا)
RUN docker-php-ext-install mysqli pdo_mysql pdo_pgsql pgsql

# نسخ جميع ملفات المشروع إلى مجلد الاستضافة الافتراضي
COPY . /var/www/html/

# فتح منفذ 80 لاستقبال طلبات HTTP
EXPOSE 80

# تشغيل Apache في وضع foreground للحفاظ على الحاوية نشطة
CMD ["apache2-foreground"]
