# כפיים — מערכת CRM

מערכת לניהול לידים, לקוחות (בתי ספר), עסקאות, מסמכים וחתימה דיגיטלית,
חשבוניות ותשלומים (SUMIT), מנויים, משלוח חומרי לימוד ורשימות תפוצה (Smove),
ספקים, הוצאות ודוחות.

**התקנה על שרת חדש: [DEPLOY.md](DEPLOY.md)** — בקצרה:

```bash
git clone https://github.com/cm0556731383/KAPAIM.git kapaim && cd kapaim
./install.sh owner@example.co.il https://crm.example.co.il
```

## טכנולוגיה

Laravel 13 · PHP 8.5 · Livewire · Tailwind CSS v4 · PostgreSQL 18 · Docker Compose.

## פיתוח

- מסמכי התכנון (מקור האמת להתנהגות המערכת): `docs/prd.md`, `docs/erd.md`,
  `docs/build-plan/`, `docs/brand-guidelines.md`.
- בדיקות: `docker compose exec app php artisan test`
  (רצות על SQLite בזיכרון — לא נוגעות במסד הנתונים האמיתי; לא להריץ אחרי
  `config:cache` בלי `config:clear`).
- נתוני הדגמה לפיתוח: `php artisan db:seed` (DatabaseSeeder — משתמשות עם
  הסיסמה `password`; לא להריץ בשרת אמיתי). התקנה אמיתית משתמשת ב-InstallSeeder.
