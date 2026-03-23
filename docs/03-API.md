# نقاط نهاية API — التوثيق الكامل

جميع نقاط النهاية تستخدم:
- **الطريقة:** POST أو GET (حسب النقطة)
- **نوع الجسم:** `application/json` أو `multipart/form-data`
- **الترميز:** UTF-8
- **المسار الجذر:** `/xml/api/`

---

## 1. تسجيل الحضور

**`POST /api/check-in.php`**

### الطلب

```json
{
  "token": "56f3a733e65444450bc8...",
  "latitude": 24.572307,
  "longitude": 46.602552,
  "accuracy": 12.5
}
```

| الحقل | النوع | مطلوب | الوصف |
|-------|-------|-------|-------|
| `token` | string | ✅ | الرمز الفريد للموظف |
| `latitude` | float | ✅ | خط العرض من GPS |
| `longitude` | float | ✅ | خط الطول من GPS |
| `accuracy` | float | لا | دقة GPS بالمتر |

### الاستجابة — نجاح

```json
{
  "success": true,
  "message": "تم تسجيل الدخول بنجاح",
  "employee_name": "منذر محمود",
  "timestamp": "2026-03-02 08:30:00",
  "distance": 45
}
```

### الاستجابة — فشل (خارج النطاق)

```json
{
  "success": false,
  "message": "أنت خارج نطاق العمل! المسافة: 350 متر (الحد المسموح: 200 متر)",
  "distance": 350
}
```

### التحقق المنفّذ بالترتيب

1. الطريقة POST
2. صحة JSON
3. صحة Token + الموظف مفعّل
4. الوقت ضمن نافذة الوردية النشطة (بداية الوردية − 60 دقيقة حتى نهاية الوردية)
5. الموقع ضمن النطاق الجغرافي
6. عدم التسجيل المكرر (خلال 5 دقائق)

---

## 2. تسجيل الانصراف

**`POST /api/check-out.php`**

### الطلب

```json
{
  "token": "56f3a733e65444450bc8...",
  "latitude": 24.572307,
  "longitude": 46.602552,
  "accuracy": 20.0
}
```

### الاستجابة — نجاح

```json
{
  "success": true,
  "message": "تم تسجيل الانصراف بنجاح",
  "employee_name": "منذر محمود",
  "timestamp": "2026-03-02 16:05:00",
  "distance": 38
}
```

### التحقق المنفّذ

1. صحة Token
2. **وجود تسجيل دخول اليوم** (شرط إلزامي)
3. الموقع ضمن النطاق الجغرافي
4. عدم التسجيل المكرر

---

## 3. تسجيل الدوام الإضافي

**`POST /api/ot.php`**

### الطلب

```json
{
  "token": "56f3a733e65444450bc8...",
  "latitude": 24.572307,
  "longitude": 46.602552,
  "accuracy": 15.0
}
```

### الاستجابة — نجاح

```json
{
  "success": true,
  "message": "تم تسجيل الدوام الإضافي بنجاح"
}
```

### التحقق المنفّذ

1. الدوام الإضافي **مفعّل** في الإعدادات
2. صحة Token
3. الموقع ضمن النطاق الجغرافي
4. **وجود تسجيل انصراف اليوم** (شرط إلزامي)
5. عدم تسجيل دوام إضافي سابق اليوم

---

## 4. التحقق من بصمة الجهاز

**`POST /api/verify-device.php`**

### الطلب

```json
{
  "token": "56f3a733e65444450bc8...",
  "fingerprint": "a3f9c2d1e8b4..."
}
```

| الحقل | الوصف |
|-------|-------|
| `token` | الرمز الفريد للموظف |
| `fingerprint` | SHA-256 لبصمة الجهاز (64 حرف hex) |

### أوضاع ربط الجهاز (`device_bind_mode`)

| القيمة | الوضع | السلوك |
|--------|-------|--------|
| 0 | حر | الرابط يعمل من أي جهاز بدون قيود |
| 1 | صارم | الرابط مربوط بجهاز واحد — أي جهاز آخر يُحظر |
| 2 | مراقبة صامتة | يسمح بالدخول من أي جهاز لكن يُسجّل التلاعب بصمت |

### تتبع الأجهزة (`known_devices`)

عند كل دخول، يتم تسجيل/تحديث الجهاز في جدول `known_devices`:
- إذا كان الجهاز موجوداً: يزيد `usage_count` ويُحدّث `last_used_at`
- إذا كان جديداً: يُنشأ سطر جديد بـ `usage_count = 1`
- **مالك الجهاز:** الموظف صاحب أعلى `usage_count` لنفس البصمة

### الاستجابات المحتملة

**الوضع الحر (`device_bind_mode = 0`) — أول دخول:**
```json
{
  "success": true,
  "first_time": true,
  "auto_bound": false
}
```
> يدخل بدون ربط. لربط الجهاز، يُفعّل المشرف وضع الربط يدوياً.

**أول دخول (الربط مفعّل يدوياً من المشرف):**
```json
{
  "success": true,
  "first_time": true,
  "auto_bound": true
}
```
> تم ربط الجهاز وتحويل `device_bind_mode` إلى الوضع المختار.

**جهاز مطابق (أي وضع):**
```json
{
  "success": true,
  "first_time": false
}
```

**جهاز مختلف — الوضع الصارم (`device_bind_mode = 1`):**
```json
{
  "success": false,
  "locked": true,
  "message": "هذا الرابط مرتبط بجهاز آخر. تواصل مع المشرف لإعادة تعيين الجهاز."
}
```
> يُحظر الدخول ويُعرض شاشة قفل.

**جهاز مختلف — المراقبة الصامتة (`device_bind_mode = 2`):**
```json
{
  "success": true,
  "first_time": false
}
```
> يُسمح بالدخول لكن يُسجّل حالة تلاعب `different_device` في جدول `tampering_cases` مع تفاصيل البصمة وIP ومالك الجهاز.

---

## 5. إرسال جميع الروابط عبر واتساب

**`POST /api/send-all-links.php`**

> يتطلب جلسة مدير نشطة + CSRF Token

### الطلب (FormData)

| الحقل | النوع | مطلوب | الوصف |
|-------|-------|-------|-------|
| `csrf_token` | string | ✅ | رمز CSRF |
| `phone` | string | لا | رقم الهاتف (افتراضي: 966578448146) |

### الاستجابة — نجاح

```json
{
  "success": true,
  "wa_url": "https://wa.me/966578448146?text=...",
  "count": 40,
  "message": "تم تجهيز 40 رابط للإرسال"
}
```

### الوصف

يجمع جميع روابط الموظفين النشطين مرتبة حسب الفرع في رسالة واحدة،
ويُعيد رابط wa.me جاهز للفتح في واتساب.

---

## 6. مصادقة عبر PIN

**`POST /api/auth-pin.php`**

### الطلب

```json
{
  "pin": "1234",
  "fingerprint": "a3f9c2d1e8b4..."
}
```

| الحقل | النوع | مطلوب | الوصف |
|-------|-------|-------|-------|
| `pin` | string | ✅ | الرقم السري (4 أرقام) |
| `fingerprint` | string | لا | بصمة الجهاز |

### الاستجابة — نجاح

```json
{
  "success": true,
  "token": "56f3a733e65...",
  "employee_name": "أحمد",
  "employee_id": 1,
  "pin_changed_at": "2026-01-15 10:00:00"
}
```

> **Rate Limit:** 10 طلب/دقيقة. إذا كان الجهاز مربوطاً بموظف آخر (bind_mode=1) يُعيد توجيه مع `redirected: true`.

---

## 7. مصادقة عبر بصمة الجهاز

**`POST /api/auth-device.php`**

### الطلب

```json
{
  "fingerprint": "a3f9c2d1e8b4..."
}
```

### الاستجابة — نجاح

```json
{
  "success": true,
  "bound": true,
  "token": "56f3a733e65...",
  "employee_name": "أحمد",
  "employee_id": 1
}
```

> **Rate Limit:** 20 طلب/دقيقة. يبحث عن موظف مربوط بالجهاز (`device_bind_mode` = 1 أو 2).

---

## 8. إنهاء الدوام الإضافي

**`POST /api/overtime-end.php`**

### الطلب

```json
{
  "token": "56f3a733e65...",
  "latitude": 24.572307,
  "longitude": 46.602552,
  "accuracy": 15.0
}
```

### الاستجابة — نجاح

```json
{
  "success": true,
  "message": "تم إنهاء الدوام الإضافي",
  "duration_minutes": 90
}
```

> يبحث عن جلسة `overtime-start` مفتوحة ويتحقق من الحد الأدنى للمدة (30 دقيقة افتراضياً).

---

## 9. بدء الدوام الإضافي (بديل)

**`POST /api/overtime.php`**

نفس سلوك `ot.php` — نقطة نهاية بديلة لتسجيل بدء الدوام الإضافي.

---

## 10. إرسال بلاغ سري

**`POST /api/submit-report.php`** (multipart/form-data)

| الحقل | النوع | مطلوب | الوصف |
|-------|-------|-------|-------|
| `token` | string | ✅ | الرمز الفريد للموظف |
| `report_text` | string | لا | نص البلاغ |
| `report_type` | string | لا | النوع: general/violation/harassment/theft/safety/other |
| `images[]` | file[] | لا | صور مرفقة (حتى 5، 10MB لكل صورة) |
| `voice` | file | لا | تسجيل صوتي |
| `voice_effect` | string | لا | مؤثر صوتي |

### الاستجابة — نجاح

```json
{
  "success": true,
  "message": "تم إرسال البلاغ بنجاح"
}
```

> **Rate Limit:** 10 طلب/ساعة. يحفظ الملفات في `uploads/reports/`.

---

## 11. تقارير الأخطاء التقنية (صامت)

**`POST /api/error-report.php`**

```json
{
  "token": "...",
  "error_type": "gps_failed",
  "error_message": "GPS timeout",
  "page": "check-in"
}
```

> **Rate Limit:** 20 طلب/دقيقة. **دائماً يُرجع** `{"ok": true}` — مصمم ليكون صامتاً تماماً حتى عند الخطأ.

---

## 12. إدارة مجموعات الوثائق

**`POST /api/profile-action.php`** (form-data)

> يتطلب جلسة مدير + CSRF Token

| الحقل | الوصف |
|-------|-------|
| `action` | `add_group` / `save_group` / `delete_group` / `delete_file` |
| `employee_id` | معرّف الموظف |
| `group_name` | اسم المجموعة (للإضافة/التعديل) |
| `expiry_date` | تاريخ الانتهاء |

### الاستجابة — نجاح (add_group)

```json
{
  "success": true,
  "csrf_token": "new_token",
  "group": {
    "id": 1,
    "group_name": "الهوية",
    "expiry_date": "2027-01-01",
    "days_left": 365,
    "files": []
  }
}
```

---

## 13. رفع صور/وثائق الموظف

**`POST /api/upload-profile.php`** (multipart/form-data)

> يتطلب جلسة مدير + CSRF Token

| الحقل | الوصف |
|-------|-------|
| `action` | `photo` (صورة بروفايل) / `document` (وثيقة) |
| `employee_id` | معرّف الموظف |
| `file` | الملف (jpg/png/webp، ≤5MB) |
| `group_id` | معرّف المجموعة (للوثائق فقط) |

### الاستجابة — نجاح

```json
{
  "success": true,
  "path": "profiles/1/photo.jpg",
  "csrf_token": "..."
}
```

---

## 14. عرض الملفات بشكل آمن

**`GET /api/serve-file.php`**

| الحقل | الوصف |
|-------|-------|
| `f` | مسار نسبي (يبدأ بـ `profiles/{id}/`) |
| `t` | token الموظف (للتحقق — أو جلسة مدير) |

> حماية ضد path traversal. يتحقق من MIME الحقيقي ويسمح فقط بـ (jpeg/png/webp/gif/pdf).

---

## 15. جلب ملفات مجموعة وثائق

**`GET /api/get-group-files.php`**

> يتطلب جلسة مدير

| الحقل | الوصف |
|-------|-------|
| `group_id` | معرّف المجموعة |

```json
{
  "success": true,
  "files": [
    { "id": 1, "file_path": "...", "file_type": "image", "original_name": "...", "file_size": 1234 }
  ]
}
```

---

## 16. حفظ تفضيلات المستخدم

**`POST /api/preferences.php`**

> يتطلب جلسة مدير

```json
{
  "key": "dark_mode",
  "value": "1"
}
```

> المفاتيح المتاحة: `dark_mode`, `language`, `sidebar_collapsed`, `notifications_enabled`

---

## 17. تصدير تقارير الحضور

**`GET /api/export.php`**

> يتطلب جلسة مدير

| الحقل | الوصف |
|-------|-------|
| `format` | csv / excel / print / json |
| `date_from` | تاريخ البداية |
| `date_to` | تاريخ النهاية |
| `branch_id` | معرّف الفرع (اختياري) |
| `employee_id` | معرّف الموظف (اختياري) |

> يُرجع ملف تحميل أو صفحة طباعة HTML حسب الصيغة المطلوبة.

---

## 18. فحص روابط الموظفين

**`GET /api/check-links.php`**

> يتطلب جلسة مدير

```json
{
  "success": true,
  "results": [
    { "id": 1, "status": "ok", "code": 200, "active": true }
  ],
  "total": 50,
  "ok": 45,
  "errors": 5
}
```

---

## 19. وسيط خرائط (Map Tile Proxy)

**`GET /api/tile.php`**

| الحقل | الوصف |
|-------|-------|
| `z` | مستوى التكبير (zoom) |
| `x`, `y` | إحداثيات tile |
| `l` | الطبقة: `satellite` / `street` |

> بدون مصادقة. يوكّل طلبات tiles من ArcGIS لتجاوز CORS مع cache محلي 30 يوم.

---

## 20. فحص صحة النظام

**`GET /api/health.php`**

> بدون مصادقة

```json
{
  "status": "healthy",
  "checks": { "php": true, "database": true, "disk": true },
  "details": { "php": "ok", "database": "connected", "disk": "writable" }
}
```

> رمز HTTP: 200 (healthy) أو 503 (unhealthy)

---

## 21. سجلات الحضور الفوري

**`GET /api/realtime-attendance.php`**

> يتطلب جلسة مدير

| الحقل | الوصف |
|-------|-------|
| `date_from`, `date_to` | نطاق التاريخ |
| `emp_id` | معرّف الموظف (اختياري) |
| `type` | in/out (اختياري) |
| `branch` | معرّف الفرع (اختياري) |
| `page` | رقم الصفحة (25 لكل صفحة) |

---

## 22. بيانات لوحة التحكم الفوري

**`GET /api/realtime-dashboard.php`**

> يتطلب جلسة مدير. يُرجع إحصائيات اليوم + آخر 15 تسجيل + قائمة الغائبين.

---

## 23. إعادة توليد روابط الموظفين

**`POST /api/regenerate-tokens.php`**

> يتطلب جلسة مدير + CSRF Token

| الحقل | الوصف |
|-------|-------|
| `action` | `all` (جميع الموظفين) / `single` (موظف واحد) |
| `employee_id` | معرّف الموظف (عند `single`) |

---

## 24. جلب بيانات موظف

**`GET /api/get-employee.php`**

| الحقل | الوصف |
|-------|-------|
| `token` | الرمز الفريد للموظف |

> **Rate Limit:** 60 طلب/دقيقة. يُرجع بيانات الموظف + آخر سجل حضور اليوم.

---

## 25. إضافة إجازة

**`POST /api/leave-add.php`** (form-data)

> يتطلب جلسة مدير + CSRF Token. يُعيد التوجيه لصفحة الإجازات.

| الحقل | النوع | الوصف |
|-------|-------|-------|
| `employee_id` | int | معرّف الموظف |
| `leave_type` | string | annual/sick/unpaid/other |
| `start_date` | date | تاريخ البداية |
| `end_date` | date | تاريخ النهاية |
| `reason` | string | سبب الإجازة |

---

## 26. توليد روابط واتساب

**`GET /api/whatsapp.php`**

> يتطلب جلسة مدير

| الحقل | الوصف |
|-------|-------|
| `emp_id` | معرّف الموظف (اختياري — إذا فارغ يجلب الكل) |

---

## رموز HTTP المستخدمة

| الرمز | المعنى | الحالة |
|-------|--------|--------|
| 200 | ناجح | نجاح أو فشل بيزنس لوجيك |
| 400 | بيانات ناقصة / غير صالحة | بيانات الطلب معطوبة |
| 403 | غير مصرح | token خاطئ أو موظف معطّل |
| 405 | طريقة غير مسموحة | ليس POST |
| 500 | خطأ في الخادم | خطأ DB أو PHP |

---

## حساب بصمة الجهاز (JavaScript)

```javascript
async function getFingerprint() {
  const parts = [
    navigator.userAgent,
    screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
    Intl.DateTimeFormat().resolvedOptions().timeZone,
    navigator.language,
    navigator.platform || ''
  ];
  // Canvas fingerprint — بصمة رسومية فريدة
  const c = document.createElement('canvas');
  const x = c.getContext('2d');
  x.font = '14px Arial'; x.fillText('fp', 2, 2);
  parts.push(c.toDataURL().slice(-50));

  const raw = parts.join('|');
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
  return Array.from(new Uint8Array(buf))
    .map(b => b.toString(16).padStart(2, '0')).join('');
}
```
