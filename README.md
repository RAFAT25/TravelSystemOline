# 🚀 TravelSystemOnline API

نظام حجز السفر - Backend API

## 🛠️ التقنيات المستخدمة

- **PHP 8.1+** مع Apache
- **PostgreSQL** قاعدة بيانات
- **JWT** للمصادقة
- **Firebase Cloud Messaging** للإشعارات
- **Docker** للنشر

## ⚙️ الإعداد

### 1. استنساخ المشروع
```bash
git clone https://github.com/YOUR_USERNAME/TravelSystemOnline.git
cd TravelSystemOnline
```

### 2. إعداد متغيرات البيئة
```bash
cp .env.example .env
# قم بتعديل .env وإضافة القيم الحقيقية
```

### 3. تثبيت التبعيات
```bash
composer install
```

### 4. تشغيل الخادم المحلي
```bash
php -S localhost:8000 -t public/
```

## 🐳 Docker

```bash
docker build -t travel-api .
docker run -p 80:80 --env-file .env travel-api
```

## 📡 نقاط API

| المسار | الطريقة | الوصف |
|--------|---------|-------|
| `/api/login` | POST | تسجيل الدخول |
| `/api/bookings` | POST | إنشاء حجز (يحتاج JWT) |
| `/api/notifications/send-test` | POST | اختبار الإشعارات |

## 🔐 الأمان

> ⚠️ **تحذير**: لا ترفع أبداً الملفات التالية إلى Git:
> - `.env`
> - `secrets/`
> - ملفات Firebase credentials

## 📁 هيكل المشروع

```
├── src/                 # MVC الرئيسي
│   ├── Config/         # إعدادات DB
│   ├── Controllers/    # Controllers
│   ├── Middleware/     # JWT Auth
│   └── Services/       # FCM, Whapi
├── public/             # نقطة الدخول
├── .env.example        # قالب المتغيرات
└── Dockerfile          # تكوين Docker
```

## 👨‍💻 المطور

**RAFAT-SOFT**