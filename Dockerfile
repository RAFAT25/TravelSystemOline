FROM php:8.1-apache

# تثبيت امتدادات PHP اللازمة:
# - mysqli و pdo_mysql لدعم قواعد بيانات MySQL (اختياري)
# - pdo_pgsql و pgsql لدعم PostgreSQL
RUN docker-php-ext-install mysqli pdo_mysql pdo_pgsql pgsql

# نسخ جميع ملفات المشروع إلى مجلد الاستضافة في الحاوية
COPY . /var/www/html/

# فتح منفذ 80 لاستقبال طلبات HTTP
EXPOSE 80

# تشغيل Apache في وضع foreground للحفاظ على الحاوية نشطة
CMD ["apache2-foreground"]
