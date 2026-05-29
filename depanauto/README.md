# DepanAuto - Ghid instalare

## Structura fisiere

```
depanauto/
├── api/
│   ├── config.php
│   ├── register.php
│   ├── update_location.php
│   ├── get_status.php
│   ├── create_request.php
│   └── action.php
├── client/
│   └── index.html
├── depanator/
│   └── index.html
└── database.sql
```

---

## Pasul 1: Baza de date

1. Deschide phpMyAdmin
2. Click pe tab-ul "SQL"
3. Copiaza continutul fisierului `database.sql` si apasa "Go"
4. Se creeaza automat baza de date `depanauto` cu toate tabelele

---

## Pasul 2: Configurare PHP

Deschide `api/config.php` si modifica:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'depanauto');
define('DB_USER', 'userul_tau_mysql');
define('DB_PASS', 'parola_ta_mysql');
```

---

## Pasul 3: Upload pe server

Incarca tot folderul `depanauto/` in radacina site-ului tau (public_html sau www).

Rezultat:
- `https://domeniultau.ro/depanauto/api/`
- `https://domeniultau.ro/depanauto/client/`
- `https://domeniultau.ro/depanauto/depanator/`

---

## Pasul 4: Actualizeaza URL-ul API

In ambele fisiere HTML (client/index.html si depanator/index.html), cauta linia:

```javascript
const API_BASE = 'https://domeniultau.ro/depanauto/api';
```

Inlocuieste `domeniultau.ro` cu domeniul tau real.

---

## Pasul 5: HTTPS obligatoriu

GPS-ul pe telefon functioneaza DOAR pe HTTPS (nu pe HTTP).
Asigura-te ca ai SSL activ pe domeniu (Let's Encrypt e gratuit pe majoritatea VPS-urilor).

---

## Testare

1. Deschide `https://domeniultau.ro/depanauto/client/` pe un telefon
2. Deschide `https://domeniultau.ro/depanauto/depanator/` pe alt telefon
3. Pe telefonul depanatorului: apasa "Activează serviciu"
4. Pe telefonul clientului: apasa "Cheamă depanator"
5. Pe telefonul depanatorului: apasa "Acceptă cererea"
6. Ambele harti se actualizeaza la fiecare 3 secunde

---

## Cum functioneaza real-time

Fiecare telefon trimite GPS-ul la server la fiecare 3 secunde (watchPosition).
Fiecare telefon citeste statusul celuilalt la fiecare 3 secunde (polling).
Nu e WebSocket pur, dar pentru o aplicatie de depanare e mai mult decat suficient.

---

## Securitate (pentru productie)

Inainte de lansare:
- Adauga autentificare cu parola sau SMS OTP
- Valideaza coordonatele GPS pe server (deja implementat partial)
- Adauga rate limiting (max 1 cerere/secunda per IP)
- Seteaza CORS doar pe domeniul tau (nu *)

---

## Ce urmeaza

- Notificari push (Firebase Cloud Messaging) cand vine o cerere
- Istoric curse cu factura PDF
- Sistem de rating client/depanator
- Plata in aplicatie (Stripe)
