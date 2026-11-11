# Gil Travel SEO AEO Report

דשבורד דוח שיווק ל-Gil Travel: AI visibility (AEO), SEO, תנועה, לידים והכנסות. Laravel 13, SQLite, פריסה ב-Railway.

| סקשן | מקור | מה צריך |
|---|---|---|
| AI search visibility | HubSpot AEO, מסונכרן ל-DB פעם ביום (05:30) | `HUBSPOT_TOKEN` עם `marketing.aeo.read`, ורשימת מתחרים ב-`AEO_COMPETITORS` |
| SEO | Google Search Console API (ל-HubSpot אין API לנתוני GSC) | חיבור Google בדף Settings (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`), או `GSC_SITE_URL` + `GOOGLE_SERVICE_ACCOUNT_JSON` |
| Website traffic | HubSpot analytics, חי עם cache של שעה | scope `business-intelligence` |
| Leads & conversions | HubSpot contacts + forms + page analytics | `crm.objects.contacts.read`, `forms`, `business-intelligence` |
| Revenue & ROI | HubSpot deals | `crm.objects.deals.read` |

כל סקשן נכשל לבד: אם חסר מפתח או scope, הסקשן מציג מה חסר ושאר הדוח עובד.

## HubSpot Service Key
את המפתח מדביקים בדף Settings של הדשבורד. הוא נשמר מוצפן ב-DB וגובר על `HUBSPOT_TOKEN`. אחרי שמירה, סנכרון ה-AEO רץ תוך דקה.

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
שתי דרכים. חשבון שחובר דרך Settings גובר על ה-Service Account.

**חיבור עם Google (מומלץ)** — מי שמנהל את Search Console לוחץ על לינק ומאשר, בלי להוסיף משתמשים.
1. ב-Google Cloud: הפעל את Google Search Console API.
2. Google Auth Platform → Branding: מלא שם ומייל. Audience: External, ולחץ **Publish app** (במצב Testing החיבור נופל אחרי 7 ימים).
3. Clients → Create client → Web application. ב-Authorized redirect URIs הדבק את הכתובת שמופיעה בדף Settings (`https://<הדומיין>/google/callback`).
4. ב-Railway: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`.
5. בדף Settings: Connect with Google, או שלח את "Link for the SEO manager" (תקף 7 ימים, לא פותח את הדוח). אם יש יותר מנכס אחד, בוחרים אותו אחרי האישור.

Google יציג "Google hasn't verified this app" — Advanced → Go to. כדי להעלים את זה צריך verification של Google.

**Service Account**
1. ב-Google Cloud: צור Service Account והורד מפתח JSON.
2. ב-Search Console → Settings → Users: הוסף את כתובת ה-Service Account עם הרשאת Restricted.
3. ב-Railway: `GSC_SITE_URL` (למשל `sc-domain:giltravel.com`) ו-`GOOGLE_SERVICE_ACCOUNT_JSON` (תוכן הקובץ, גולמי או base64).

## נתוני SEO ב-Reports של HubSpot (Custom Object)
ה-Custom Report Builder של HubSpot לא מציע את Search Console כמקור נתונים. `php artisan seo:sync` מעתיק את נתוני GSC ל-Custom Object בשם **SEO metrics**, ואותו כן אפשר לבחור שם. דורש Enterprise באחד ה-Hubs.
1. ב-Service Key מוסיפים את ה-scopes `crm.schemas.custom.read`, `crm.schemas.custom.write`, `crm.objects.custom.read`, `crm.objects.custom.write`.
2. ב-Railway: `HUBSPOT_SEO_SYNC=true`. אופציונלי: `HUBSPOT_SEO_SYNC_ROWS` (כמה שאילתות ודפים מובילים לכל יום, ברירת מחדל 25).
3. Redeploy. בעלייה הסנכרון יוצר את האובייקט וממלא 90 ימים אחורה. אחר כך הוא רץ כל יום ב-06:00 ומעדכן את 5 הימים האחרונים, כי נתוני GSC ממשיכים להשתנות כמה ימים. ידני: `php artisan seo:sync --days=30`.
4. ב-HubSpot: Reports → Create report → Create report on your own → Primary data source: **SEO metrics**.

כל רשומה היא יום אחד: Type = `Total` (סך הכל באתר), `Query` (שאילתה) או `Page` (דף). בדוחות מסננים לפי Type, כדי לא לספור פעמיים. Clicks ו-Impressions מסכמים עם Sum, ו-CTR ו-Average position עם Average.

## הטמעה בדשבורד של HubSpot
בדף Settings → Embed in HubSpot → Create embed link. הלינק (`/embed/<token>`) פותח את הדוח לקריאה בלבד בלי התחברות, ומותר להטמיע אותו רק מ-`*.hubspot.com`.
ב-HubSpot: Reporting → Dashboards → Add content → External content → Other content, ומדביקים את הלינק. דורש Professional ומעלה באחד ה-Hubs.
כל מי שיש לו את הלינק רואה את הדוח. Create a new link מבטל את הקודם.

## פיתוח מקומי
```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve
php artisan test
```

סנכרון AEO ידני (בלי לחכות ל-05:30): `php artisan aeo:sync`. עם `--skip-details` הוא מדלג על פרטי הריצות (ציטוטים וטקסט תשובה) ורץ מהר יותר.

## Railway
שירות אחד מה-Dockerfile (FrankenPHP), volume ב-`/data` ל-SQLite. בכל עלייה: migrate, סנכרון AEO ברקע, ו-`schedule:work` לסנכרון היומי.

משתנים: `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_TIMEZONE`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/data/database.sqlite`, `LOG_CHANNEL=stderr`, `DASHBOARD_USER`, `DASHBOARD_PASSWORD`, `HUBSPOT_TOKEN`, `AEO_OWN_BRAND`, `AEO_COMPETITORS`, `REPORT_KEY_PAGES`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (או `GSC_SITE_URL`, `GOOGLE_SERVICE_ACCOUNT_JSON`).

## Looker Studio (אופציונלי)
ה-migration `create_aeo_reporting_views` יוצר views רק על MySQL. על SQLite הוא מדלג.
