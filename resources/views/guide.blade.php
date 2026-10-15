@verbatim
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>מדריך למשתמשת — כפיים</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --primary: #286E9A; --primary-dark: #1C5679; --primary-lighter: #E1EEF4;
  --secondary: #D6335F; --accent: #FDB92D; --accent-light: #FEF0D4;
  --bg: #EFEEE5; --surface: #FFFFFF; --text: #20241F; --muted: #5C6B5F; --border: #DAD7C7;
  --success: #16A34A; --success-bg: #DCFCE7; --warning: #D97706; --warning-bg: #FEF3C7;
  --error: #DC2626; --error-bg: #FEE2E2; --info: #2563EB; --info-bg: #DBEAFE;
  --sidebar: #1F5876;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; scroll-padding-top: 24px; }
html, body { overflow-x: clip; }
body { margin: 0; background: var(--bg); color: var(--text); font-family: 'Heebo', system-ui, sans-serif; font-size: 17px; line-height: 1.75; }
a { color: var(--primary); }
.layout { display: grid; grid-template-columns: 1fr 290px; max-width: 1340px; margin: 0 auto; gap: 40px; padding: 0 24px; }
.toc { grid-column: 2; grid-row: 1; position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 28px 0 40px; }
.toc-inner { background: var(--sidebar); color: #fff; border-radius: 18px; padding: 22px 18px; }
.toc img { display: block; width: 150px; background: #fff; border-radius: 12px; padding: 8px 12px; margin: 0 auto 16px; }
.toc h2 { font-size: 14px; letter-spacing: .02em; color: #BFD7E6; margin: 0 6px 8px; font-weight: 600; }
.toc ol { list-style: none; margin: 0; padding: 0; counter-reset: t; }
.toc li a { display: flex; gap: 10px; color: #fff; text-decoration: none; padding: 6px 10px; border-radius: 9px; font-size: 15px; line-height: 1.4; }
.toc li a::before { counter-increment: t; content: counter(t); min-width: 22px; height: 22px; border-radius: 50%; background: rgba(255,255,255,.12); font-size: 12px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.toc li a:hover, .toc li a.active { background: rgba(255,255,255,.12); }
.toc li a.active::before { background: var(--accent); color: var(--text); }
main { grid-column: 1; grid-row: 1; min-width: 0; padding: 28px 0 80px; }
.hero { background: var(--surface); border: 2px solid var(--primary); border-radius: 22px; padding: 40px 44px; position: relative; overflow: hidden; }
.hero::after { content: ""; position: absolute; left: -70px; top: -70px; width: 220px; height: 220px; border-radius: 50%; border: 34px solid var(--secondary); opacity: .9; }
.hero::before { content: ""; position: absolute; left: 10px; top: 10px; width: 46px; height: 46px; border-radius: 50%; background: var(--primary); z-index: 1; }
.hero .eyebrow { display: inline-block; background: var(--accent-light); color: #8a5a00; font-weight: 600; font-size: 14px; padding: 3px 12px; border-radius: 999px; }
.hero h1 { font-size: clamp(30px, 4.4vw, 44px); line-height: 1.2; color: var(--primary-dark); margin: 14px 0 10px; font-weight: 800; position: relative; z-index: 2; max-width: 75%; }
.hero p { margin: 0; color: var(--muted); max-width: 70%; font-size: 18px; }
.flow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 26px; }
.flow span { background: var(--primary-lighter); color: var(--primary-dark); font-weight: 600; padding: 7px 14px; border-radius: 999px; font-size: 15px; }
.flow i { color: var(--muted); font-style: normal; }
section.chapter { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 36px 44px; margin-top: 28px; }
section.chapter > h2 { display: flex; align-items: center; gap: 14px; font-size: 28px; margin: 0 0 6px; color: var(--primary-dark); line-height: 1.3; }
section.chapter > h2 .num { background: var(--primary); color: #fff; width: 40px; height: 40px; border-radius: 12px; font-size: 18px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.lead-text { color: var(--muted); font-size: 18px; margin: 0 0 18px; }
h3 { font-size: 21px; margin: 30px 0 8px; color: var(--text); }
h4 { font-size: 17px; margin: 20px 0 4px; }
p { margin: 8px 0 12px; }
ul, ol.steps { padding-inline-start: 22px; margin: 8px 0 14px; }
li { margin: 4px 0; }
ol.steps { counter-reset: s; list-style: none; padding: 0; }
ol.steps > li { counter-increment: s; position: relative; padding-inline-start: 42px; margin: 10px 0; }
ol.steps > li::before { content: counter(s); position: absolute; right: 0; top: 2px; width: 28px; height: 28px; border-radius: 50%; background: var(--accent); font-weight: 700; font-size: 14px; display: flex; align-items: center; justify-content: center; }
b.ui { background: var(--primary-lighter); color: var(--primary-dark); font-weight: 600; padding: 1px 8px; border-radius: 6px; white-space: nowrap; }
figure { margin: 20px 0 26px; }
figure img { width: 100%; height: auto; display: block; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 6px 24px rgba(32,36,31,.08); cursor: zoom-in; background: var(--bg); }
figure.tall img { max-height: 760px; object-fit: cover; object-position: top; }
figure.phones { display: flex; gap: 22px; flex-wrap: wrap; justify-content: center; }
figure.phones img { width: 260px; }
figcaption { color: var(--muted); font-size: 14px; margin-top: 8px; text-align: center; }
.note { border-radius: 14px; padding: 14px 18px; margin: 16px 0; border-inline-start: 5px solid; font-size: 16px; }
.note strong:first-child { display: block; margin-bottom: 2px; }
.note.tip { background: var(--success-bg); border-color: var(--success); }
.note.info { background: var(--info-bg); border-color: var(--info); }
.note.warn { background: var(--warning-bg); border-color: var(--warning); }
.note p { margin: 4px 0; }
table { width: 100%; border-collapse: collapse; margin: 12px 0 18px; font-size: 16px; }
th, td { text-align: right; padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: top; }
th { background: var(--bg); font-weight: 600; font-size: 14px; color: var(--muted); }
.table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; }
.table-wrap table { margin: 0; }
.dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; vertical-align: middle; margin-inline-end: 6px; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.card { border: 1px solid var(--border); border-radius: 14px; padding: 14px 18px; background: #FCFBF7; }
.card h4 { margin-top: 0; color: var(--primary-dark); }
.card p { margin: 0; font-size: 15.5px; }
.hero p.demo-note { margin-top: 22px; font-size: 14px; color: var(--muted); }
footer { text-align: center; color: var(--muted); font-size: 14px; margin-top: 40px; }
.mobile-toc-btn { display: none; }
#lightbox { position: fixed; inset: 0; background: rgba(15,20,18,.88); display: none; align-items: flex-start; justify-content: center; z-index: 100; overflow: auto; padding: 30px 16px; cursor: zoom-out; }
#lightbox.open { display: flex; }
#lightbox img { max-width: min(1440px, 100%); height: auto; border-radius: 10px; }
#lightbox button { position: fixed; top: 14px; left: 14px; background: #fff; border: 0; border-radius: 50%; width: 40px; height: 40px; font-size: 22px; cursor: pointer; }
.to-top { position: fixed; bottom: 22px; left: 22px; width: 46px; height: 46px; border-radius: 50%; border: 0; background: var(--primary); color: #fff; font-size: 22px; box-shadow: 0 4px 14px rgba(0,0,0,.2); cursor: pointer; opacity: 0; pointer-events: none; transition: opacity .2s; }
.to-top.show { opacity: 1; pointer-events: auto; }
@media (max-width: 1000px) {
  body { font-size: 16px; }
  .layout { display: block; padding: 0 16px; }
  .toc { position: fixed; inset: 0 auto 0 0; right: 0; left: auto; width: min(320px, 86vw); z-index: 50; padding: 12px; transform: translateX(105%); visibility: hidden; transition: transform .25s, visibility .25s; height: 100vh; }
  .toc.open { transform: none; visibility: visible; }
  .toc-inner { min-height: 100%; }
  .mobile-toc-btn { display: flex; position: sticky; top: 0; z-index: 40; align-items: center; gap: 10px; width: 100%; background: var(--sidebar); color: #fff; border: 0; font: inherit; font-weight: 600; padding: 12px 16px; margin: 0; width: 100%; cursor: pointer; }
  .backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 45; display: none; }
  .backdrop.show { display: block; }
  main { padding-top: 16px; }
  .hero { padding: 28px 22px; }
  .hero h1, .hero p { max-width: 100%; }
  .hero::after, .hero::before { display: none; }
  section.chapter { padding: 24px 18px; }
  section.chapter > h2 { font-size: 23px; }
  .grid2 { grid-template-columns: 1fr; }
  figure.tall img { max-height: 520px; }
  figure.phones img { width: 46%; }
}
@media print {
  .toc, .mobile-toc-btn, .to-top { display: none !important; }
  .layout { display: block; }
  section.chapter { break-inside: auto; border: 0; padding: 10px 0; }
  figure { break-inside: avoid; }
  figure.tall img { max-height: none; }
}
</style>
</head>
<body>
<button class="mobile-toc-btn" type="button" id="tocBtn" aria-controls="toc" aria-expanded="false">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
  תוכן העניינים
</button>
<div class="backdrop" id="backdrop"></div>
<div class="layout">
<main>

<header class="hero">
  <span class="eyebrow">מדריך למשתמשת</span>
  <h1>מערכת כפיים — המדריך המלא</h1>
  <p>כל מה שצריך כדי לעבוד במערכת מהיום הראשון: מליד חדש, דרך לקוחה ועסקה, ועד מסמכים, תשלומים, מנויים, חומרי לימוד ודוחות.</p>
  <div class="flow" aria-label="רצף העבודה במערכת">
    <span>ליד</span><i>←</i><span>לקוחה</span><i>←</i><span>עסקה</span><i>←</i><span>הצעת מחיר · טופס הזמנה · חוזה</span><i>←</i><span>חשבונית</span><i>←</i><span>תשלום וקבלה</span>
  </div>
  <p class="demo-note">כל צילומי המסך במדריך צולמו על נתוני הדגמה (בתי ספר ואנשי קשר בדיוניים), ולא על הנתונים שלך. אפשר ללחוץ על כל צילום כדי להגדיל אותו.</p>
</header>

<!-- 1 -->
<section class="chapter" id="login">
  <h2><span class="num">1</span>כניסה למערכת</h2>
  <p class="lead-text">המערכת זמינה בכתובת <a href="https://new.chanimeir.work">new.chanimeir.work</a> מכל מחשב, טאבלט או טלפון.</p>
  <ol class="steps">
    <li>מזינים את <b class="ui">אימייל</b> ואת ה<b class="ui">סיסמה</b> שקיבלת מבעלת העסק.</li>
    <li>אפשר לסמן <b class="ui">זכרי אותי</b> כדי להישאר מחוברת במחשב הזה.</li>
    <li>לוחצים <b class="ui">כניסה</b>, והמערכת עוברת ל<b>מסך העבודה</b>.</li>
  </ol>
  <figure><img src="/images/guide/01-login.png" alt="מסך ההתחברות" loading="lazy" width="1440" height="900"><figcaption>מסך ההתחברות</figcaption></figure>
  <div class="note info"><strong>אין הרשמה עצמית</strong><p>משתמשות חדשות מוסיפים רק דרך <a href="#users">משתמשות והרשאות</a>. אם שכחת את הסיסמה, בעלת העסק יכולה לאפס אותה משם.</p></div>
  <p>ליציאה מהמערכת לוחצים <b class="ui">יציאה</b> בפינה העליונה של המסך.</p>
</section>

<!-- 2 -->
<section class="chapter" id="layout">
  <h2><span class="num">2</span>מבנה המסך והתפריט</h2>
  <p class="lead-text">התפריט הראשי נמצא בצד ימין ומחולק לשתי קבוצות.</p>
  <div class="grid2">
    <div class="card"><h4>עבודה שוטפת</h4><p>מסך עבודה · לידים · לקוחות · גבייה ותשלומים · רשימות תפוצה · ספקים והוצאות</p></div>
    <div class="card"><h4>ניהול והגדרות</h4><p>קטלוג תוכניות · תבניות מסמכים · הגדרות מערכת · משתמשות והרשאות · יומן פעילות · חיפוש גלובלי</p></div>
  </div>
  <ul>
    <li>בראש כל מסך מוצגים שם המשתמשת המחוברת וההרשאה שלה, ולידם כפתור <b class="ui">יציאה</b>.</li>
    <li>הודעות הצלחה, אזהרה ושגיאה מופיעות כ"בועות" קטנות על המסך. הודעות שגיאה נשארות עד שסוגרים אותן ב־×.</li>
    <li>במסכים ארוכים מופיע בפינה כפתור עגול של <b>חזרה לראש הדף</b>.</li>
  </ul>
  <h3>עבודה מהטלפון</h3>
  <p>המערכת מותאמת למסך קטן. בטלפון התפריט מוסתר, ופותחים אותו בכפתור ☰ שבראש המסך.</p>
  <figure class="phones">
    <img src="/images/guide/20-mobile-home.png" alt="מסך העבודה בטלפון" loading="lazy" width="780" height="1688">
    <img src="/images/guide/20-mobile-menu.png" alt="התפריט פתוח בטלפון" loading="lazy" width="780" height="1688">
    <figcaption style="width:100%">מסך העבודה בטלפון, משמאל עם התפריט פתוח</figcaption>
  </figure>
</section>

<!-- 3 -->
<section class="chapter" id="home">
  <h2><span class="num">3</span>מסך העבודה</h2>
  <p class="lead-text">זה המסך שנפתח אחרי הכניסה. הוא מרכז את מה שדורש טיפול היום, בלי שצריך לחפש.</p>
  <figure><img src="/images/guide/02-home.png" alt="מסך העבודה" loading="lazy" width="1440" height="900"><figcaption>מסך העבודה: כרטיסי סיכום, משימות ופריטי טיפול</figcaption></figure>
  <h3>מה רואים במסך</h3>
  <ul>
    <li><b>כותרת:</b> ברכה, התאריך העברי והלועזי, מספר הפריטים שמחכים לטיפול היום, וכפתור <b class="ui">+ ליד חדש</b> לקליטה מהירה.</li>
    <li><b>כרטיסי סיכום:</b> לידים, ממתינות לשיחה חוזרת, Follow Up להיום, סך פתוח לגבייה וצ'קים להפקדה.</li>
    <li><b>משימות ופריטי טיפול:</b> רשימת Follow Up, תזכורות וגבייה, עם תגית <b>היום</b> או תאריך יעד. לחיצה על פריט פותחת את הכרטיס הרלוונטי.</li>
    <li><b>מי רכשה ומי התעניינה:</b> בתי הספר שרכשו את התוכנית האחרונה שנוספה לקטלוג או התעניינו בה. שימושי לשיחות מכירה ממוקדות.</li>
  </ul>
  <div class="note tip"><strong>צ'קים לפירעון</strong><p>כשמגיע מועד הפירעון של צ'ק, תופיע בראש המסך התראה <b>"צ'קים לפירעון — להפיק קבלה?"</b>. אם הכסף נכנס לחשבון, לוחצים <b class="ui">נפרע — הפקת קבלה</b> והקבלה תופק ותישלח ללקוחה. אם עדיין לא, לוחצים <b class="ui">עדיין לא — להזכיר מחר</b>.</p></div>
</section>

<!-- 4 -->
<section class="chapter" id="leads">
  <h2><span class="num">4</span>לידים</h2>
  <p class="lead-text">כל בית ספר שמתעניין נקלט כליד, ונשאר בכרטיס אחד לאורך כל תהליך המכירה. פנייה חוזרת מאותו בית ספר מתועדת באותו כרטיס, ולא נפתח כרטיס חדש.</p>
  <h3>רשימת הלידים</h3>
  <figure><img src="/images/guide/03-leads.png" alt="רשימת הלידים" loading="lazy" width="1440" height="900"><figcaption>רשימת הלידים עם הסינונים</figcaption></figure>
  <ul>
    <li><b>סינון:</b> לפי בית ספר, עיר, מקור פנייה, סטטוס ותוכנית או מארז מבוקש. אפשר לשלב כמה סינונים יחד.</li>
    <li><b>עמודות:</b> בית ספר, איש קשר ראשי, תוכניות מבוקשות, סטטוס, מטפלת, מועד ה־Follow Up הבא ועדכון אחרון.</li>
    <li>לידים שמתעניינים ב<b>מנוי שנתי</b> מסומנים במסגרת מיוחדת.</li>
    <li>לחיצה על שורה פותחת את כרטיס הליד.</li>
  </ul>
  <h3>קליטת ליד חדש</h3>
  <ol class="steps">
    <li>לוחצים <b class="ui">+ ליד חדש</b> (ברשימת הלידים או במסך העבודה).</li>
    <li>ממלאים דוא"ל וטלפון (חובה). שם בית הספר וטלפון בית הספר אפשר למלא כבר עכשיו או מאוחר יותר.</li>
    <li>בוחרים <b class="ui">מקור פנייה</b> ומוסיפים הערות לפי הצורך.</li>
    <li>לוחצים <b class="ui">+ ליד חדש</b> בתחתית החלון. הליד נוצר עם הסטטוס "חדש", ומשויך אלייך כמטפלת.</li>
  </ol>
  <figure><img src="/images/guide/03b-new-lead.png" alt="חלון ליד חדש" loading="lazy" width="1440" height="900"><figcaption>חלון קליטת ליד חדש</figcaption></figure>
  <div class="note info"><strong>לידים מדף הנחיתה</strong><p>כשהחיבור לדף הנחיתה מופעל (ב<a href="#settings">הגדרות מערכת</a> ← אינטגרציות), פניות מהאתר נכנסות כלידים חדשים בלי הקלדה ידנית, ומצורפות לרשימת התפוצה הראשית.</p></div>

  <h3>כרטיס הליד</h3>
  <figure class="tall"><img src="/images/guide/04-lead-detail.png" alt="כרטיס ליד" loading="lazy" width="1440" height="1722"><figcaption>כרטיס ליד: פרטי בית הספר, אנשי קשר, היסטוריה וסטטוס</figcaption></figure>
  <p>הכרטיס מחולק לאזורים. כל אזור נשמר בכפתור השמירה שלו.</p>
  <div class="table-wrap"><table>
    <tr><th>אזור</th><th>מה עושים בו</th></tr>
    <tr><td><b>פרטי בית ספר</b></td><td>שם המוסד, עיר, טלפון, דוא"ל וכתובת. לוחצים <b class="ui">עריכה</b>, מעדכנים ושומרים.</td></tr>
    <tr><td><b>פרטי הפנייה</b></td><td>מקור הפנייה והערות כלליות.</td></tr>
    <tr><td><b>מטפלת בליד</b></td><td>איזו משתמשת אחראית על הליד. משנים ולוחצים <b class="ui">עדכון שיוך</b>.</td></tr>
    <tr><td><b>תוכניות מבוקשות</b></td><td>התוכניות או המארזים שבית הספר מתעניין בהם. בוחרים מהרשימה ולוחצים <b class="ui">הוספה</b>, ומסירים ב־×.</td></tr>
    <tr><td><b>סטטוס ליד (רמזור)</b></td><td>הסטטוס הנוכחי. כשהסטטוס צהוב אפשר להוסיף גם תת־סטטוס, למשל "ממתינה לשיחה חוזרת".</td></tr>
    <tr><td><b>אנשי קשר</b></td><td>רכזת, מנהלת, גורם חשבונאי וכו'. אפשר לסמן איש קשר כ<b>ראשי</b> או כ<b>גורם חשבונאי</b>.</td></tr>
    <tr><td><b>היסטוריית פעילות ו־Follow Up</b></td><td>ציר זמן של כל שיחה ופעולה מול בית הספר.</td></tr>
    <tr><td><b>תזכורות אישיות</b></td><td>משימות עם מועד יעד, שמופיעות גם במסך העבודה.</td></tr>
  </table></div>

  <h4>הרמזור — משמעות הצבעים</h4>
  <ul>
    <li><span class="dot" style="background:#D97706"></span><b>צהוב</b>: חדש או בתהליך מכירה. אפשר להוסיף תת־סטטוס.</li>
    <li><span class="dot" style="background:#16A34A"></span><b>ירוק</b>: מוכנה לסגור או רוצה לרכוש.</li>
    <li><span class="dot" style="background:#DC2626"></span><b>אדום</b>: נסגר ללא מכירה. הליד וההיסטוריה שלו נשמרים.</li>
  </ul>

  <h4>תיעוד שיחה ו־Follow Up</h4>
  <ol class="steps">
    <li>לוחצים <b class="ui">+ תיעוד אינטראקציה</b> כדי לתעד שיחה או פגישה: סוג, סיכום ותוצאה. אפשר לקבוע מיד גם מועד Follow Up הבא.</li>
    <li>או לוחצים <b class="ui">+ Follow Up חדש</b> לתיעוד מעקב, עם סיכום ו<b>מועד Follow Up הבא</b>.</li>
    <li>המועד הבא יופיע ברשימת הלידים ובמסך העבודה ביום שנקבע.</li>
  </ol>
  <div class="note tip"><strong>מחקת בטעות?</strong><p>פריטים שהוסרו (אנשי קשר, תיעודים, תזכורות) מופיעים 30 יום באזור <b>"פריטים שהוסרו לאחרונה"</b> בתחתית הכרטיס, ואפשר לשחזר אותם בכפתור <b class="ui">שחזור</b>.</p></div>

  <h3 id="convert">המרת ליד ללקוחה</h3>
  <p>כשבית הספר מחליט לרכוש, לוחצים בראש כרטיס הליד על <b class="ui">המרה ללקוחה</b>.</p>
  <ul>
    <li>צריך להשלים קודם את פרטי בית הספר (לפחות שם המוסד).</li>
    <li>לא נוצר כרטיס חדש מאפס: כל ההיסטוריה, אנשי הקשר וההערות עוברים לכרטיס הלקוחה.</li>
    <li>אחרי ההמרה מופיע בכרטיס הליד הפס "ליד זה הומר ללקוחה" עם קישור <b class="ui">מעבר לכרטיס הלקוחה</b>.</li>
  </ul>
</section>

<!-- 5 -->
<section class="chapter" id="customers">
  <h2><span class="num">5</span>לקוחות</h2>
  <p class="lead-text">לכל בית ספר יש כרטיס לקוחה אחד לאורך כל תקופת ההתקשרות. סניף של רשת מנוהל ככרטיס נפרד.</p>
  <figure><img src="/images/guide/05-customers.png" alt="רשימת הלקוחות" loading="lazy" width="1440" height="900"><figcaption>רשימת הלקוחות</figcaption></figure>
  <p>אפשר לחפש לפי שם בית ספר ולסנן לפי עיר, תוכנית שנרכשה, מנוי פעיל וסטטוס. לקוחות עם מנוי פעיל מסומנות בתגית <b>מנויה פעילה</b>.</p>

  <h3>כרטיס הלקוחה</h3>
  <p>בראש הכרטיס מופיעות תגיות מצב (פעילה, מנויה פעילה, חוב פתוח), התאריך שממנו היא לקוחה ושם איש הקשר הראשי. הקישור <b class="ui">כרטיס הליד המקורי</b> מחזיר להיסטוריית המכירה. התוכן מחולק ללשוניות:</p>

  <h4>לשונית "בית ספר ואנשי קשר"</h4>
  <figure><img src="/images/guide/06-customer-info.png" alt="כרטיס לקוחה – בית ספר ואנשי קשר" loading="lazy" width="1440" height="900"></figure>
  <ul>
    <li><b>פרטי בית ספר</b>, כולל <b>שם לחשבונית</b> ו<b>ח.פ.</b>, שמשמשים בהפקת חשבוניות.</li>
    <li><b>אנשי קשר</b>: איש הקשר הראשי הוא ברירת המחדל לנמעני מסמכים, הודעות וחומרי לימוד. הגורם החשבונאי משמש לחשבוניות.</li>
    <li><b>פרטים נוספים</b>: הברה, מספר כיתות בשנתון ולוגו בית הספר.</li>
  </ul>

  <h4>לשונית "עסקאות"</h4>
  <figure><img src="/images/guide/06c-customer-deals.png" alt="לשונית עסקאות" loading="lazy" width="1440" height="900"></figure>
  <p>כאן רואים את כל העסקאות של הלקוחה, ופותחים עסקה חדשה. ההסבר המלא נמצא בפרק <a href="#deals">עסקאות</a>.</p>

  <h4>לשונית "מנוי"</h4>
  <p>המנוי השנתי ויומן האספקה שלו. ההסבר המלא נמצא בפרק <a href="#subscription">מנוי שנתי</a>.</p>

  <h4>לשונית "מסמכים ותשלומים"</h4>
  <figure><img src="/images/guide/06d-customer-billing.png" alt="לשונית מסמכים ותשלומים" loading="lazy" width="1440" height="900"></figure>
  <p>תמונת מצב כספית של הלקוחה: יתרת החוב הכוללת, ולכל עסקה הסכום, כמה שולם והיתרה. <b class="ui">פתיחת עסקה</b> מובילה לכרטיס העסקה, ושם מפיקים מסמכים ורושמים תשלומים.</p>

  <h4>לשונית "חומרים"</h4>
  <p>שליחת חומרי לימוד ומעקב אחריהם. ההסבר המלא נמצא בפרק <a href="#materials">חומרי לימוד</a>.</p>

  <h4>לשונית "היסטוריית פעילות"</h4>
  <p>ציר זמן מלא של כל מה שקרה מול הלקוחה, כולל ההיסטוריה משלב הליד.</p>
  <div class="note warn"><strong>התראת חריגת מחיר</strong><p>אם הלקוחה רכשה תוכניות בודדות בסכום כולל שגבוה ממחיר מנוי שנתי, תופיע בכרטיס התראה. זו הזדמנות להציע לה מנוי.</p></div>
</section>

<!-- 6 -->
<section class="chapter" id="deals">
  <h2><span class="num">6</span>עסקאות</h2>
  <p class="lead-text">כל רכישה היא עסקה נפרדת, של תוכנית אחת או מארז אחד. כשלקוחה רוכשת כמה תוכניות, פותחים עסקה לכל אחת.</p>
  <h3>פתיחת עסקה חדשה</h3>
  <ol class="steps">
    <li>בכרטיס הלקוחה עוברים ללשונית <b class="ui">עסקאות</b> ולוחצים <b class="ui">+ עסקה חדשה</b>.</li>
    <li>בוחרים <b>תוכנית או מארז</b>.</li>
    <li><b>סכום מוסכם</b> הוא אופציונלי. אם משאירים אותו ריק, נלקח מחיר המחירון.</li>
    <li>אפשר לבחור <b>אמצעי תשלום</b> מתוכנן, <b>מספר תלמידות</b> ו<b>בקשת התאמה מיוחדת</b>.</li>
    <li>לוחצים <b class="ui">יצירת עסקה</b>.</li>
  </ol>
  <div class="note info"><strong>מחיר נעול</strong><p>שם התוכנית ומחירה נשמרים בעסקה ברגע המכירה. שינוי מחיר בקטלוג בהמשך לא משנה עסקאות קיימות.</p></div>

  <h3>כרטיס העסקה</h3>
  <figure class="tall"><img src="/images/guide/07-deal.png" alt="כרטיס עסקה" loading="lazy" width="1440" height="2117"><figcaption>כרטיס עסקה: פרטים, סטטוס, מסמכים ותשלומים</figcaption></figure>
  <ul>
    <li><b>פרטי המוצר בעת המכירה:</b> התוכנית או המארז והמחיר כפי שנמכרו.</li>
    <li><b>פרטי עסקה:</b> סכום מוסכם, אמצעי תשלום מתוכנן, מספר תלמידות והערות. שומרים ב<b class="ui">שמירת פרטי עסקה</b>.</li>
    <li><b>סטטוס עסקה:</b> פתוחה, שולמה, מבוטלת וכו'. משנים ולוחצים <b class="ui">עדכון סטטוס</b>. עסקה שבוטלה לא נמחקת, והיא נשארת להיסטוריה.</li>
  </ul>

  <h3>מסמכי העסקה — הסדר הנכון</h3>
  <p>בראש אזור <b>מסמכים</b> מוצג "פס התקדמות" של המסמכים. מסמך שכבר הופק מסומן בירוק.</p>
  <div class="flow" style="margin:6px 0 14px">
    <span>הצעת מחיר (לא חובה)</span><i>←</i><span>טופס הזמנה</span><i>←</i><span>חוזה חתום</span><i>←</i><span>חשבונית</span><i>←</i><span>חשבונית זיכוי (במקרה הצורך)</span>
  </div>
  <ul>
    <li>המערכת שומרת על הסדר: חוזה מפיקים רק אחרי קבלת טופס הזמנה, וחשבונית רק אחרי שהחוזה נחתם.</li>
    <li>להפקת מסמך לוחצים <b class="ui">+ הפקת הצעת מחיר</b>, <b class="ui">+ הפקת טופס הזמנה</b> או <b class="ui">+ הפקת חוזה</b>. המסמך נוצר מהתבנית הפעילה ונפתח לעריכה ולשליחה (ראו <a href="#documents">מסמכים ושליחתם</a>).</li>
    <li>לחשבונית בוחרים קודם <b>עוסק</b> (החברה שמפיקה), ואז לוחצים <b class="ui">הפקת חשבונית</b>. החשבונית מופקת ב־SUMIT.</li>
    <li>בטבלת המסמכים רואים לכל מסמך את אופן השליחה (קובץ PDF או טופס מקוון), ומתי נשלח, התקבל ונחתם. מסמך שנחתם מחוץ למערכת מסמנים בכפתור <b class="ui">סמן כנחתם</b>.</li>
    <li><b>חשבונית זיכוי</b> אפשר להפיק פעם אחת לעסקה.</li>
  </ul>

  <h3 id="payments">רישום תשלומים</h3>
  <p>באזור <b>תשלומים</b> מופיעים שלושה כרטיסים: <b>סכום עסקה</b>, <b>שולם</b> ו<b>יתרה לתשלום</b>. כדי לרשום תשלום:</p>
  <ol class="steps">
    <li><b>איך משלמים הפעם?</b> בוחרים אחת מהאפשרויות: אשראי, הוראת קבע, העברה בנקאית או צ'קים.</li>
    <li>ממלאים את הפרטים לפי האמצעי שנבחר (פירוט בטבלה).</li>
    <li>מאשרים, והתשלום נכנס לטבלת <b>תשלומים שהתקבלו</b>.</li>
  </ol>
  <div class="table-wrap"><table>
    <tr><th>אמצעי</th><th>איך זה עובד</th></tr>
    <tr><td><b>אשראי</b></td><td>המערכת יוצרת <b>קישור לתשלום</b> ושולחת אותו לדוא"ל של הלקוחה. היא משלמת בדף המאובטח של SUMIT, והתשלום נרשם כאן אוטומטית. הקבלה נשלחת אליה מ־SUMIT. אם כבר שמור לה כרטיס, אפשר <b class="ui">חיוב הכרטיס השמור</b>. בטבלת "קישורים שנשלחו" אפשר להעתיק קישור, לשלוח אותו שוב או לבטל אותו.</td></tr>
    <tr><td><b>הוראת קבע</b></td><td>מוקמת ב־SUMIT. כל חיוב חודשי נרשם כאן אוטומטית, והקבלה נשלחת ללקוחה. בכרטיס רואים כמה עוד צפוי להיגבות.</td></tr>
    <tr><td><b>העברה בנקאית</b></td><td>רישום ידני של הסכום שהתקבל ותאריך הקבלה, והפקת קבלה.</td></tr>
    <tr><td><b>צ'קים</b></td><td>רושמים צ'ק אחד או כמה: מספר, תאריך פירעון וסכום (בנק, סניף וחשבון לא חובה). הכפתור <b class="ui">+ צ'ק נוסף</b> ממלא אוטומטית את הצ'ק הבא: המספר הבא, חודש אחרי, אותו סכום. כל צ'ק נרשם כ"טרם נפרע", וביום הפירעון מופיעה במסך העבודה תזכורת להפקת קבלה.</td></tr>
  </table></div>
  <div class="note info"><strong>תשלומי SUMIT מתעדכנים לבד</strong><p>תשלומים באשראי ובהוראת קבע נמשכים מ־SUMIT בערך כל 10 דקות. אם לקוחה שילמה עכשיו, ייתכן שיעברו כמה דקות עד שהתשלום יופיע.</p></div>
  <div class="note warn"><strong>קבלה לפני תשלום</strong><p>בתחתית אזור התשלומים יש אפשרות <b class="ui">הפקת קבלה לפני תשלום</b>. היא מיועדת למקרים חריגים בלבד, כשצריך קבלה על מלוא החשבונית לפני שהכסף התקבל. אפשר להפיק קבלה כזו רק פעם אחת לעסקה.</p></div>
  <div class="note warn"><strong>חשש לתשלום כפול</strong><p>אם נרשם יותר מסכום העסקה, המערכת מציגה אזהרה "חשש לתשלום כפול או ביתר". כדאי לבדוק לפני שממשיכים.</p></div>
</section>

<!-- 7 -->
<section class="chapter" id="documents">
  <h2><span class="num">7</span>מסמכים ושליחתם</h2>
  <p class="lead-text">הצעת מחיר, טופס הזמנה וחוזה נוצרים מתבנית, ונשלחים ללקוחה כטופס דיגיטלי לאישור או כקובץ PDF.</p>
  <figure class="tall"><img src="/images/guide/08-document-2.png" alt="מסך מסמך" loading="lazy" width="1440" height="1386"><figcaption>מסך מסמך (טופס הזמנה): תוכן, שדות, נמענים וסטטוס תהליך</figcaption></figure>
  <h3>מה יש במסך המסמך</h3>
  <ul>
    <li><b>תוכן המסמך</b>: הטקסט של המסמך, כפי שהלקוחה תראה אותו.</li>
    <li><b>שדות הטופס</b>: שדות "מקושרים" נמלאים אוטומטית מכרטיס הלקוחה והעסקה (שם בית הספר, תוכנית, סכום וכו'). אפשר לתקן ולשמור ב<b class="ui">שמירת שדות</b>. שדה שמסומן ב־* הוא חובה. אחרי שהלקוחה מאשרת, השדות ננעלים.</li>
    <li><b>נמענים</b>: כברירת מחדל, אנשי הקשר הראשיים. אפשר להוסיף נמען לשליחה הזו בלבד (<b class="ui">+ הוספת נמען לשליחה זו</b>), וזה לא משנה את כרטיס הלקוחה.</li>
    <li><b>סטטוס תהליך</b>: מתי המסמך נשלח, נפתח ע"י הלקוחה, התקבל, נחתם ואושר.</li>
    <li><b class="ui">תצוגת הדפסה / PDF</b>: לצפייה, להדפסה או לשמירה כ־PDF.</li>
  </ul>
  <h3>שליחה ללקוחה</h3>
  <div class="grid2">
    <div class="card"><h4>שליחה כטופס דיגיטלי</h4><p>הלקוחה מקבלת מייל עם קישור לטופס מקוון, ממלאת את השדות החסרים ולוחצת <b>אישור וחתימה</b>. המערכת מתעדכנת מיד, ואין צורך בסימון ידני.</p></div>
    <div class="card"><h4>שליחה כ־PDF</h4><p>הלקוחה מקבלת מייל עם המסמך כקובץ מצורף. כשהטופס חוזר חתום, מסמנים במסך המסמך <b class="ui">סימון כטופס שהתקבל</b> או <b class="ui">סימון כחוזה שנחתם</b>.</p></div>
  </div>
  <figure><img src="/images/guide/21-sign-form.png" alt="הטופס הדיגיטלי כפי שהלקוחה רואה" loading="lazy" width="1440" height="900"><figcaption>כך הלקוחה רואה חוזה שנשלח כטופס דיגיטלי</figcaption></figure>
  <div class="note info"><strong>כל המיילים יוצאים דרך Smove</strong><p>מסמכים, חומרי לימוד ודיוורים נשלחים דרך חשבון ה־Smove של העסק. מסמך שנשלח כבר לא נשלח שוב בטעות, גם אם לוחצים פעמיים או מלשונית ישנה.</p></div>
  <h3>חשבוניות</h3>
  <p>במסך חשבונית מופיע <b>פירוט</b> של שורות (תיאור, כמות, מחיר יחידה וסכום), ואפשר להוסיף שורה ב<b class="ui">+ הוספת שורה נוספת</b>. לוחצים <b class="ui">הפקה מול Summit</b>, והחשבונית מופקת רשמית ב־SUMIT ונשלחת ללקוחה.</p>
</section>

<!-- 8 -->
<section class="chapter" id="subscription">
  <h2><span class="num">8</span>מנוי שנתי</h2>
  <p class="lead-text">מנוי שנתי הוא עסקה שנותנת זכאות ל־10 תוכניות חודשיות ולהנחה של 10% על תוכניות פרימיום. הוא נפתח כשמוכרים מארז מסוג "מנוי שנתי".</p>
  <figure class="tall"><img src="/images/guide/06b-customer-subscription.png" alt="לשונית מנוי" loading="lazy" width="1440" height="1254"><figcaption>לשונית המנוי: פרטים ויומן אספקה של 10 התוכניות</figcaption></figure>
  <h3>פרטי המנוי</h3>
  <p>תאריך התחלה, המחיר שסוכם, ההתקדמות (כמה תוכניות מתוך 10 סופקו) וגובה התשלום החודשי. אפשר לערוך את התשלום החודשי בלחיצה על <b class="ui">עריכה</b>.</p>
  <h3>יומן אספקה</h3>
  <p>שורה לכל חודש במנוי, לפי החודשים העבריים. כשתוכנית מסופקת:</p>
  <ol class="steps">
    <li>בשורת החודש בוחרים ברשימה <b class="ui">בחרו תוכנית שסופקה</b>.</li>
    <li>לוחצים <b class="ui">סימון כסופקה</b>. התאריך ושם המשתמשת שסימנה נרשמים אוטומטית.</li>
    <li>סימון שגוי מבטלים ב<b class="ui">ביטול סימון</b>.</li>
  </ol>
  <div class="note info"><strong>שליחת חומרים ≠ אספקה</strong><p>שליחת חומרי לימוד לא מסמנת את התוכנית כסופקה. הסימון ביומן נעשה בנפרד, והוא הבסיס לחישוב ההתקדמות ולקיזוז בביטול.</p></div>
  <p>המנוי מסתיים אוטומטית כשמסומנת התוכנית העשירית, או כשחודש המנוי העשירי נגמר, גם אם לא כל החודשים סומנו (אפשר עדיין לסמן אספקה באיחור). עסקת המנוי נשארת בהיסטוריה של הלקוח, והוא יוצא מרשימת התפוצה של המנויים, אלא אם כבר יש לו מנוי פעיל לשנה הבאה. כדי להמשיך לשנה נוספת פותחים עסקת מנוי חדשה, והיא משויכת אוטומטית לחודשי השנה הבאה.</p>
  <h3>ביטול מנוי</h3>
  <ol class="steps">
    <li>בתחתית לשונית המנוי לוחצים <b class="ui">ביטול מנוי</b>.</li>
    <li>בוחרים <b>ביטול החל מחודש</b>, מזינים <b>סכום הזיכוי</b> ו<b>סיבת הביטול</b>.</li>
    <li>לוחצים <b class="ui">אישור ביטול המנוי</b>. גם העסקה תסומן כמבוטלת, ואי אפשר לבטל את הפעולה הזו.</li>
    <li>אחר כך מפיקים <b class="ui">הפקת חשבונית זיכוי</b> מתוך אותו מסך.</li>
  </ol>
  <p>בביטול, הלקוחה מוסרת מרשימת המנויים ומרשימות התוכניות שעוד לא סופקו. היא נשארת ברשימה הראשית וברשימות של תוכניות שכבר קיבלה.</p>
</section>

<!-- 9 -->
<section class="chapter" id="materials">
  <h2><span class="num">9</span>שליחת חומרי לימוד</h2>
  <p class="lead-text">שולחים מתוך כרטיס הלקוחה ← לשונית <b>חומרים</b>. השליחה עצמה יוצאת דרך Smove.</p>
  <figure><img src="/images/guide/06e-customer-materials.png" alt="שליחת חומרים" loading="lazy" width="1440" height="900"><figcaption>שליחת חומרים והיסטוריית משלוחים</figcaption></figure>
  <ol class="steps">
    <li>בוחרים <b>תוכנית</b> מבין התוכניות שהלקוחה רכשה.</li>
    <li>מצרפים קבצים: PDF, Word, PowerPoint, תמונה, וידאו או שמע, עד 95MB לקובץ. סרטון גדול יותר מוסיפים כקישור (<b class="ui">+ הוספת קישור</b>), למשל ליוטיוב או לדרייב.</li>
    <li>בודקים את ה<b>נמענים</b>. ברירת המחדל היא איש הקשר הראשי, ואפשר להוסיף כתובת נוספת.</li>
    <li>לוחצים <b class="ui">שליחה באמצעות Smove</b>.</li>
  </ol>
  <p>ב<b>היסטוריית משלוחים</b> רואים לכל משלוח את הסטטוס: <b>ממתין לאישור</b>, או <b>התקבל, תודה</b> אחרי שהלקוחה לחצה על כפתור האישור במייל. אם החומרים לא נפתחו אחרי מספר שעות שמוגדר בהגדרות, תופיע תזכורת. אם וידאת את הקבלה בטלפון, אפשר ללחוץ <b class="ui">סימון כטופל</b>.</p>
</section>

<!-- 10 -->
<section class="chapter" id="collections">
  <h2><span class="num">10</span>גבייה ותשלומים</h2>
  <p class="lead-text">מסך מרוכז של כל מה שפתוח לגבייה בכל הלקוחות.</p>
  <figure><img src="/images/guide/09-collections.png" alt="גבייה ותשלומים" loading="lazy" width="1440" height="900"><figcaption>מסך גבייה ותשלומים</figcaption></figure>
  <ul>
    <li><b>סך פתוח לגבייה</b> ו<b>מספר עסקאות ממתינות לתשלום</b>.</li>
    <li><b>עסקאות ממתינות לתשלום</b>: לכל עסקה מוצגים בית הספר, הסכום, כמה שולם, יתרת החוב, אמצעי התשלום, מה נשלח ללקוחה (חשבונית או קישור תשלום) ויעד משימת הגבייה. הכפתור <b class="ui">רישום תשלום</b> מוביל ישר לעסקה.</li>
    <li><b>צ'קים במעקב</b>: כל הצ'קים שעוד לא נפרעו, עם תאריך הפירעון. צ'ק שהגיע מועדו מסומן "הגיע מועד הפירעון".</li>
  </ul>
  <p>התמונה במדריך ריקה כי בנתוני ההדגמה עדיין לא הופקה חשבונית. בעבודה שוטפת כאן יופיעו כל העסקאות הפתוחות.</p>
</section>

<!-- 11 -->
<section class="chapter" id="mailing">
  <h2><span class="num">11</span>רשימות תפוצה</h2>
  <p class="lead-text">המערכת מעדכנת לבד את חברות הרשימות לפי המצב העסקי, וממסך זה אפשר גם לשלוח מייל לרשימות.</p>
  <figure class="tall"><img src="/images/guide/10-mailing-lists.png" alt="רשימות תפוצה" loading="lazy" width="1440" height="1380"><figcaption>רשימות התפוצה ושליחת מייל</figcaption></figure>
  <h3>איך הרשימות מתעדכנות אוטומטית</h3>
  <ul>
    <li><b>ליד חדש</b> מצטרף לרשימה הכללית.</li>
    <li><b>רכישת תוכנית</b> מצרפת את הלקוחה לרשימה של אותה תוכנית.</li>
    <li><b>רכישת מנוי</b> מצרפת לרשימת המנויים ולרשימות התוכניות שבמנוי.</li>
    <li><b>ביטול מנוי</b> מסיר מרשימת המנויים ומהתוכניות שלא סופקו.</li>
    <li>יש גם רשימה נפרדת ל<b>ספקים</b>.</li>
  </ul>
  <h3>שליחת מייל לרשימות</h3>
  <ol class="steps">
    <li>מסמנים רשימה אחת או יותר. כתובת שמופיעה בכמה רשימות תקבל את המייל פעם אחת בלבד.</li>
    <li>כותבים <b>נושא</b>.</li>
    <li>בוחרים אם לכתוב <b>טקסט בתבנית המיילים של המערכת</b> (עם הלוגו והעיצוב), או להעלות <b>קובץ HTML משלי</b>.</li>
    <li>בודקים ב<b class="ui">תצוגה מקדימה</b> או ב<b class="ui">שליחת בדיקה אליי</b>.</li>
    <li>לוחצים <b class="ui">שליחה לרשימות</b>. המייל יופיע בטבלת <b>מיילים שנשלחו</b> עם הסטטוס שלו.</li>
  </ol>
</section>

<!-- 12 -->
<section class="chapter" id="suppliers">
  <h2><span class="num">12</span>ספקים, הוצאות ודוח הכנסות והוצאות</h2>
  <p class="lead-text">שלושה מסכים מקושרים, שעוברים ביניהם בכפתורים שבראש כל מסך.</p>
  <h3>ספקים</h3>
  <figure><img src="/images/guide/11-suppliers.png" alt="ספקים" loading="lazy" width="1440" height="900"></figure>
  <p>מוסיפים ספק עם שם, מספר חברה, סיווג עסקי, טלפון, דוא"ל והערות. כל ספק חדש מצטרף אוטומטית לרשימת התפוצה של הספקים.</p>
  <h3>הוצאות</h3>
  <figure><img src="/images/guide/12-expenses.png" alt="הוצאות" loading="lazy" width="1440" height="900"></figure>
  <ol class="steps">
    <li>ממלאים תאריך, סכום וספק. אפשר גם לשייך את ההוצאה לתוכנית, וזה לא חובה.</li>
    <li>לוחצים <b class="ui">שמירת הוצאה</b>.</li>
    <li>בטבלה רואים לכל הוצאה אם יש לה חשבונית. <b class="ui">צירוף חשבונית</b> מאפשר לצרף אותה מאוחר יותר, והשורה תעבור מ"חסרה חשבונית" ל"יש חשבונית".</li>
  </ol>
  <h3>דוח הכנסות והוצאות</h3>
  <figure class="tall"><img src="/images/guide/13-cashflow.png" alt="דוח הכנסות והוצאות" loading="lazy" width="1440" height="1063"></figure>
  <ul>
    <li><b>בנוי כמו דוח רווח והפסד:</b> כרטיסי סיכום של הכנסות, הוצאות ורווח נקי, ומתחתם טבלה שנפתחת מרוכזת. לחיצה על הכנסות פותחת את התוכניות ומשם את התשלומים, ולחיצה על הוצאות פותחת את הספקים ומשם את ההוצאות. הכנסה ממנוי נפרשת שווה בשווה על פני 10 חודשי המנוי, גם אם שולם מראש. במנוי שבוטל נספרים רק החודשים שלפני הביטול, בניכוי הזיכוי.</li>
    <li><b>סינון:</b> שלוש רשימות בשורה אחת: שנים, חודשים ותוכניות, בכל שילוב. בהתחלה הכול מסומן. לחיצה על "הכל" מסירה את כל הסימונים, כדי לבחור פריט אחד או כמה, ולחיצה נוספת מסמנת שוב הכול. אפשר גם לבטל סימון של פריט בודד.</li>
    <li><b>PDF להדפסה:</b> "PDF מקוצר" מפיק את הסכומים הכוללים בלבד (הכנסות, הוצאות ורווח), ו"PDF מפורט" את כל התוכניות והספקים, עם כל תשלום וכל הוצאה. שניהם מעוצבים עם הלוגו ולפי הסינון שעל המסך.</li>
  </ul>
</section>

<!-- 13 -->
<section class="chapter" id="catalog">
  <h2><span class="num">13</span>קטלוג תוכניות ומארזים</h2>
  <figure><img src="/images/guide/14-programs.png" alt="קטלוג תוכניות" loading="lazy" width="1440" height="955"></figure>
  <h3>תוכניות</h3>
  <p>מוסיפים תוכנית חודשית או פרימיום עם שם, תיאור ומחיר. מסמנים <b>תוכנית פרימיום</b> כשרלוונטי, ולוחצים <b class="ui">יצירת תוכנית</b>.</p>
  <ul>
    <li><b class="ui">עריכה</b>: שינוי שם או מחיר חל רק על מכירות חדשות.</li>
    <li><b class="ui">השבתה</b>: התוכנית לא תופיע במכירות חדשות, אבל תמשיך להופיע בעסקאות קודמות. אפשר להפעיל אותה מחדש בכל עת.</li>
  </ul>
  <h3>מארזים</h3>
  <p>מארז הוא חבילה של כמה תוכניות. יוצרים מארז, ואז בוחרים מארז ותוכנית ולוחצים <b class="ui">הוספת תוכנית למארז</b>. מארז שמסומן <b>מקנה מנוי שנתי</b> פותח מנוי עם יומן אספקה כשמוכרים אותו.</p>
  <div class="note tip"><strong>רשימות התפוצה מתעדכנות לבד</strong><p>רשימת התפוצה של תוכנית נוצרת אוטומטית ברכישה הראשונה שלה, ושינוי שם התוכנית מעדכן גם את שם הרשימה.</p></div>
</section>

<!-- 14 -->
<section class="chapter" id="templates">
  <h2><span class="num">14</span>תבניות מסמכים</h2>
  <p class="lead-text">מכאן קובעים איך ייראו הצעת המחיר, טופס ההזמנה והחוזה.</p>
  <figure class="tall"><img src="/images/guide/15-templates.png" alt="תבניות מסמכים" loading="lazy" width="1440" height="1693"></figure>
  <ol class="steps">
    <li>לוחצים <b class="ui">+ תבנית חדשה</b>, או <b class="ui">עריכה</b> בתבנית קיימת.</li>
    <li>נותנים שם, בוחרים <b>סוג מסמך</b> וכותבים את <b>מלל התבנית</b>. אפשר לבחור גם גודל גופן.</li>
    <li>כדי לשלב מידע אוטומטי מוסיפים שדה מכרטיס הלקוחה או מהעסקה לתוך המלל, למשל שם בית הספר, שם התוכנית או הסכום.</li>
    <li>ב<b class="ui">+ הוספת שדה לתבנית</b> מגדירים שדות שיופיעו בטופס: <b>טקסט חופשי</b> שהלקוחה ממלאת, או <b>מקושר</b> שנמלא אוטומטית. אפשר לסמן שדה כחובה.</li>
    <li>בודקים ב<b class="ui">תצוגה מקדימה</b>.</li>
  </ol>
  <p>הגרסה הפעילה היא זו שמשמשת להפקת מסמכים חדשים. מסמך שכבר הופק נשאר כפי שהיה, גם אם עורכים את התבנית אחר כך.</p>
</section>

<!-- 15 -->
<section class="chapter" id="settings">
  <h2><span class="num">15</span>הגדרות מערכת</h2>
  <p class="lead-text">ההגדרות מחולקות ללשוניות. רוב הרשימות מאפשרות הוספה, עריכה, שינוי סדר והשבתה, ולא מחיקה, כדי לשמור על ההיסטוריה.</p>
  <div class="table-wrap"><table>
    <tr><th>לשונית</th><th>מה מגדירים</th></tr>
    <tr><td><b>סטטוסים</b></td><td>סטטוסים של לידים, עסקאות ועוד. סטטוסי מערכת מסומנים "השם קבוע", ואפשר רק לשנות את הסדר שלהם.</td></tr>
    <tr><td><b>מנויים</b></td><td>חודש תחילת המנוי (חודש עברי).</td></tr>
    <tr><td><b>לידים</b></td><td>מקורות פנייה (דף נחיתה, הפניה, פנייה חוזרת וכו').</td></tr>
    <tr><td><b>אמצעי תשלום</b></td><td>האמצעים שמופיעים בבחירה בעסקה.</td></tr>
    <tr><td><b>עוסקים</b></td><td>החברות או העוסקים שמפיקים חשבוניות: שם, ח.פ./ע.מ, אימייל, טלפון וחיבור ה־SUMIT של כל אחד.</td></tr>
    <tr><td><b>תבניות דואר</b></td><td>מיילים תפעוליים שהמערכת שולחת, עם נושא, תוכן ושדות מיזוג.</td></tr>
    <tr><td><b>אינטגרציות</b></td><td>חיבור ל־Smove, ל־SUMIT ולדף הנחיתה (ראו בהמשך).</td></tr>
  </table></div>
  <figure class="tall"><img src="/images/guide/16a-settings-statuses.png" alt="הגדרות – סטטוסים" loading="lazy" width="1440" height="2870"><figcaption>לשונית הסטטוסים</figcaption></figure>
  <h3>אינטגרציות</h3>
  <figure class="tall"><img src="/images/guide/16g-settings-integrations.png" alt="הגדרות – אינטגרציות" loading="lazy" width="1440" height="1426"></figure>
  <ul>
    <li>בטבלה העליונה מפעילים או משביתים כל חיבור. כשחיבור <b>מושבת</b>, המערכת לא פונה אליו בכלל.</li>
    <li><b>Smove:</b> מפתח API (בתוך המסך יש הסבר איפה מוצאים אותו), מזהה רשימה ומספר השעות לפני תזכורת על חומרים שלא נפתחו.</li>
    <li><b>SUMIT:</b> חיבור נפרד לכל עוסק, עם מספר חברה ומפתח API. הכפתור <b class="ui">בדיקת חיבור</b> בודק שהפרטים נכונים. <b>מצב בדיקה</b> מפיק מסמכים כטיוטה ומבצע חיובי אשראי כבדיקת אישור בלבד, בלי חיוב בפועל. זה שימושי בתקופת ההתנסות.</li>
    <li><b>דף הנחיתה:</b> סוד ה־Webhook, שצריך להזין גם בהגדרות דף הנחיתה עצמו.</li>
  </ul>
  <div class="note warn"><strong>זהירות עם המפתחות</strong><p>מפתחות API הם כמו סיסמה. לא שולחים אותם במייל או בוואטסאפ, ומזינים אותם רק במסך הזה.</p></div>
</section>

<!-- 16 -->
<section class="chapter" id="users">
  <h2><span class="num">16</span>משתמשות והרשאות</h2>
  <figure class="tall"><img src="/images/guide/17-users.png" alt="משתמשות והרשאות" loading="lazy" width="1440" height="1312"></figure>
  <h3>הוספת משתמשת</h3>
  <ol class="steps">
    <li>באזור <b>הוספת משתמשת חדשה</b> ממלאים שם מלא, אימייל מערכת (לכניסה), אימייל אישי ותפקיד.</li>
    <li>לוחצים <b class="ui">הוספת משתמשת</b>.</li>
    <li>המערכת מציגה <b>סיסמה ראשונית</b>. מעבירים אותה למשתמשת בדרך מאובטחת.</li>
  </ol>
  <ul>
    <li><b class="ui">איפוס סיסמה</b> יוצר סיסמה ראשונית חדשה.</li>
    <li>משתמשת שמסומנת כ<b>לא פעילה</b> לא יכולה להתחבר, וכל מה שעשתה נשמר ביומן.</li>
    <li>טבלת <b>תפקידים והרשאות</b> מראה מה כל תפקיד יכול לראות ולערוך. כרגע יש תפקיד "גישה מלאה". בעתיד אפשר להוסיף תפקיד מוגבל, למשל עובדת מכירות עם גישה ללידים בלבד.</li>
  </ul>
</section>

<!-- 17 -->
<section class="chapter" id="activity">
  <h2><span class="num">17</span>יומן פעילות</h2>
  <figure class="tall"><img src="/images/guide/18-activity.png" alt="יומן פעילות" loading="lazy" width="1440" height="1835"></figure>
  <p>כל פעולה משמעותית במערכת נרשמת כאן: מי עשתה, מתי ומה. פעולות שהמערכת ביצעה לבד מסומנות <b>אוטומטי</b>, למשל משיכת תשלום מ־SUMIT או הצטרפות לרשימת תפוצה. אפשר לסנן לפי משתמשת וטווח תאריכים.</p>
  <div class="note tip"><strong>מתי זה שימושי?</strong><p>כשרוצים לדעת מי שינתה סטטוס, מתי נשלח מסמך, או למה פעולה מול Smove או SUMIT נכשלה. הודעת השגיאה המלאה מופיעה בתיאור.</p></div>
</section>

<!-- 18 -->
<section class="chapter" id="search">
  <h2><span class="num">18</span>חיפוש גלובלי</h2>
  <figure><img src="/images/guide/19-search.png" alt="חיפוש גלובלי" loading="lazy" width="1440" height="900"></figure>
  <p>מקלידים שם, טלפון או שם בית ספר, והתוצאות מופיעות תוך כדי הקלדה. החיפוש מוצא גם לידים וגם לקוחות, כך שלא צריך לדעת מראש אם בית הספר עדיין ליד או כבר לקוחה.</p>
</section>

<!-- 19 -->
<section class="chapter" id="faq">
  <h2><span class="num">19</span>שאלות נפוצות וטיפים</h2>
  <h4>אפשר למחוק ליד או לקוחה?</h4>
  <p>לא. כדי לשמור על ההיסטוריה, ליד שלא נסגר מסמנים בסטטוס "נסגר ללא מכירה", ועסקה שבוטלה מסמנים כמבוטלת. כל המידע נשמר.</p>
  <h4>הלקוחה אומרת שלא קיבלה את המייל.</h4>
  <p>בודקים במסך המסמך או בהיסטוריית המשלוחים שהמייל נשלח, ושהכתובת של הנמען נכונה. כדאי לבקש ממנה לבדוק גם בתיקיית הספאם. אפשר לבדוק ביומן הפעילות אם הייתה שגיאה מול Smove.</p>
  <h4>למה אי אפשר להפיק חשבונית?</h4>
  <p>חשבונית מפיקים רק אחרי שהחוזה נחתם, ואחרי שבוחרים עוסק. בודקים בפס ההתקדמות של המסמכים בעסקה איזה שלב חסר.</p>
  <h4>הלקוחה שילמה באשראי, אבל התשלום לא מופיע.</h4>
  <p>התשלומים נמשכים מ־SUMIT בערך כל 10 דקות. אם התשלום לא מופיע גם אחרי חצי שעה, בודקים ביומן הפעילות.</p>
  <h4>שיניתי מחיר בקטלוג. מה קורה לעסקאות קיימות?</h4>
  <p>כלום. עסקאות קיימות שומרות את המחיר שבו נמכרו, והמחיר החדש חל רק על עסקאות חדשות.</p>
  <h4>עשיתי טעות. איך מתקנים?</h4>
  <p>רוב השדות אפשר לערוך בלחיצה על <b class="ui">עריכה</b>. פריטים שהוסרו מכרטיס ליד אפשר לשחזר 30 יום. אחרי הפקה ב־SUMIT, את החשבונית עצמה לא משנים, ומתקנים בעזרת חשבונית זיכוי.</p>
</section>

<footer>מערכת כפיים · מדריך למשתמשת · עודכן באוקטובר 2026</footer>
@endverbatim
@include('partials.site-credit')
@verbatim
</main>

<nav class="toc" id="toc" aria-label="תוכן העניינים">
  <div class="toc-inner">
    <img src="/images/logo-transparent.png" alt="כפיים">
    <h2>תוכן העניינים</h2>
    <ol>
      <li><a href="#login">כניסה למערכת</a></li>
      <li><a href="#layout">מבנה המסך והתפריט</a></li>
      <li><a href="#home">מסך העבודה</a></li>
      <li><a href="#leads">לידים</a></li>
      <li><a href="#customers">לקוחות</a></li>
      <li><a href="#deals">עסקאות ותשלומים</a></li>
      <li><a href="#documents">מסמכים ושליחתם</a></li>
      <li><a href="#subscription">מנוי שנתי</a></li>
      <li><a href="#materials">חומרי לימוד</a></li>
      <li><a href="#collections">גבייה ותשלומים</a></li>
      <li><a href="#mailing">רשימות תפוצה</a></li>
      <li><a href="#suppliers">ספקים, הוצאות ודוחות</a></li>
      <li><a href="#catalog">קטלוג תוכניות</a></li>
      <li><a href="#templates">תבניות מסמכים</a></li>
      <li><a href="#settings">הגדרות מערכת</a></li>
      <li><a href="#users">משתמשות והרשאות</a></li>
      <li><a href="#activity">יומן פעילות</a></li>
      <li><a href="#search">חיפוש גלובלי</a></li>
      <li><a href="#faq">שאלות נפוצות</a></li>
    </ol>
  </div>
</nav>
</div>

<div id="lightbox" role="dialog" aria-modal="true" aria-label="תצוגה מוגדלת"><button type="button" aria-label="סגירה">×</button><img alt=""></div>
<button class="to-top" id="toTop" type="button" aria-label="חזרה לראש הדף">↑</button>

<script>
(function () {
  var lb = document.getElementById('lightbox'), lbImg = lb.querySelector('img');
  document.querySelectorAll('main figure img').forEach(function (img) {
    img.addEventListener('click', function () { lbImg.src = img.src; lbImg.alt = img.alt; lb.classList.add('open'); document.body.style.overflow = 'hidden'; });
  });
  function closeLb() { lb.classList.remove('open'); document.body.style.overflow = ''; }
  lb.addEventListener('click', closeLb);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeLb(); closeToc(); } });

  var toc = document.getElementById('toc'), btn = document.getElementById('tocBtn'), bd = document.getElementById('backdrop');
  function closeToc() { toc.classList.remove('open'); bd.classList.remove('show'); btn.setAttribute('aria-expanded', 'false'); }
  btn.addEventListener('click', function () { var o = toc.classList.toggle('open'); bd.classList.toggle('show', o); btn.setAttribute('aria-expanded', o ? 'true' : 'false'); });
  bd.addEventListener('click', closeToc);
  toc.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', closeToc); });

  var links = {}; toc.querySelectorAll('a').forEach(function (a) { links[a.getAttribute('href').slice(1)] = a; });
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting && links[en.target.id]) {
          Object.values(links).forEach(function (l) { l.classList.remove('active'); });
          links[en.target.id].classList.add('active');
        }
      });
    }, { rootMargin: '-20% 0px -70% 0px' });
    document.querySelectorAll('section.chapter').forEach(function (s) { io.observe(s); });
  }
  var tt = document.getElementById('toTop');
  window.addEventListener('scroll', function () { tt.classList.toggle('show', window.scrollY > 600); }, { passive: true });
  tt.addEventListener('click', function () { window.scrollTo({ top: 0 }); });
})();
</script>
</body>
</html>
@endverbatim
