# DepanAuto — Hardening de securitate

Acest document descrie modificările de securitate aplicate codului și **pașii manuali obligatorii** care trebuie făcuți de tine pe server/console (cod nu le poate face automat).

---

## ⚠️ OBLIGATORIU — rotește TOATE secretele

Secretele vechi au fost incluse în arhivele `.tar.gz` (versionate în Git), deci trebuie considerate **compromise**. Rotește-le pe toate:

1. **Parola MySQL** — schimb-o din cPanel/phpMyAdmin și pune noua valoare în `api/config.local.php` (vezi mai jos). Vechea parolă `wEoRlT2ww...` nu mai trebuie folosită.
2. **Service account Firebase** — în [Firebase Console → Project settings → Service accounts](https://console.firebase.google.com/) generează o cheie nouă, șterge-o pe cea veche, și pune noul `firebase-credentials.json` în `api/` (fișierul e ignorat de Git).
3. **Cheia OpenRouteService** — regenerează cheia din [ORS dashboard](https://openrouteservice.org/dev/#/home), pune-o nouă în `config.local.php` ca `ORS_KEY`.
4. **Firebase Web API Key** (din `depanator/firebase-messaging-sw.js`) — e o cheie publică prin design, dar restrânge-o în Google Cloud Console (HTTP referrers + API restrictions) ca să nu poată fi abuzată.

---

## Configurarea secretelor (nouă)

Secretele NU mai sunt hardcodate în cod. Două opțiuni:

**Opțiunea A — fișier local (recomandat pe shared hosting):**
```bash
cp api/config.local.example.php api/config.local.php
# editează api/config.local.php cu valorile reale
```

**Opțiunea B — variabile de mediu** pe server: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `ORS_KEY`, `ALLOWED_ORIGIN`.

`config.local.php` și `firebase-credentials.json` sunt în `.gitignore` și nu trebuie comise niciodată.

---

## Modificări aplicate în cod

| Zonă | Înainte | După |
|------|---------|------|
| **Secrete DB** | Hardcodate în `config.php` | Din `config.local.php` / env |
| **Cheie ORS** | Hardcodată în `get_route.php` | Din constanta `ORS_KEY` |
| **CORS** | `Access-Control-Allow-Origin: *` | `ALLOWED_ORIGIN` (configurabil, default domeniul propriu) |
| **Erori DB** | Expunea `$e->getMessage()` clientului | Mesaj generic + `error_log` |
| **Rate limiting** | Inexistent | `rateLimit()` per-IP pe `auth.php` (10/min) și `create_request.php` (10/min) |
| **Validare token** | Doar lungime 64 | Lungime 64 **și** `ctype_xdigit` |
| **Validare GPS** | Lipsea în `create_request.php` | Bounds check (-90..90 / -180..180) |
| **Fișiere `test_*.php`** | Publice, expuneau date | **Șterse** + blocate în `.htaccess` |
| **`firebase-credentials.json`** | În repo | Exclus din repo (gitignored) — se pune manual pe server |
| **Schema DB** | 3 tabele; restul create în runtime | `database.sql` complet (9 tabele) |
| **`.htaccess`** | Inexistent | Blochează acces HTTP la `config.local.php`, `*.json`, `*.sql`, `*.md`, `test_*.php` |

---

## Livrat ulterior (sprint completare)

- **GDPR/legal** ✅: pagini `legal/politica-confidentialitate.html` și `legal/termeni-conditii.html`, checkbox de consimțământ obligatoriu la înregistrare (client + depanator), enforce server-side în `auth.php` + dovadă `users.consent_at`. **De completat:** placeholder-ele `[COMPLETEAZĂ: ...]` cu datele firmei (denumire, CUI, adresă, e-mail GDPR).
- **PWA client** ✅: `client/manifest.json` + `client/sw.js` (clientul e acum instalabil ca PWA).
- **Service workers** ✅: strategie network-first pentru HTML (update-urile ajung la useri), versiuni de cache bumpate.
- **Push notifications** ⚙️ (necesită acțiune): wiring complet adăugat — `fcm-init.js` înregistrează tokenul FCM (client + depanator), iar backend-ul notifică clientul la acceptare și la mesaj. **Push-ul rămâne DEZACTIVAT** până completezi `FCM_VAPID_KEY` în `fcm-init.js` cu cheia VAPID din Firebase Console → Cloud Messaging → Web Push certificates. Codul e best-effort și nu afectează aplicația cât timp cheia lipsește. **Nu a putut fi testat live** (lipsă cheie VAPID + mediu Firebase).

## Rămas de făcut

- **Token-uri user**: adaugă expirare/rotație (acum sunt valabile la nesfârșit).
- **`API_BASE`** hardcodat în fișierele front-end — de centralizat într-un singur config.
- **HTTPS**: obligatoriu (GPS și push funcționează doar pe HTTPS).
- **Push către depanatori la cerere nouă**: backend-ul notifică acum doar clientul; pentru a notifica depanatorii la `create_request` trebuie iterat peste tokenele FCM ale depanatorilor online.
- **`api/` orfan** de la rădăcina backup-ului (cu `require_once 'config.php'` rupt) nu a fost importat — era cod mort.
