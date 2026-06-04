# DepanAuto pe Cloudflare (Workers + D1)

Port complet al backend-ului PHP/MySQL pe **Cloudflare Workers** (API în JavaScript) + **D1** (bază SQLite). Un singur Worker servește atât API-ul (`/depanauto/api/*`) cât și frontend-ul static (binding `ASSETS`).

```
worker/
├── src/index.js        # tot backend-ul (15 endpoint-uri portate din PHP)
├── schema.sql          # schema D1 (SQLite)
├── wrangler.toml       # config Worker + D1 + assets
├── build.sh            # asamblează frontend-ul static în ./public
├── scripts/hash-password.mjs  # generează hash pt. primul admin
└── package.json
```

## Ce e diferit față de varianta PHP
- `password_hash` (bcrypt) → **PBKDF2** via Web Crypto (DB nou, fără migrare de parole).
- Push Firebase (semnare JWT RS256) → reimplementat cu **Web Crypto**.
- MySQL → **D1/SQLite** (ENUM→CHECK, `NOW()`→`datetime('now')`, etc.).
- Rate limiting → tabel `rate_limits` în D1.
- Numele rutelor `*.php` sunt **păstrate**, deci frontend-ul nu se schimbă (doar `API_BASE` devine relativ, automat prin `build.sh`).

## Deploy — pași

Ai nevoie de un cont Cloudflare și de [wrangler](https://developers.cloudflare.com/workers/wrangler/) (`npm i -g wrangler`, apoi `wrangler login`).

```bash
cd worker
npm install

# 1. Creează baza D1 și pune database_id în wrangler.toml
wrangler d1 create depanauto
#   -> copiază "database_id" afișat în wrangler.toml

# 2. Creează tabelele
npm run db:init                 # = wrangler d1 execute depanauto --remote --file=./schema.sql

# 3. Setează secretele
wrangler secret put ORS_KEY                 # cheia OpenRouteService
wrangler secret put FIREBASE_CREDENTIALS    # lipești TOT JSON-ul service account (pt. push)
#   (ambele sunt opționale: fără ORS_KEY ruta cade pe estimare haversine;
#    fără FIREBASE_CREDENTIALS push-ul e dezactivat — restul merge normal)

# 4. (opțional) pune ALLOWED_ORIGIN = domeniul tău în wrangler.toml [vars]

# 5. Build frontend + deploy
npm run deploy                  # rulează build.sh apoi wrangler deploy
```

### Primul admin
```bash
node scripts/hash-password.mjs "parola-aleasa"
# copiază INSERT-ul afișat și rulează-l:
wrangler d1 execute depanauto --remote --command "INSERT INTO admins (...) VALUES (...);"
```

## Deploy automat (GitHub Actions)
Workflow-ul `.github/workflows/deploy-depanauto-worker.yml` deployează la fiecare push pe `main` care atinge `worker/**` sau frontend-ul. Necesită secretul de repo **`CLOUDFLARE_API_TOKEN`** (Workers + D1 edit). Migrarea schemei (pasul 2) rămâne manuală, o singură dată.

## Cheia VAPID (push în browser)
Pentru notificări push trebuie completată și `FCM_VAPID_KEY` în `depanauto/fcm-init.js` (Firebase Console → Cloud Messaging → Web Push certificates) — la fel ca în varianta PHP.

## Endpoint-uri (toate sub `/depanauto/api/`)
`auth.php`, `register.php`, `create_request.php`, `update_location.php`, `get_status.php`, `action.php`, `profile.php`, `rating.php`, `message.php`, `history.php`, `get_route.php`, `save_fcm_token.php`, `admin.php`.
