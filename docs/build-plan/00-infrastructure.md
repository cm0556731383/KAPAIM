[<< אינדקס](README.md) | הבא: [01 — ליבת מערכת >>](01-core-users-roles-activity-log.md)

# שלב 0: תשתית בסיס (Infrastructure)

**מטרה:** סביבת עבודה רצה — קונטיינרים, DB, Laravel חשוף, גיבויים, לוגים.

**מה נבנה בפועל:**
- הרצת `setup-stack.sh` על ה-VPS: Docker, docker-compose (app/nginx/postgres/queue/scheduler), `.env`, מיגרציות ריקות, גיבוי יומי ב-cron.
- חיבור aaPanel/Nginx חיצוני + SSL לדומיין.
- שלד Laravel: Livewire + Tailwind מותקנים, layout בסיסי RTL (עברית).

**תלויות:** אין (שלב ראשון).

**הגדרת סיום:** `docker compose ps` מראה את כל 5 השירותים רצים, האתר עולה מעל HTTPS, `php artisan migrate` רץ בהצלחה, גיבוי DB רץ ונשמר ב-`backups/`.

---
[<< אינדקס](README.md) | הבא: [01 — ליבת מערכת >>](01-core-users-roles-activity-log.md)
