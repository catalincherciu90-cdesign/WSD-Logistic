# Analiză DepanAuto — Echipa AI

> Analiză multi-perspectivă a proiectului **DepanAuto** (aplicație real-time de depanare auto: client cheamă depanator, tracking GPS, prețuri pe km, push Firebase, PWA).
> Realizată de agenții relevanți din repo-ul `ai-agenti`. Data: 2026-05-29.
> Cod analizat: `extracted/site/depanauto/` (backend PHP + MySQL, frontend vanilla JS + Leaflet, PWA).

---

## 0. Rezumat executiv (pentru CEO — Bogdan)

DepanAuto este un **MVP funcțional și surprinzător de complet** — mult peste ce promite README-ul. Implementate deja: autentificare email/parolă (client + depanator), profile cu date vehicul, panou admin (dashboard, tarife, aprobare depanatori, venituri), istoric curse cu rating, mesagerie, push notifications Firebase, calcul rută reală via OpenRouteService.

**Dar nu e gata de producție din cauza unor probleme CRITICE de securitate** care trebuie rezolvate ÎNAINTE de orice lansare sau campanie de marketing:

| # | Problemă critică | Impact |
|---|------------------|--------|
| 1 | **Secrete expuse** (parolă DB, `firebase-credentials.json`, cheie OpenRouteService) sunt incluse în arhivele `.tar.gz` versionate în Git | Compromitere completă bază de date + abuz Firebase/ORS pe cheltuiala noastră |
| 2 | **CORS `Access-Control-Allow-Origin: *`** | Orice site poate apela API-ul → CSRF, abuz |
| 3 | **Fișiere `test_*.php` publice** care expun token-uri useri, emailuri, mesaje | Scurgere date personale (GDPR) |
| 4 | **Fără rate limiting** | Brute-force pe login/register, spam cereri |

**Recomandare strategică:** 1 sprint de „hardening" tehnic (Lucian + Cosmin + Danbastan) → abia apoi go-to-market (Victoria + Laura + Elena). Detalii pe roluri mai jos.

---

## 1. Tehnic & DevOps

### @lucian — Tech Lead (arhitectură, securitate, deployment)
**Verdict: arhitectură simplă și corectă pentru scop, dar securitate de blocat lansarea.**

- ✅ **Bun:** PDO cu prepared statements peste tot (`PDO::ATTR_EMULATE_PREPARES => false`), singleton de conexiune, password hashing cu `password_hash(PASSWORD_DEFAULT)`, token-uri 256-bit (`bin2hex(random_bytes(32))`), validare coordonate GPS (range -90..90 / -180..180).
- 🔴 **CRITIC — secrete în istoric Git:** parola DB `wEoRlT2ww...` (`config.php:8`), `firebase-credentials.json` (private key complet), cheia ORS (`get_route.php:8`). Sunt în cele două `.tar.gz` deja commit-uite → **trebuie rotite TOATE** (parolă MySQL, service account Firebase, cheie OpenRouteService) și mutate în variabile de mediu / fișier `.env` neversionersionat.
- 🔴 **CRITIC — CORS `*`** (`config.php:33`): de restrâns la `https://wsdlogistics.ro`.
- 🔴 **CRITIC — `test_*.php` publice** (6 fișiere) expun token-uri și date personale: de șters din producție sau blocat cu `.htaccess` / auth admin.
- 🟠 Fără rate limiting → de adăugat throttling per-IP (min. pe login/register/create_request).
- 🟠 Token-uri fără expirare și stocate în clar → de adăugat TTL + (ideal) hashing.
- 🟠 **Schema `database.sql` incompletă:** definește doar `users`, `requests`, `settings`, dar codul folosește `request_messages`, `ratings`, `admin_sessions`, `admins`, `client_profiles`, `depanator_profiles` (create dinamic în PHP cu `CREATE TABLE IF NOT EXISTS`). Risc de drift și greu de reprodus mediul. **De consolidat tot într-un singur `database.sql` versionat.**
- 🟡 Fără logging persistent al erorilor; mesaje de eroare uneori expun detalii DB.

### @cosmin — Full-Stack Web Developer (implementare)
**Verdict: cod curat și consistent, dar are duplicare și cod mort de curățat.**

- 🟠 **Duplicare/orfan:** există un `api/` la rădăcina site-ului (`create_request.php`, `save_fcm_token.php`, `send_notification.php`) cu `require_once 'config.php'` **rupt** (nu există `config.php` acolo) și nereferențiat din frontend → de șters.
- 🟠 **`API_BASE` hardcodat** în 3 locuri (`client/index.html:295`, `depanator/index.html:218`, `sw.js:3`) la `https://wsdlogistics.ro/depanauto/api` → de centralizat într-un singur fișier de config JS.
- 🟡 SPA-uri single-file (588 / 425 linii cu CSS+JS inline) — ok pentru MVP, dar greu de întreținut pe termen lung.
- 🟡 `save_fcm_token.php` există dar nu pare apelat din client → push-ul către client e incomplet (vezi @alex).
- ✅ Helpers consistenți (`jsonResponse`/`jsonError`), coduri HTTP corecte (400/401/404/405/500).

### @danbastan — Senior Platform & AI Engineer (sisteme, scalabilitate)
**Verdict: corect ca MVP, dar polling-ul și lipsa observabilității vor durea la scară.**

- 🟠 **Real-time = polling HTTP la 3s** (`get_status.php`) + `watchPosition` la 5s. OK la zeci de useri; la sute-mii de useri activi simultan, costul DB/CPU crește liniar. Pe termen mediu: SSE sau WebSocket (sau Cloudflare Durable Objects) pentru tracking live.
- 🟠 **Crearea dinamică de tabele la runtime** (`admin.php:26-32`, `save_fcm_token.php` ALTER TABLE) e anti-pattern → migrații versionate.
- 🟠 Fără config bazat pe mediu (dev/staging/prod) → totul hardcodat.
- 🟡 `send_notification.php` implementează corect FCM V1 cu OAuth2 JWT signing — solid.
- 💡 **Oportunitate AI** (opțional, roadmap): dispecerizare inteligentă (match depanator pe baza distanță + rating + capacitate tractare), ETA predictiv. De discutat cu Bogdan ca diferențiator.

### @alex — Mobile App Developer (PWA & push)
**Verdict: PWA decentă, dar push-ul către CLIENT e neimplementat și nu există app nativ.**

- 🟠 **Push asimetric:** depanatorul primește notificări (FCM + service worker), dar **clientul nu înregistrează token FCM** → clientul nu e notificat când depanatorul acceptă/trimite mesaj. De completat înregistrarea FCM și apelul către `save_fcm_token.php` în `client/`.
- 🟠 **Service Worker fără rotație de versiune** (`cache: depanauto-v1`) → useri blocați pe versiuni vechi după update. De adăugat versionare + `skipWaiting`.
- 🟡 Offline inexistent (network-first, fără fallback) — acceptabil pentru o app care depinde de GPS live.
- 💡 **Recomandare:** PWA e suficient acum, dar pentru notificări fiabile + prezență în store, un wrapper (Capacitor) sau React Native ar crește dramatic rata de răspuns a depanatorilor. De prioritizat după hardening.
- ✅ `manifest.json` corect (standalone, icons 192/512, theme color).

---

## 2. Design & UX

### @irina — Senior UX Lead (fluxuri, IA, strategie)
**Verdict: fluxul de bază e clar, dar lipsesc stările de eroare și onboarding-ul de permisiuni.**

- 🟠 **Permisiuni GPS fără onboarding:** app-ul depinde 100% de GPS, dar nu există ecran care să explice DE CE are nevoie de locație înainte de prompt → rată mare de refuz. De adăugat un „pre-permission priming screen".
- 🟠 **Stări goale/eroare subțiri:** ce vede clientul dacă nu există depanatori disponibili? Dacă pică rețeaua? De definit empty states + retry.
- 🟠 **Feedback de așteptare:** între „Cheamă depanator" și „acceptat" — clientul are nevoie de ETA și status vizibil clar (e parțial acolo, de întărit).
- 🟡 Două roluri (client/depanator) cu UX separat — bine. Dar lipsește un journey map end-to-end documentat.
- 💡 Recomand usability test moderat pe 5 șoferi reali + 3 depanatori înainte de lansare.

### @diana — UI/UX Designer (interfață)
**Verdict: UI curat, mobile-first, dar lipsește un design system și branding consistent.**

- ✅ UI modern: badge-uri de status colorate (waiting/active), butoane mari touch-friendly, `100dvh`, fără zoom — bun pentru mobil.
- 🟠 **Culori inconsistente între roluri:** client = albastru `#1d4ed8`, depanator = verde `#059669` — ok ca diferențiere, dar fără tokens/paletă documentată → greu de scalat.
- 🟠 CSS inline în fiecare HTML (sute de linii) → fără reutilizare. De extras într-un mic design system (variabile CSS).
- 🟡 Lipsesc microinteracțiuni de loading dincolo de „Se încarcă...".
- 💡 De aliniat cu @marian pe identitate vizuală (logo, iconografie) înainte de marketing.

### @marian — Visual & Graphic Designer (brand)
**Verdict: există branding funcțional, dar nu o identitate vizuală reală.**

- 🟠 Există iconițe PWA (192/512) și emoji (🔧), dar **fără logo profesional, paletă oficială sau brand guidelines**.
- 💡 De produs: logo DepanAuto, paletă (pornind de la albastru/verde existent), set de iconițe consistent, template-uri pentru social/ads (cu @laura), și un mic press kit (cu @cristina).
- 💡 Splash screen + icoane store dacă se merge pe app nativ (cu @alex).

---

## 3. Strategie & Analytics

### @victoria — CMO & Strategy Director (GTM)
**Verdict: produs cu fit clar pe piață, dar lansarea trebuie secvențiată corect.**

- 💡 **Poziționare:** „Uber pentru depanare auto" — local, rapid, preț transparent pe km. Diferențiator vs. concurența clasică (telefon + tarif opac): **transparență preț + tracking live**.
- 💡 **GTM secvențial:** (1) hardening tehnic & legal → (2) recrutare ofertă (depanatori) într-un singur oraș pilot → (3) cerere (clienți) prin local SEO + Google Ads geo. Marketplace = problema „chicken-and-egg": **întâi densitate de depanatori**, altfel clienții deschid app-ul și nu găsesc pe nimeni.
- 🟠 **Risc strategic:** fără depanatori activi, orice buget de acquisition de clienți e ars. De aliniat cu @emma (onboarding depanatori) și @ioana (model preț atractiv pentru ambele părți).
- 💡 OKR Q1 sugerat: X depanatori activi/oraș pilot, timp mediu de acceptare < N min, NPS > 40.

### @robert — Head of Analytics (date & măsurare)
**Verdict: ZERO analytics implementat — trebuie rezolvat înainte de orice buget de marketing.**

- 🔴 **Nu există tracking** (GA4, GTM, niciun eveniment). Lansăm „orb". De implementat ÎNAINTE de prima campanie.
- 💡 **Evenimente cheie de instrumentat:** `sign_up` (client/depanator), `request_created`, `request_accepted`, `request_completed`, `rating_submitted`, timp până la acceptare, rată anulare.
- 💡 **Funnel critic:** open app → sign up → create request → matched → completed. Drop-off-ul ne spune unde pierdem.
- 💡 KPI nord: **% cereri finalizate cu succes** și **timp mediu de matching**. Acestea, nu vanity metrics, decid sănătatea marketplace-ului.
- 💡 Dashboard în Looker Studio pentru Bogdan, alimentat din tabela `requests` (avem deja `venit total`, `curse finalizate` în admin).

---

## 4. Pricing & Client

### @ioana — Pricing & Quotes Specialist
**Verdict: model de preț simplu și corect ca structură, dar needs business logic.**

- ✅ Model existent (tabela `settings`): `price_per_km = 4.50 RON`, `price_fixed = 10 RON`, `price_min = 25 RON`. Calcul distanță Haversine + rută reală ORS. Structură curată, editabilă din admin („Tarife").
- 🟠 **Lipsește comisionul platformei** — cum câștigă DepanAuto? De definit: % din cursă (ex. 15-20%) sau abonament depanator. Acum pare că tot prețul merge la depanator.
- 🟠 Fără pricing dinamic (surge la cerere mare / noapte / vreme rea) — oportunitate de revenue.
- 💡 Tarife diferențiate pe tip vehicul intervenție (avem deja: dubă, platformă tractare, camion) — tractarea costă mai mult ca un boost la baterie.
- 💡 Transparența prețului ESTE propunerea de valoare → de păstrat afișarea clară înainte de confirmare.

### @emma — Account Director & Client Success
**Verdict: onboarding-ul depanatorilor e cheia; suportul lipsește.**

- 💡 **Depanatorii sunt „clienții" noștri B2B** — fără ei, nu există serviciu. Avem deja flux de **aprobare depanatori** în admin (bun!), dar nu un proces de onboarding (verificare licență, training app, primele curse).
- 🟠 **Fără canal de suport** în app (client sau depanator) — ce fac dacă cursa merge prost? De adăugat minim un contact/help.
- 💡 Retenție depanatori = plată rapidă + curse suficiente. De urmărit cu @robert „depanatori activi azi" (există deja în dashboard).
- 💡 Recenziile/rating-ul (implementat) sunt aur pentru client success și case studies — de folosit.

---

## 5. Growth — SEO, Paid, Content, Social, PR

### @elena — SEO & Content Strategist
**Verdict: oportunitate uriașă de local SEO, complet neexploatată.**

- 🔴 **App-ul e un SPA fără conținut indexabil** — zero SEO on-page acum (fără meta, fără landing public, fără text).
- 💡 **Local SEO e canalul #1** pentru „depanare auto [oraș]", „tractare auto", „pornire baterie" — intenție comercială mare, urgentă.
- 💡 De construit: **landing pages publice pe oraș/serviciu** (depanare Buzău, tractare București etc.), **Google Business Profile**, citations locale, recenzii.
- 💡 Conținut: ghiduri („ce faci când rămâi în pană", „cât costă o tractare") → atrag trafic + convertesc în instalări app.
- ⚠️ Coordonare cu @lucian: landing-urile au nevoie de meta tags, structured data (`LocalBusiness`), Core Web Vitals.

### @laura — Performance Marketing Manager (paid)
**Verdict: canal cu intenție mare, dar de pornit DOAR după ofertă (depanatori) + tracking.**

- 💡 **Google Search e canalul principal** — „depanare auto urgent", „tractare acum" = oameni în criză, gata să convertească. tCPA după ce avem conversion tracking (vezi @robert).
- 🟠 **Nu porni paid fără depanatori activi** în zona țintită — altfel plătim click-uri care nu găsesc serviciu (vezi @victoria).
- 💡 Geo-targeting strict pe orașul pilot. Extensii de apel (call) — mulți vor suna direct.
- 💡 Retargeting pe cei care au instalat dar n-au creat cerere.
- ⚠️ Blocant: fără GA4 + Enhanced Conversions (cu @robert), bidding-ul automat e inutil.

### @george — Google Ads & PPC Specialist
**Verdict: structură de campanie concretă, dependentă de tracking.**

- 💡 **Structură propusă:** Campanie Search „Urgență" (depanare/tractare acum) + Campanie „Servicii" (pornire baterie, schimb roată) + remarketing. Negative keywords agresive (piese auto, service ITP etc.).
- 💡 Ad extensions: call, location, sitelinks (preț transparent!).
- 💡 Landing = pagina pe serviciu de la @elena, nu direct app store, pentru Quality Score.
- ⚠️ Blocant identic: conversion tracking via GTM înainte de buget serios.

### @mihai — Copywriter & Content
**Verdict: copy-ul UI e funcțional și clar, dar lipsește copy-ul de conversie.**

- ✅ UI copy bun și uman: „Depanare auto la tine acasă", „Cheamă depanator", „Cum a fost intervenția?".
- 💡 De scris: **landing pages** (framework PAS — Problem: ai rămas în pană / Agitate: noaptea, pe câmp / Solution: depanator în N minute, preț știut dinainte), texte ads (cu @laura/@george), secvențe email post-instalare.
- 💡 Microcopy de încredere: „Preț afișat înainte de confirmare", „Depanatori verificați" — reduc frica la prima utilizare.

### @cristina — PR Manager
**Verdict: poveste de PR bună („startup auto local"), dar prematur fără tracțiune.**

- 💡 Unghi PR: „prima platformă de depanare cu tracking live și preț transparent din [regiune]". Presă locală + auto.
- 💡 De pregătit press kit (cu @marian) și managementul recenziilor Google de la lansare (reputația = totul într-un serviciu de urgență).
- 🟠 De aliniat un protocol de comunicare de criză: un incident (depanator întârzie / accident) poate viraliza negativ. Plan de răspuns pregătit.
- 💡 PR-ul vine DUPĂ ce avem primele curse de succes ca dovadă.

### @ion — Social Media & Marketing
**Verdict: conținut de awareness + recrutare depanatori, dual-audience.**

- 💡 **Două audiențe pe social:** (1) șoferi (Facebook/Instagram local — awareness, „salvează-ți serile") și (2) depanatori (grupuri auto/tractări — recrutare ofertă).
- 💡 Conținut: video scurte cu o cursă reală (cu @marian), testimoniale, „știați că" auto. CTA clar pe fiecare post.
- 💡 Facebook local groups + marketplace pentru recrutare depanatori în orașul pilot (cost zero, mare impact pe oferta).

---

## 6. Operations & Legal

### @stefan — Senior PM & Operations Lead
**Verdict: proiect avansat ca produs, dar fără proces de delivery/QA documentat.**

- 💡 **Roadmap secvențiat propus (3 sprinturi):**
  1. **Hardening** (Lucian, Cosmin, Danbastan): rotește secrete + .env, CORS, șterge test files, rate limiting, consolidează `database.sql`, curăță `api/` orfan.
  2. **Completare produs** (Alex, Cosmin): push către client, finalizare PDF bon (UI există, backend lipsește), versionare SW, design system (Diana).
  3. **Pre-lansare** (Robert: GA4/GTM; Elena: landing local SEO; Marian/Mihai: brand+copy) → apoi paid (Laura/George).
- 🟠 **Fără QA workflow și „definition of done"** — de instituit testare pe device real (2 telefoane) per release.
- 🟠 Fără mediu de staging — totul pe producție. De creat staging înainte de lansare.
- ⚠️ Dependență critică pe ofertă (depanatori) — risc #1 de delivery al marketplace-ului.

### @ana — COO & Chief of Staff (sinteză)
**Decizie recomandată pentru Bogdan:**

1. **STOP marketing până la hardening.** Secretele expuse și `test_*.php` sunt risc legal+financiar imediat. Prioritate 0.
2. **Pilot pe un singur oraș** (Buzău pare candidatul natural — vezi proiectul DAG Auto). Densitate de depanatori înainte de orice buget de clienți.
3. **Definiește modelul de revenue** (comision platformă) cu @ioana — fără el, nu există business.
4. **Instrumentează datele** (@robert) înainte să cheltuim un leu pe paid.
5. Secvență: **Hardening → Completare produs → Tracking → SEO local + ofertă → Paid + PR.**

### @clara — Copyright, IP & Legal/Compliance
**Verdict: riscuri legale serioase de rezolvat OBLIGATORIU înainte de lansare.**

- 🔴 **GDPR — date personale + locație:** colectăm nume, email, telefon, date vehicul ȘI **geolocație în timp real** (date sensibile). Lipsesc: **Politică de confidențialitate**, **Termeni și condiții**, **consimțământ explicit**, temei legal, politică de retenție, drept la ștergere. Obligatoriu legal în UE/RO.
- 🔴 **Scurgere de date prin `test_*.php`** (token-uri, emailuri, locații expuse public) = potențială breșă GDPR raportabilă. De eliminat urgent.
- 🟠 **Secrete în Git** (credențiale Firebase/DB/ORS) — pe lângă riscul tehnic, e și expunere de date.
- 🟠 **Marketplace = răspundere:** relația cu depanatorii (contractori vs. angajați), asigurare, răspundere în caz de daune în timpul intervenției. De clarificat juridic.
- 🟠 **Marca „DepanAuto"** — de verificat disponibilitate și înregistrat la OSIM înainte de investiție în brand (cu @marian).
- 💡 De adăugat: acord de prelucrare date, cookie/consent banner pe landing-uri, verificare licențe depanatori (legal pentru tractare).

---

## 7. Tabel consolidat — priorități

| Prioritate | Acțiune | Responsabil | Blochează |
|------------|---------|-------------|-----------|
| 🔴 P0 | Rotește TOATE secretele + mută în `.env` | @lucian | Lansare |
| 🔴 P0 | Șterge `test_*.php` din producție | @lucian/@cosmin | Lansare (GDPR) |
| 🔴 P0 | Politică confidențialitate + T&C + consimțământ GDPR | @clara | Lansare |
| 🔴 P0 | Restrânge CORS la domeniul propriu | @lucian | Lansare |
| 🟠 P1 | Rate limiting (login/register/create_request) | @lucian | Lansare |
| 🟠 P1 | Consolidează `database.sql` (toate tabelele) | @cosmin | Mentenanță |
| 🟠 P1 | Definește comisionul platformei (revenue model) | @ioana | Business |
| 🟠 P1 | Implementează GA4 + GTM + evenimente | @robert | Marketing |
| 🟠 P1 | Push notifications către client (FCM token) | @alex | UX/retenție |
| 🟡 P2 | Versionare service worker | @alex | Update-uri |
| 🟡 P2 | Finalizează backend PDF „bon" | @cosmin | Feature |
| 🟡 P2 | Landing pages local SEO pe oraș/serviciu | @elena/@mihai | Acquisition |
| 🟡 P2 | Design system + identitate vizuală | @diana/@marian | Brand |
| 🟢 P3 | Onboarding depanatori într-un oraș pilot | @emma/@ion | Marketplace |
| 🟢 P3 | Campanii Google Ads geo (după P0/P1) | @laura/@george | Growth |

---

*Analiză generată de echipa AI. Notițele individuale ale fiecărui agent sunt în `ai-agenti/shared/memory/<nume>/active-projects.md`.*
