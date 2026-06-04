# DepanAuto — Ghid de deploy

Ai **două variante funcționale** ale aplicației. Alege **una singură** — nu trebuie să le menții pe ambele.

| | A. PHP + MySQL | B. Cloudflare Workers + D1 |
|---|---|---|
| **Cod** | `depanauto/` | `worker/` |
| **Rulează pe** | Hosting clasic (cPanel/VPS) | Cloudflare (global, serverless) |
| **Bază de date** | MySQL (phpMyAdmin) | D1 (SQLite) |
| **Cost** | Hostingul tău actual | Plan gratuit Cloudflare (suficient la început) |
| **Efort** | Mic (e gata) | Mediu (creezi resurse Cloudflare o dată) |
| **Scalare / viteză** | Limitată de server | Foarte bună, automată |
| **Recomandat dacă...** | Vrei rapid live pe `wsdlogistics.ro` | Vrei performanță + costuri mici pe termen lung |

> Ambele folosesc același frontend. La varianta B, rutele păstrează numele `*.php`, deci frontend-ul e identic.

---

## ⚠️ Pas 0 — OBLIGATORIU pentru ambele: rotește secretele
Credențialele vechi sunt în istoricul Git (arhivele `.tar.gz`) → consideră-le compromise:
- **Parolă MySQL** nouă (din cPanel) — doar pentru varianta A
- **Service account Firebase** nou (Firebase Console → Service accounts) + șterge-l pe cel vechi
- **Cheie OpenRouteService** nouă (dashboard ORS)
- Restrânge **Firebase Web API Key** (Google Cloud Console → HTTP referrers)

---

## Varianta A — PHP + MySQL (cPanel/VPS)

1. **Baza de date**
   - În phpMyAdmin → tab **SQL** → rulează conținutul `depanauto/database.sql` (creează cele 9 tabele).
2. **Secretele** (nu în Git)
   ```bash
   cp depanauto/api/config.local.example.php depanauto/api/config.local.php
   # editează config.local.php: DB_*, ORS_KEY, ALLOWED_ORIGIN
   ```
   - Urcă noul `firebase-credentials.json` în `depanauto/api/` (e gitignored).
3. **Upload** folderul `depanauto/` în rădăcina site-ului (ex. `public_html/depanauto/`).
4. **Primul admin**
   ```bash
   php -r "echo password_hash('PAROLA', PASSWORD_DEFAULT);"
   # apoi în phpMyAdmin:
   # INSERT INTO admins (name,email,password_hash,is_super) VALUES ('Admin','admin@wsdlogistics.ro','<hash>',1);
   ```
5. **HTTPS obligatoriu** (GPS și push merg doar pe HTTPS — Let's Encrypt e gratuit).
6. **Push (opțional):** completează `FCM_VAPID_KEY` în `depanauto/fcm-init.js`.
7. **Test:** deschide `https://domeniul-tau/depanauto/client/` și `.../depanator/` pe două telefoane.

Detalii complete: `depanauto/HARDENING.md`.

---

## Varianta B — Cloudflare Workers + D1

Necesită un cont Cloudflare și `wrangler` (`npm i -g wrangler && wrangler login`).

```bash
cd worker
npm install

# 1. Bază D1 → pune database_id afișat în wrangler.toml
wrangler d1 create depanauto

# 2. Creează tabelele
npm run db:init

# 3. Secrete (opționale: fără ele, ruta cade pe estimare / push dezactivat)
wrangler secret put ORS_KEY
wrangler secret put FIREBASE_CREDENTIALS   # lipești tot JSON-ul service account

# 4. (opțional) ALLOWED_ORIGIN = domeniul tău în wrangler.toml [vars]

# 5. Build frontend + deploy
npm run deploy
```

**Primul admin**
```bash
node scripts/hash-password.mjs "parola"
# rulează INSERT-ul afișat:
wrangler d1 execute depanauto --remote --command "INSERT INTO admins (...) VALUES (...);"
```

**Deploy automat (GitHub Actions):** adaugă secretul de repo **`CLOUDFLARE_API_TOKEN`** (permisiuni Workers + D1 edit). Apoi orice push pe `main` care atinge `worker/**` sau frontend-ul deployează singur. Migrarea schemei (pasul 2) rămâne manuală, o dată.

**Push (opțional):** completează `FCM_VAPID_KEY` în `depanauto/fcm-init.js` (același pas ca la varianta A).

Detalii complete: `worker/README.md`.

---

## Verificare finală (ambele variante)
1. Înregistrare client + depanator (bifează consimțământul)
2. Client: „Cheamă depanator” → Depanator: „Acceptă”
3. Verifică: harta se actualizează, prețul apare, mesajele merg
4. Depanator: „Finalizează” → Client: dă rating + descarcă bonul PDF
5. Admin: `.../admin/` → dashboard, tarife, aprobare depanatori

## Note
- **Datele legale**: completează placeholder-ele `[COMPLETEAZĂ]` din `depanauto/legal/*.html` înainte de lansare publică.
- **Migrare date** între variante (MySQL ↔ D1): nu e automată; la început pornești cu bază goală.
