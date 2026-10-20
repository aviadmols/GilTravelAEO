# Gil Travel Report

דשבורד דוח שיווק ל-Gil Travel: AI visibility (AEO), SEO, תנועה, לידים והכנסות. Laravel 13, SQLite, פריסה ב-Railway.

| סקשן | מקור | מה צריך |
|---|---|---|
| AI search visibility | HubSpot AEO, מסונכרן ל-DB פעם ביום (05:30) | `HUBSPOT_TOKEN` עם `marketing.aeo.read`, ורשימת מתחרים ב-`AEO_COMPETITORS` |
| SEO | Google Search Console API (ל-HubSpot אין API לנתוני GSC) | `GSC_SITE_URL`, `GOOGLE_SERVICE_ACCOUNT_JSON` |
| Website traffic | HubSpot analytics, חי עם cache של שעה | scope `business-intelligence` |
| Leads & conversions | HubSpot contacts + forms + page analytics | `crm.objects.contacts.read`, `forms`, `business-intelligence` |
| Revenue & ROI | HubSpot deals | `crm.objects.deals.read` |

כל סקשן נכשל לבד: אם חסר מפתח או scope, הסקשן מציג מה חסר ושאר הדוח עובד.

## HubSpot Service Key
Development → Keys → Service Keys → Create. Scopes: `marketing.aeo.read`, `crm.objects.contacts.read`, `crm.objects.deals.read`, `forms`, `business-intelligence`.

## איך מחושבים מדדי ה-AEO
ה-API של HubSpot מחזיר לכל ריצה רק מספרי ציטוטים ואת טקסט התשובה. לכן:
- **Mention rate** = אחוז התשובות שבהן מופיע שם המותג (`AEO_OWN_BRAND`).
- **Share of voice** = האזכורים שלנו מתוך כל אזכורי המותגים במעקב (`AEO_COMPETITORS`).
- **Rank** = מיקום לפי מספר האזכורים בין המותגים במעקב.
- **Sentiment** לא קיים ב-API. אחרי שיש מפתח, `php artisan aeo:probe` מראה אם הוא מגיע בשדה `visibility`.

פורמט: מותגים מופרדים ב-`;`, כינויים ב-`|`. לדוגמה:
`AEO_COMPETITORS="Kesher Tours|Kesher;Other Brand"`

## Search Console
1. ב-Google Cloud: צור Service Account והורד מפתח JSON.
2. ב-Search Console → Settings → Users: הוסף את כתובת ה-Service Account עם הרשאת Restricted.
3. ב-Railway: `GSC_SITE_URL` (למשל `sc-domain:giltravel.com`) ו-`GOOGLE_SERVICE_ACCOUNT_JSON` (תוכן הקובץ, גולמי או base64).

## פיתוח מקומי
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve
php artisan test
```

## Railway
שירות אחד מה-Dockerfile (FrankenPHP), volume ב-`/data` ל-SQLite. בכל עלייה: migrate, סנכרון AEO ברקע, ו-`schedule:work` לסנכרון היומי.

משתנים: `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_TIMEZONE`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/data/database.sqlite`, `LOG_CHANNEL=stderr`, `DASHBOARD_USER`, `DASHBOARD_PASSWORD`, `HUBSPOT_TOKEN`, `AEO_OWN_BRAND`, `AEO_COMPETITORS`, `REPORT_KEY_PAGES`, `GSC_SITE_URL`, `GOOGLE_SERVICE_ACCOUNT_JSON`.

## Looker Studio (אופציונלי)
ה-migration `create_aeo_reporting_views` יוצר views רק על MySQL. על SQLite הוא מדלג.
