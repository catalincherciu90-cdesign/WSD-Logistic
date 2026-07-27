// DepanAuto — Cloudflare Worker (port complet al backend-ului PHP).
// Rute: orice /api/<nume>.php (numele fisierelor sunt pastrate ca sa nu schimbam frontend-ul).
// Bindings: DB (D1). Vars: ALLOWED_ORIGIN, ORS_KEY. Secret: FIREBASE_CREDENTIALS (JSON service account).

const enc = (s) => new TextEncoder().encode(s);

function b64(bytes) { let s = ''; for (const b of bytes) s += String.fromCharCode(b); return btoa(s); }
function ub64(str) { const bin = atob(str); const a = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a; }
function b64url(input) {
  const bytes = typeof input === 'string' ? enc(input) : input;
  return b64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function randomToken() {
  const a = crypto.getRandomValues(new Uint8Array(32));
  return [...a].map((b) => b.toString(16).padStart(2, '0')).join('');
}

// ---------- HTTP helpers ----------
function corsHeaders(env) {
  return {
    'Access-Control-Allow-Origin': env.ALLOWED_ORIGIN || '*',
    'Vary': 'Origin',
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization',
  };
}
function json(env, data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8', ...corsHeaders(env) },
  });
}
const ok = (env, data = {}) => json(env, { success: true, ...data });
const err = (env, message, status = 400) => json(env, { success: false, error: message }, status);

async function parseParams(request) {
  const url = new URL(request.url);
  const params = {};
  for (const [k, v] of url.searchParams) params[k] = v;
  if (request.method === 'POST') {
    const ct = request.headers.get('content-type') || '';
    try {
      if (ct.includes('application/json')) Object.assign(params, await request.json());
      else { const fd = await request.formData(); for (const [k, v] of fd) params[k] = v; }
    } catch (_) { /* body gol / invalid */ }
  }
  return params;
}

function getToken(params) {
  const t = String(params.token || '');
  if (t.length !== 64 || !/^[0-9a-f]+$/i.test(t)) return null;
  return t;
}
const fnum = (v) => { const n = parseFloat(v); return Number.isFinite(n) ? n : null; };

// ---------- Rate limiting (D1) ----------
async function rateLimit(env, bucket, max, windowSec, ip) {
  const key = `${bucket}_${ip}`.replace(/[^a-zA-Z0-9_]/g, '_');
  const now = Math.floor(Date.now() / 1000);
  // Un singur statement atomic: incrementeaza (sau reseteaza daca fereastra a expirat)
  // si intoarce noul contor. Elimina fereastra TOCTOU la cereri concurente (I11).
  const row = await env.DB.prepare(
    'INSERT INTO rate_limits (bucket, count, reset_at) VALUES (?, 1, ?) ' +
    'ON CONFLICT(bucket) DO UPDATE SET ' +
    'count = CASE WHEN rate_limits.reset_at <= ? THEN 1 ELSE rate_limits.count + 1 END, ' +
    'reset_at = CASE WHEN rate_limits.reset_at <= ? THEN ? ELSE rate_limits.reset_at END ' +
    'RETURNING count'
  ).bind(key, now + windowSec, now, now, now + windowSec).first();
  return row ? row.count <= max : true;
}

// ---------- Password hashing (PBKDF2, Web Crypto) ----------
async function hashPassword(password) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const key = await crypto.subtle.importKey('raw', enc(password), 'PBKDF2', false, ['deriveBits']);
  const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' }, key, 256);
  return `pbkdf2$100000$${b64(salt)}$${b64(new Uint8Array(bits))}`;
}
async function verifyPassword(password, stored) {
  try {
    const [scheme, iterStr, saltB64, hashB64] = String(stored).split('$');
    if (scheme !== 'pbkdf2') return false;
    const salt = ub64(saltB64);
    const key = await crypto.subtle.importKey('raw', enc(password), 'PBKDF2', false, ['deriveBits']);
    const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations: parseInt(iterStr, 10), hash: 'SHA-256' }, key, 256);
    const got = b64(new Uint8Array(bits));
    if (got.length !== hashB64.length) return false;
    let diff = 0;
    for (let i = 0; i < got.length; i++) diff |= got.charCodeAt(i) ^ hashB64.charCodeAt(i);
    return diff === 0;
  } catch (_) { return false; }
}

// ---------- Geo, tarifare & rutare ----------
// Valori implicite folosite cand lipsesc randuri in `settings` (protejeaza
// impotriva preturilor 0). Trebuie sa fie identice cu seed-ul din schema.sql.
const PRICE_DEFAULTS = { price_per_km: 4.5, price_fixed: 10, price_min: 25 };
const ROUTE_FACTOR = 1.28;     // linie dreapta -> drum real (cand ORS nu raspunde)
const MAX_DISTANCE_KM = 300;   // plafon de siguranta: peste atata = GPS aberant
const AVG_SPEED_KMH = 40;      // viteza presupusa pt. ETA cand nu avem durata reala

const round2 = (n) => Math.round(n * 100) / 100;

// Tipuri de problema permise la o cerere (valoarea stocata in DB).
const PROBLEM_TYPES = new Set(['pana', 'baterie', 'nu_porneste', 'tractare', 'combustibil', 'accident', 'altele']);

function haversine(lat1, lng1, lat2, lng2) {
  const R = 6371, toRad = (d) => (d * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1), dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// Coordonata plauzibila: in limite si nu 0,0 ("Null Island" = GPS lipsa).
function validCoord(lat, lng) {
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return false;
  if (Math.abs(lat) < 1e-4 && Math.abs(lng) < 1e-4) return false;
  return true;
}

// Pretul cursei pentru o distanta data (km) si setarile de tarifare.
const computePrice = (distanceKm, s) =>
  Math.max(s.price_min, s.price_fixed + distanceKm * s.price_per_km);

// Setarile de tarifare, cu valori implicite garantate (niciun tarif <= 0).
async function getSettings(env) {
  const out = { ...PRICE_DEFAULTS };
  try {
    const { results } = await env.DB.prepare('SELECT setting_key, setting_value FROM settings').all();
    for (const r of results) {
      const v = parseFloat(r.setting_value);
      if (Number.isFinite(v)) out[r.setting_key] = v;
    }
  } catch (_) { /* folosim default-urile */ }
  for (const k of Object.keys(PRICE_DEFAULTS)) {
    if (!Number.isFinite(out[k]) || out[k] <= 0) out[k] = PRICE_DEFAULTS[k];
  }
  return out;
}

// Distanta A -> B: incearca drumul real (OpenRouteService), cu fallback la
// Haversine * ROUTE_FACTOR. Sursa unica pentru harta SI pentru tarifare, ca
// distanta afisata sa fie aceeasi cu cea facturata.
// Returneaza { distance_km, duration_min, polyline, source } sau null (coord. invalide).
async function routeDistance(env, fromLat, fromLng, toLat, toLng) {
  fromLat = Number(fromLat); fromLng = Number(fromLng);
  toLat = Number(toLat); toLng = Number(toLng);
  if (!validCoord(fromLat, fromLng) || !validCoord(toLat, toLng)) return null;

  const fallback = (source = 'haversine') => {
    const dist = haversine(fromLat, fromLng, toLat, toLng) * ROUTE_FACTOR;
    return { distance_km: round2(dist), duration_min: Math.round((dist / AVG_SPEED_KMH) * 60), polyline: [], source };
  };

  if (!env.ORS_KEY) return fallback();
  try {
    // Cheia merge in header-ul Authorization (recomandat de ORS) - evita problemele
    // de codare URL cand cheia contine caractere base64 (+ / =) si nu o expune in URL.
    const url = `https://api.openrouteservice.org/v2/directions/driving-car?start=${fromLng},${fromLat}&end=${toLng},${toLat}`;
    const res = await fetch(url, { headers: { 'Accept': 'application/geo+json, application/json', 'Authorization': env.ORS_KEY } });
    if (res.status !== 200) return fallback();
    const data = await res.json();
    const seg = data?.features?.[0]?.properties?.segments?.[0];
    if (!seg || typeof seg.distance !== 'number') return fallback();
    const geometry = data.features[0].geometry?.coordinates || [];
    return {
      distance_km: round2(seg.distance / 1000),
      duration_min: Math.round((seg.duration || 0) / 60),
      polyline: geometry.map((c) => [c[1], c[0]]),
      source: 'ors',
    };
  } catch (_) { return fallback(); }
}
async function getUser(env, token) {
  return env.DB.prepare('SELECT * FROM users WHERE token = ?').bind(token).first();
}

// ---------- Firebase Cloud Messaging (push) ----------
async function importPrivateKey(pem) {
  const body = pem.replace(/-----BEGIN PRIVATE KEY-----/, '').replace(/-----END PRIVATE KEY-----/, '').replace(/\s+/g, '');
  return crypto.subtle.importKey('pkcs8', ub64(body).buffer, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['sign']);
}
async function getFirebaseAccessToken(creds) {
  const now = Math.floor(Date.now() / 1000);
  const header = b64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
  const claim = b64url(JSON.stringify({
    iss: creds.client_email,
    scope: 'https://www.googleapis.com/auth/firebase.messaging',
    aud: 'https://oauth2.googleapis.com/token',
    iat: now, exp: now + 3600,
  }));
  const unsigned = `${header}.${claim}`;
  const key = await importPrivateKey(creds.private_key);
  const sig = new Uint8Array(await crypto.subtle.sign({ name: 'RSASSA-PKCS1-v1_5' }, key, enc(unsigned)));
  const jwt = `${unsigned}.${b64url(sig)}`;
  const res = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer', assertion: jwt }),
  });
  const j = await res.json().catch(() => ({}));
  return j.access_token || null;
}
async function notifyUserByToken(env, userToken, title, body, data = {}) {
  try {
    if (!userToken || !env.FIREBASE_CREDENTIALS) return false;
    const row = await env.DB.prepare('SELECT fcm_token FROM users WHERE token = ?').bind(userToken).first();
    if (!row || !row.fcm_token) return false;
    const creds = JSON.parse(env.FIREBASE_CREDENTIALS);
    const accessToken = await getFirebaseAccessToken(creds);
    if (!accessToken) return false;
    const strData = {}; for (const k in data) strData[k] = String(data[k]);
    const res = await fetch(`https://fcm.googleapis.com/v1/projects/${creds.project_id}/messages:send`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${accessToken}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: {
          token: row.fcm_token,
          notification: { title, body },
          data: strData,
          webpush: {
            notification: { title, body, icon: '/depanauto/icons/icon-192.png', vibrate: [200, 100, 200], requireInteraction: true },
            fcm_options: { link: '/depanauto/depanator/' },
          },
        },
      }),
    });
    return res.status === 200;
  } catch (_) { return false; }
}

// ====================== ENDPOINT HANDLERS ======================

async function h_auth(request, env, p, ip) {
  if (request.method !== 'POST') return err(env, 'Metoda nepermisa', 405);
  const action = p.action || '';
  if (!(await rateLimit(env, 'auth_' + action, 10, 60, ip))) return err(env, 'Prea multe cereri. Incearca mai tarziu.', 429);

  if (action === 'register') {
    const role = p.role || '', name = (p.name || '').trim();
    const email = (p.email || '').trim().toLowerCase(), phone = (p.phone || '').trim(), password = p.password || '';
    if (!['client', 'depanator'].includes(role)) return err(env, 'Rol invalid');
    if (!name) return err(env, 'Numele este obligatoriu');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return err(env, 'Email invalid');
    if (password.length < 6) return err(env, 'Parola trebuie sa aiba minim 6 caractere');
    if (p.consent !== '1') return err(env, 'Trebuie sa accepti Termenii si Politica de confidentialitate');

    const existing = await env.DB.prepare('SELECT id FROM users WHERE email = ?').bind(email).first();
    if (existing) return err(env, 'Acest email este deja inregistrat');

    const token = randomToken();
    const hash = await hashPassword(password);
    // Depanatorii noi asteapta aprobarea adminului; clientii sunt activi imediat.
    const status = role === 'depanator' ? 'pending' : 'active';
    await env.DB.prepare(
      "INSERT INTO users (token, role, name, email, phone, password_hash, status, online, last_seen, consent_at) " +
      "VALUES (?,?,?,?,?,?,?,0,datetime('now'),datetime('now'))"
    ).bind(token, role, name, email, phone, hash, status).run();
    if (role === 'client') await env.DB.prepare('INSERT INTO client_profiles (user_token) VALUES (?)').bind(token).run();
    else await env.DB.prepare('INSERT INTO depanator_profiles (user_token) VALUES (?)').bind(token).run();
    return ok(env, { token, role, name });
  }

  if (action === 'login') {
    const email = (p.email || '').trim().toLowerCase(), password = p.password || '';
    if (!email || !password) return err(env, 'Email si parola sunt obligatorii');
    const user = await env.DB.prepare('SELECT * FROM users WHERE email = ?').bind(email).first();
    if (!user || !(await verifyPassword(password, user.password_hash))) return err(env, 'Email sau parola incorecta');
    await env.DB.prepare("UPDATE users SET last_seen = datetime('now') WHERE token = ?").bind(user.token).run();
    return ok(env, { token: user.token, role: user.role, name: user.name });
  }
  return err(env, 'Actiune necunoscuta');
}

async function h_register(request, env, p) {
  if (request.method !== 'POST') return err(env, 'Metoda nepermisa', 405);
  const role = p.role || '';
  if (!['client', 'depanator'].includes(role)) return err(env, 'Rol invalid. Foloseste: client sau depanator');
  const token = randomToken();
  await env.DB.prepare("INSERT INTO users (token, role, online, last_seen) VALUES (?,?,1,datetime('now'))").bind(token, role).run();
  return ok(env, { token, role, message: 'Inregistrat cu succes' });
}

async function h_create_request(request, env, p, ip) {
  if (request.method !== 'POST') return err(env, 'Metoda nepermisa', 405);
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  if (!(await rateLimit(env, 'create_request', 10, 60, ip))) return err(env, 'Prea multe cereri. Incearca mai tarziu.', 429);
  const lat = fnum(p.lat), lng = fnum(p.lng);
  if (lat === null || lng === null) return err(env, 'GPS invalid');
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return err(env, 'Coordonate GPS in afara limitelor');
  const user = await getUser(env, token);
  if (!user || user.role !== 'client') return err(env, 'Doar clientii pot trimite cereri');
  if (user.status && user.status !== 'active') return err(env, 'Contul tău este suspendat.');
  const problemType = PROBLEM_TYPES.has(p.problem_type) ? p.problem_type : null;
  const problemDesc = (p.description || '').trim().slice(0, 300) || null;
  // Insert atomic: creeaza cererea DOAR daca nu exista deja una activa (previne dubla cerere - I4).
  const res = await env.DB.prepare(
    "INSERT INTO requests (client_token, status, client_lat, client_lng, problem_type, problem_desc, created_at) " +
    "SELECT ?,'waiting',?,?,?,?,datetime('now') " +
    "WHERE NOT EXISTS (SELECT 1 FROM requests WHERE client_token=? AND status IN ('waiting','accepted'))"
  ).bind(token, lat, lng, problemType, problemDesc, token).run();
  if (res.meta.changes === 0) return err(env, 'Ai deja o cerere activa');
  return ok(env, { request_id: res.meta.last_row_id });
}

async function h_update_location(request, env, p) {
  if (request.method !== 'POST') return err(env, 'Metoda nepermisa', 405);
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  const lat = fnum(p.lat), lng = fnum(p.lng);
  if (lat === null || lng === null) return err(env, 'Coordonate GPS invalide');
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return err(env, 'Coordonate GPS in afara limitelor');
  const res = await env.DB.prepare("UPDATE users SET lat = ?, lng = ?, online = 1, last_seen = datetime('now') WHERE token = ?").bind(lat, lng, token).run();
  if (res.meta.changes === 0) return err(env, 'Token negasit. Inregistreaza-te mai intai.');
  await env.DB.prepare("UPDATE requests SET client_lat = ?, client_lng = ? WHERE client_token = ? AND status IN ('waiting','accepted')").bind(lat, lng, token).run();
  return ok(env);
}

async function h_get_status(request, env, p) {
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  const user = await getUser(env, token);
  if (!user) return err(env, 'Token negasit');
  await env.DB.prepare("UPDATE users SET last_seen = datetime('now'), online = 1 WHERE token = ?").bind(token).run();

  const response = {
    success: true, role: user.role, my_status: user.status || 'active',
    my_location: { lat: user.lat, lng: user.lng },
    active_request: null, other_location: null, depanatori_online: 0,
    settings: await getSettings(env),
  };
  const cnt = await env.DB.prepare(
    "SELECT COUNT(*) AS cnt FROM users WHERE role='depanator' AND status='active' AND online=1 AND last_seen > datetime('now','-30 seconds')"
  ).first();
  response.depanatori_online = cnt ? cnt.cnt : 0;

  if (user.role === 'client') {
    const r = await env.DB.prepare(
      "SELECT r.*, CAST((julianday('now') - julianday(r.created_at)) * 86400 AS INTEGER) AS waiting_seconds, " +
      "u.lat AS dep_lat, u.lng AS dep_lng, u.name AS dep_name, u.phone AS dep_phone, " +
      "dp.vehicle_plate, dp.vehicle_type, dp.vehicle_brand, dp.license_number, dp.rating, dp.total_jobs " +
      "FROM requests r LEFT JOIN users u ON u.token = r.depanator_token " +
      "LEFT JOIN depanator_profiles dp ON dp.user_token = r.depanator_token " +
      "WHERE r.client_token = ? AND r.status IN ('waiting','accepted') ORDER BY r.created_at DESC LIMIT 1"
    ).bind(token).first();
    if (r) {
      response.active_request = { id: r.id, status: r.status, price_ron: r.price_ron, distance_km: r.distance_km, problem_type: r.problem_type, problem_desc: r.problem_desc, waiting_seconds: r.waiting_seconds };
      if (r.status === 'accepted') {
        if (r.dep_lat) response.other_location = { lat: parseFloat(r.dep_lat), lng: parseFloat(r.dep_lng) };
        response.depanator_info = {
          name: r.dep_name ?? '—', phone: r.dep_phone ?? '—',
          vehicle_plate: r.vehicle_plate ?? '—', vehicle_type: r.vehicle_type ?? '—', vehicle_brand: r.vehicle_brand ?? '—',
          rating: r.rating ? Math.round(parseFloat(r.rating) * 10) / 10 : null,
          total_jobs: r.total_jobs ?? 0,
        };
      }
    }
  } else {
    const waiting = await env.DB.prepare(
      "SELECT r.*, u.lat AS client_lat2, u.lng AS client_lng2 FROM requests r " +
      "LEFT JOIN users u ON u.token = r.client_token WHERE r.status = 'waiting' ORDER BY r.created_at DESC LIMIT 5"
    ).all();
    response.waiting_requests = waiting.results;
    const acc = await env.DB.prepare(
      "SELECT r.*, u.lat AS client_lat2, u.lng AS client_lng2 FROM requests r " +
      "LEFT JOIN users u ON u.token = r.client_token WHERE r.depanator_token = ? AND r.status = 'accepted' ORDER BY r.accepted_at DESC LIMIT 1"
    ).bind(token).first();
    if (acc) {
      response.active_request = { id: acc.id, status: acc.status, price_ron: acc.price_ron, distance_km: acc.distance_km, problem_type: acc.problem_type, problem_desc: acc.problem_desc };
      if (acc.client_lat2) response.other_location = { lat: parseFloat(acc.client_lat2), lng: parseFloat(acc.client_lng2) };
    }
  }
  return json(env, response);
}

async function h_action(request, env, p) {
  if (request.method !== 'POST') return err(env, 'Metoda nepermisa', 405);
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  const action = p.action || '';
  const user = await getUser(env, token);
  if (!user) return err(env, 'Token negasit');

  if (action === 'accept_request') {
    if (user.role !== 'depanator') return err(env, 'Doar depanatorii pot accepta cereri');
    if (user.status && user.status !== 'active') return err(env, 'Contul tău nu este activ încă. Așteaptă aprobarea administratorului.');
    const requestId = parseInt(p.request_id || 0, 10);
    const depLat = fnum(p.dep_lat), depLng = fnum(p.dep_lng);
    // Un depanator poate avea o singura cursa activa la un moment dat (C4).
    const busy = await env.DB.prepare("SELECT id FROM requests WHERE depanator_token = ? AND status = 'accepted'").bind(token).first();
    if (busy) return err(env, 'Ai deja o cursă activă. Finalizeaz-o înainte să accepți alta.');
    const r = await env.DB.prepare("SELECT * FROM requests WHERE id = ? AND status = 'waiting'").bind(requestId).first();
    if (!r) return err(env, 'Cerere negasita sau deja acceptata');
    const useLat = (depLat !== null && depLat !== 0) ? depLat : user.lat;
    const useLng = (depLng !== null && depLng !== 0) ? depLng : user.lng;
    if (!useLat || !useLng || !r.client_lat || !r.client_lng) return err(env, 'GPS indisponibil. Asteapta cateva secunde si incearca din nou.');

    // Aceeasi rutare ca harta -> distanta afisata = distanta facturata.
    const route = await routeDistance(env, useLat, useLng, r.client_lat, r.client_lng);
    if (!route) return err(env, 'Coordonate GPS invalide. Asteapta cateva secunde si incearca din nou.');
    if (route.distance_km > MAX_DISTANCE_KM) return err(env, 'Distanta calculata este neverosimil de mare (GPS instabil). Reincearca.');

    const s = await getSettings(env);
    const distRoute = route.distance_km;
    const price = computePrice(distRoute, s);

    await env.DB.prepare('UPDATE users SET lat = ?, lng = ? WHERE token = ?').bind(useLat, useLng, token).run();
    const upd = await env.DB.prepare(
      "UPDATE requests SET status='accepted', depanator_token=?, distance_km=?, price_ron=?, accepted_at=datetime('now') WHERE id=? AND status='waiting'"
    ).bind(token, round2(distRoute), round2(price), requestId).run();
    if (upd.meta.changes === 0) return err(env, 'Nu s-a putut accepta cererea');
    await notifyUserByToken(env, r.client_token, 'Depanator pe drum! 🚗', `${user.name || 'Un depanator'} ți-a acceptat cererea și vine spre tine.`, { type: 'accepted', request_id: requestId });
    return ok(env, { distance_km: round2(distRoute), price_ron: round2(price) });
  }

  if (action === 'cancel_request') {
    const reason = (p.reason || '').trim().slice(0, 255);
    const requestId = parseInt(p.request_id || 0, 10);
    if (user.role === 'client') {
      await env.DB.prepare(
        "UPDATE requests SET status='cancelled', cancelled_by='client', cancel_reason=?, cancelled_at=datetime('now') WHERE client_token=? AND status IN ('waiting','accepted')"
      ).bind(reason || 'Anulat de client', token).run();
    } else {
      // Redeschide DOAR cursa indicata; sterge urmele de anulare, cererea revine curata in pool.
      const sql = "UPDATE requests SET status='waiting', depanator_token=NULL, accepted_at=NULL, cancelled_by=NULL, cancel_reason=NULL, cancelled_at=NULL WHERE depanator_token=? AND status='accepted'" + (requestId ? " AND id=?" : "");
      await env.DB.prepare(sql).bind(...(requestId ? [token, requestId] : [token])).run();
    }
    return ok(env);
  }

  if (action === 'complete_request') {
    if (user.role !== 'depanator') return err(env, 'Doar depanatorii pot finaliza');
    const requestId = parseInt(p.request_id || 0, 10);
    const sql = "UPDATE requests SET status='completed', completed_at=datetime('now') WHERE depanator_token=? AND status='accepted'" + (requestId ? " AND id=?" : "");
    const upd = await env.DB.prepare(sql).bind(...(requestId ? [token, requestId] : [token])).run();
    if (upd.meta.changes === 0) return err(env, 'Nu s-a putut finaliza cursa');
    return ok(env);
  }

  return err(env, 'Actiune necunoscuta');
}

async function h_profile(request, env, p) {
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  const user = await getUser(env, token);
  if (!user) return err(env, 'Token invalid');
  if (request.method === 'GET') {
    const table = user.role === 'client' ? 'client_profiles' : 'depanator_profiles';
    const profile = await env.DB.prepare(`SELECT * FROM ${table} WHERE user_token = ?`).bind(token).first();
    return ok(env, { role: user.role, name: user.name, email: user.email, phone: user.phone, profile: profile || {} });
  }
  // POST
  // Limite de lungime pe campurile text libere (aliniate la schema MySQL) - Val4 #7.
  const clip = (v, n) => (v || '').trim().slice(0, n);
  const name = clip(p.name, 120), phone = clip(p.phone, 30);
  if (name) await env.DB.prepare('UPDATE users SET name = ?, phone = ? WHERE token = ?').bind(name, phone, token).run();
  // Upsert: functioneaza si daca randul de profil nu exista inca (ex. cont creat
  // prin register.php) - altfel datele de profil s-ar pierde silentios (I10).
  if (user.role === 'client') {
    await env.DB.prepare(
      'INSERT INTO client_profiles (user_token, car_plate, car_brand, car_model, car_year) VALUES (?,?,?,?,?) ' +
      'ON CONFLICT(user_token) DO UPDATE SET car_plate=excluded.car_plate, car_brand=excluded.car_brand, car_model=excluded.car_model, car_year=excluded.car_year'
    ).bind(token, clip(p.car_plate, 20).toUpperCase(), clip(p.car_brand, 40), clip(p.car_model, 40), clip(p.car_year, 10)).run();
  } else {
    await env.DB.prepare(
      'INSERT INTO depanator_profiles (user_token, license_number, vehicle_plate, vehicle_type, vehicle_brand, vehicle_capacity, bio) VALUES (?,?,?,?,?,?,?) ' +
      'ON CONFLICT(user_token) DO UPDATE SET license_number=excluded.license_number, vehicle_plate=excluded.vehicle_plate, vehicle_type=excluded.vehicle_type, vehicle_brand=excluded.vehicle_brand, vehicle_capacity=excluded.vehicle_capacity, bio=excluded.bio'
    ).bind(token, clip(p.license_number, 60), clip(p.vehicle_plate, 20).toUpperCase(), clip(p.vehicle_type, 40), clip(p.vehicle_brand, 60), clip(p.vehicle_capacity, 40), clip(p.bio, 500)).run();
  }
  return ok(env);
}

async function h_rating(request, env, p) {
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  if (request.method === 'POST') {
    const requestId = parseInt(p.request_id || 0, 10), stars = parseInt(p.stars || 0, 10);
    if (!Number.isInteger(stars) || stars < 1 || stars > 5) return err(env, 'Rating invalid. Valori acceptate: 1-5');
    const r = await env.DB.prepare(
      "SELECT r.*, u.role FROM requests r JOIN users u ON u.token = ? WHERE r.id = ? AND r.client_token = ? AND r.status = 'completed'"
    ).bind(token, requestId, token).first();
    if (!r) return err(env, 'Cerere negasita sau nu poti evalua aceasta cursa');
    if (r.role !== 'client') return err(env, 'Doar clientii pot da rating');
    const already = await env.DB.prepare('SELECT id FROM ratings WHERE request_id = ?').bind(requestId).first();
    if (already) return err(env, 'Ai evaluat deja aceasta cursa');
    await env.DB.prepare('INSERT INTO ratings (request_id, client_token, depanator_token, stars) VALUES (?,?,?,?)')
      .bind(requestId, token, r.depanator_token, stars).run();
    const avg = await env.DB.prepare('SELECT AVG(stars) AS avg_stars, COUNT(*) AS total FROM ratings WHERE depanator_token = ?').bind(r.depanator_token).first();
    const newAvg = Math.round(parseFloat(avg.avg_stars) * 100) / 100;
    await env.DB.prepare('UPDATE depanator_profiles SET rating = ?, total_jobs = ? WHERE user_token = ?').bind(newAvg, avg.total, r.depanator_token).run();
    return ok(env, { new_avg: newAvg });
  }
  const profile = await env.DB.prepare('SELECT rating, total_jobs FROM depanator_profiles WHERE user_token = ?').bind(token).first();
  return ok(env, { rating: profile ? parseFloat(profile.rating) : 5.0, total_jobs: profile ? profile.total_jobs : 0 });
}

async function h_message(request, env, p) {
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  const user = await getUser(env, token);
  if (!user) return err(env, 'Token invalid');
  if (request.method === 'POST') {
    const requestId = parseInt(p.request_id || 0, 10), message = (p.message || '').trim();
    if (!message) return err(env, 'Mesajul este gol');
    if (message.length > 500) return err(env, 'Mesajul este prea lung');
    if (user.role !== 'depanator') return err(env, 'Doar depanatorii pot trimite mesaje');
    const r = await env.DB.prepare("SELECT client_token FROM requests WHERE id = ? AND depanator_token = ? AND status = 'accepted'").bind(requestId, token).first();
    if (!r) return err(env, 'Cerere negasita');
    await env.DB.prepare("INSERT INTO request_messages (request_id, sender_role, message) VALUES (?, 'depanator', ?)").bind(requestId, message).run();
    await notifyUserByToken(env, r.client_token, '🔧 Mesaj de la depanator', message.slice(0, 120), { type: 'message', request_id: requestId });
    return ok(env);
  }
  const requestId = parseInt(p.request_id || 0, 10);
  if (!requestId) return err(env, 'request_id lipsa');
  // Doar partile din cursa pot citi mesajele (altfel: IDOR pe conversatii private).
  const member = await env.DB.prepare('SELECT id FROM requests WHERE id = ? AND (client_token = ? OR depanator_token = ?)').bind(requestId, token, token).first();
  if (!member) return err(env, 'Cerere negasita');
  const msgs = await env.DB.prepare('SELECT * FROM request_messages WHERE request_id = ? AND seen = 0 ORDER BY created_at ASC').bind(requestId).all();
  if (msgs.results.length) await env.DB.prepare('UPDATE request_messages SET seen = 1 WHERE request_id = ? AND seen = 0').bind(requestId).run();
  return ok(env, { messages: msgs.results });
}

async function h_history(request, env, p) {
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  const page = Math.max(1, parseInt(p.page || 1, 10)), limit = 20, offset = (page - 1) * limit;
  const user = await env.DB.prepare('SELECT role, name FROM users WHERE token = ?').bind(token).first();
  if (!user) return err(env, 'Token invalid');
  let rows, total, stats;
  if (user.role === 'client') {
    rows = (await env.DB.prepare(
      "SELECT r.*, u.name AS depanator_name, u.phone AS depanator_phone, u.token AS depanator_token, " +
      "dp.vehicle_plate, dp.vehicle_type, dp.vehicle_brand, rt.stars AS my_rating " +
      "FROM requests r LEFT JOIN users u ON u.token = r.depanator_token " +
      "LEFT JOIN depanator_profiles dp ON dp.user_token = r.depanator_token " +
      "LEFT JOIN ratings rt ON rt.request_id = r.id WHERE r.client_token = ? ORDER BY r.created_at DESC LIMIT ? OFFSET ?"
    ).bind(token, limit, offset).all()).results;
    total = (await env.DB.prepare('SELECT COUNT(*) AS c FROM requests WHERE client_token = ?').bind(token).first()).c;
    stats = await env.DB.prepare("SELECT COUNT(*) AS total, SUM(price_ron) AS total_spent FROM requests WHERE client_token = ? AND status = 'completed'").bind(token).first();
  } else {
    rows = (await env.DB.prepare(
      "SELECT r.*, u.name AS client_name, u.phone AS client_phone, cp.car_plate, cp.car_brand, cp.car_model, rt.stars AS my_rating " +
      "FROM requests r LEFT JOIN users u ON u.token = r.client_token " +
      "LEFT JOIN client_profiles cp ON cp.user_token = r.client_token " +
      "LEFT JOIN ratings rt ON rt.request_id = r.id WHERE r.depanator_token = ? ORDER BY r.created_at DESC LIMIT ? OFFSET ?"
    ).bind(token, limit, offset).all()).results;
    total = (await env.DB.prepare('SELECT COUNT(*) AS c FROM requests WHERE depanator_token = ?').bind(token).first()).c;
    stats = await env.DB.prepare("SELECT COUNT(*) AS total, SUM(price_ron) AS total_earned FROM requests WHERE depanator_token = ? AND status = 'completed'").bind(token).first();
  }
  return ok(env, { role: user.role, name: user.name, history: rows, total, page, stats });
}

async function h_get_route(request, env, p, ip) {
  // Endpoint public care atinge un API extern platit (ORS) - limitam abuzul (I9).
  if (!(await rateLimit(env, 'get_route', 60, 60, ip))) return err(env, 'Prea multe cereri. Incearca mai tarziu.', 429);
  const route = await routeDistance(env, fnum(p.from_lat), fnum(p.from_lng), fnum(p.to_lat), fnum(p.to_lng));
  if (!route) return err(env, 'Coordonate invalide');
  return ok(env, route);
}

// Setari publice (preturi) — pentru landing page. Doar citire, fara token.
async function h_settings(request, env) {
  return ok(env, { settings: await getSettings(env) });
}

async function h_save_fcm_token(request, env, p) {
  if (request.method !== 'POST') return err(env, 'Metoda nepermisa', 405);
  const token = getToken(p); if (!token) return err(env, 'Token invalid');
  const fcm = (p.fcm_token || '').trim();
  if (!fcm) return err(env, 'FCM token lipsa');
  const res = await env.DB.prepare('UPDATE users SET fcm_token = ? WHERE token = ?').bind(fcm, token).run();
  if (res.meta.changes === 0) return err(env, 'Token user negasit');
  return ok(env);
}

// ---------- Admin ----------
async function getAdminSession(env, p) {
  const sessionToken = p.admin_token || '';
  if (!sessionToken) return null;
  const session = await env.DB.prepare("SELECT * FROM admin_sessions WHERE token = ? AND expires_at > datetime('now')").bind(sessionToken).first();
  if (!session) return null;
  return env.DB.prepare('SELECT * FROM admins WHERE id = ?').bind(session.admin_id).first();
}
async function h_admin(request, env, p, ip) {
  const action = p.action || '';

  if (action === 'login') {
    if (!(await rateLimit(env, 'admin_login', 10, 60, ip))) return err(env, 'Prea multe cereri.', 429);
    const email = (p.email || '').trim().toLowerCase(), password = p.password || '';
    if (!email || !password) return err(env, 'Email si parola sunt obligatorii');
    const admin = await env.DB.prepare('SELECT * FROM admins WHERE email = ?').bind(email).first();
    if (!admin || !(await verifyPassword(password, admin.password_hash))) return err(env, 'Email sau parola incorecta');
    const token = randomToken();
    const expires = new Date(Date.now() + 8 * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ');
    await env.DB.prepare('INSERT INTO admin_sessions (admin_id, token, expires_at) VALUES (?,?,?)').bind(admin.id, token, expires).run();
    await env.DB.prepare("UPDATE admins SET last_login = datetime('now') WHERE id = ?").bind(admin.id).run();
    return ok(env, { token, name: admin.name, is_super: !!admin.is_super });
  }
  if (action === 'logout') {
    await env.DB.prepare('DELETE FROM admin_sessions WHERE token = ?').bind(p.admin_token || '').run();
    return ok(env);
  }

  const admin = await getAdminSession(env, p);
  if (!admin) return err(env, 'Neautorizat', 401);

  if (action === 'get_stats') {
    const q = (sql) => env.DB.prepare(sql).first().then((r) => Object.values(r)[0]);
    return ok(env, { stats: {
      total_clients: await q("SELECT COUNT(*) FROM users WHERE role='client'"),
      total_depanatori: await q("SELECT COUNT(*) FROM users WHERE role='depanator'"),
      pending_depanatori: await q("SELECT COUNT(*) FROM users WHERE role='depanator' AND status='pending'"),
      total_requests: await q('SELECT COUNT(*) FROM requests'),
      completed_requests: await q("SELECT COUNT(*) FROM requests WHERE status='completed'"),
      total_revenue: await q("SELECT COALESCE(SUM(price_ron),0) FROM requests WHERE status='completed'"),
      active_today: await q("SELECT COUNT(*) FROM users WHERE last_seen >= date('now') AND last_seen < date('now','+1 day')"),
    } });
  }

  if (action === 'get_users') {
    const role = p.role || 'all', status = p.status || 'all', search = (p.search || '').trim();
    const page = Math.max(1, parseInt(p.page || 1, 10)), limit = 20, offset = (page - 1) * limit;
    const where = ['1=1']; const params = [];
    if (role !== 'all') { where.push('u.role = ?'); params.push(role); }
    if (status !== 'all') { where.push('u.status = ?'); params.push(status); }
    if (search) { where.push('(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'); const s = `%${search}%`; params.push(s, s, s); }
    const whereStr = where.join(' AND ');
    const users = (await env.DB.prepare(
      `SELECT u.*, cp.car_plate, cp.car_brand, cp.car_model, dp.vehicle_plate, dp.vehicle_type, dp.license_number, dp.rating, dp.total_jobs, ` +
      `(SELECT COUNT(*) FROM requests r WHERE r.client_token = u.token OR r.depanator_token = u.token) AS total_requests ` +
      `FROM users u LEFT JOIN client_profiles cp ON cp.user_token = u.token LEFT JOIN depanator_profiles dp ON dp.user_token = u.token ` +
      `WHERE ${whereStr} ORDER BY u.created_at DESC LIMIT ? OFFSET ?`
    ).bind(...params, limit, offset).all()).results;
    const total = (await env.DB.prepare(`SELECT COUNT(*) AS c FROM users u WHERE ${whereStr}`).bind(...params).first()).c;
    return ok(env, { users, total, page });
  }

  if (action === 'update_user_status') {
    if (!['active', 'pending', 'suspended'].includes(p.status || '')) return err(env, 'Status invalid');
    await env.DB.prepare('UPDATE users SET status = ? WHERE token = ?').bind(p.status, p.user_token || '').run();
    return ok(env);
  }
  if (action === 'delete_user') {
    if (!admin.is_super) return err(env, 'Neautorizat', 401);
    await env.DB.prepare("UPDATE users SET status = 'suspended' WHERE token = ?").bind(p.user_token || '').run();
    return ok(env);
  }
  if (action === 'get_admins') {
    if (!admin.is_super) return err(env, 'Neautorizat', 401);
    const admins = (await env.DB.prepare('SELECT id, name, email, is_super, created_at, last_login FROM admins ORDER BY created_at ASC').all()).results;
    return ok(env, { admins });
  }
  if (action === 'add_admin') {
    if (!admin.is_super) return err(env, 'Neautorizat', 401);
    const name = (p.name || '').trim(), email = (p.email || '').trim().toLowerCase(), password = p.password || '', isSuper = parseInt(p.is_super || 0, 10);
    if (!name || !email || password.length < 6) return err(env, 'Date incomplete sau parola prea scurta');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return err(env, 'Email invalid');
    try {
      await env.DB.prepare('INSERT INTO admins (name, email, password_hash, is_super) VALUES (?,?,?,?)').bind(name, email, await hashPassword(password), isSuper).run();
      return ok(env);
    } catch (_) { return err(env, 'Email deja folosit'); }
  }
  if (action === 'delete_admin') {
    if (!admin.is_super) return err(env, 'Neautorizat', 401);
    const adminId = parseInt(p.admin_id || 0, 10);
    if (adminId === admin.id) return err(env, 'Nu te poti sterge pe tine insuti');
    await env.DB.prepare('DELETE FROM admins WHERE id = ?').bind(adminId).run();
    return ok(env);
  }
  if (action === 'get_settings') {
    return ok(env, { settings: await getSettings(env) });
  }
  if (action === 'update_settings') {
    for (const field of ['price_per_km', 'price_fixed', 'price_min']) {
      if (p[field] !== undefined) {
        const val = fnum(p[field]);
        if (val !== null && val >= 0) await env.DB.prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?').bind(String(Math.round(val * 100) / 100), field).run();
      }
    }
    return ok(env);
  }
  return err(env, 'Actiune necunoscuta');
}

const ROUTES = {
  'auth.php': h_auth,
  'register.php': h_register,
  'create_request.php': h_create_request,
  'update_location.php': h_update_location,
  'get_status.php': h_get_status,
  'action.php': h_action,
  'profile.php': h_profile,
  'rating.php': h_rating,
  'message.php': h_message,
  'history.php': h_history,
  'get_route.php': h_get_route,
  'save_fcm_token.php': h_save_fcm_token,
  'settings.php': h_settings,
  'admin.php': h_admin,
};

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: corsHeaders(env) });

    // API: orice cale care contine /api/<nume>.php
    const name = url.pathname.split('/').pop();
    if (url.pathname.includes('/api/') && ROUTES[name]) {
      const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
      try {
        const params = await parseParams(request);
        return await ROUTES[name](request, env, params, ip);
      } catch (e) {
        console.error('Worker error:', e && e.stack ? e.stack : e);
        return err(env, 'Eroare interna de server', 500);
      }
    }

    // Restul: fisiere statice (frontend) prin binding-ul ASSETS, daca exista.
    if (env.ASSETS) return env.ASSETS.fetch(request);
    return new Response('Not found', { status: 404 });
  },
};
