# Testare — calculatorul de distanță

Checklist pas cu pas pentru o cursă de probă, ca să confirmi că **distanța afișată = distanța facturată** și că plafoanele de siguranță funcționează.

## Pregătire

Ai nevoie de **2 sesiuni separate** (GPS diferit pentru client și depanator):
- Telefon + laptop, SAU
- Două browsere (ex. Chrome normal + fereastră incognito), SAU
- Două telefoane

URL-uri (înlocuiește `…` cu domeniul Worker-ului, ex. `https://wsdlogistic.<cont>.workers.dev`):
- Client: `…/depanauto/client/`
- Depanator: `…/depanauto/depanator/`
- Admin: `…/depanauto/admin/`

---

## Pas 1 — Verifică tarifele curente (admin)
- [ ] Intră în `/depanauto/admin/` → notează `price_per_km`, `price_fixed`, `price_min` (implicit: **4.5 / 10 / 25**)
- [ ] Reține formula: `preț = max(price_min, price_fixed + km_real × price_per_km)`

## Pas 2 — Înregistrează cele 2 conturi
- [ ] Sesiunea A: cont **depanator** (acceptă permisiunea de **locație**)
- [ ] Sesiunea B: cont **client** (acceptă permisiunea de **locație**)
- [ ] Ambele afișează harta și au GPS activ (nu „Așteptăm GPS-ul…")

## Pas 3 — Lansează o cerere
- [ ] Client (B): apasă **„Cheamă depanator"** → status devine „Se caută…"
- [ ] Depanator (A): vede cererea în listă → apasă **Accept**

## Pas 4 — ✅ Verificarea cheie (distanță = preț)
- [ ] Pe ecranul clientului apar **Distanță**, **ETA** și **Preț**
- [ ] **Ruta albastră de pe hartă** corespunde cu valoarea **Distanță** afișată
- [ ] **Recalculează manual** prețul cu formula din Pas 1 și compară cu **Prețul afișat** — trebuie să coincidă

> Exemplu: 6.4 km real → `max(25, 10 + 6.4×4.5)` = `max(25, 38.8)` = **38.80 RON**

## Pas 5 — Coerență (înainte vs. acum)
- [ ] Distanța afișată trebuie să fie **drumul real pe șosea**, nu linie dreaptă (pe un traseu cu ocoliri se vede clar diferența)
- [ ] Distanța de pe hartă și cea din preț sunt **identice** (acesta era bug-ul principal)

## Pas 6 — Reglare tarife (opțional)
- [ ] Schimbă `price_per_km` din admin → lansează o cerere nouă → confirmă că prețul reflectă noua valoare

---

## 🔒 Teste de siguranță (opțional, tehnice)

Endpoint-ul public de rută, direct din bara de adrese:

- [ ] **Coordonate normale** → întoarce distanță + durată:
  `…/depanauto/api/get_route.php?from_lat=44.43&from_lng=26.10&to_lat=44.50&to_lng=26.20`
- [ ] **Coordonate invalide** → `{"success":false,...}`:
  `…/depanauto/api/get_route.php?from_lat=999&from_lng=26&to_lat=44&to_lng=26`
- [ ] **GPS lipsă (0,0)** → respins:
  `…/depanauto/api/get_route.php?from_lat=0&from_lng=0&to_lat=44&to_lng=26`

Plafonul de siguranță la acceptare: dacă distanța rezultată depășește `MAX_DISTANCE_KM`
(implicit **300 km**), acceptarea e respinsă, ca să nu se salveze un preț aberant
dintr-un fix GPS greșit.

---

## Dacă ceva nu e în regulă

| Simptom | Cauză probabilă | Ce faci |
|---------|-----------------|---------|
| Preț pare prea mare | tarif prea mare în admin | reglează `price_per_km` |
| Distanță = linie dreaptă (nu drum real) | cheia ORS lipsește/expirat | verifică `ORS_KEY` în Cloudflare → Worker → Settings → Variables |
| „GPS indisponibil" la accept | permisiune locație blocată | reactivează locația în browser |
| Distanță hartă ≠ preț | (n-ar trebui să se mai întâmple) | raportează, se investighează |

---

## Note pentru dezvoltatori

- Logica de distanță e centralizată în `routeDistance()` — **o singură sursă** pentru
  hartă și pentru tarifare. Există în două locuri oglindite:
  - Worker (producție): `worker/src/index.js`
  - PHP (cPanel): `depanauto/api/config.php`
- Constante reglabile (Worker: în `index.js`; PHP: `config.php` / variabile de mediu):
  `ROUTE_FACTOR` (1.28), `MAX_DISTANCE_KM` (300), `AVG_SPEED_KMH` (40),
  `PRICE_PER_KM` / `PRICE_FIXED` / `PRICE_MIN` (default-uri dacă lipsesc din `settings`).
- Teste de regresie: `cd worker && npm test` (include cazurile pentru calculatorul de distanță).
