// Smoke test end-to-end pentru Worker-ul DepanAuto.
// Simuleaza D1 cu node:sqlite (in-memory) si conduce fluxul complet prin worker.fetch.
// Rulare:  npm test   (din directorul worker/, Node 22+)
import { DatabaseSync } from 'node:sqlite';
import fs from 'node:fs';

// --- Shim D1 peste node:sqlite ---
class Stmt {
  constructor(db, sql) { this.db = db; this.sql = sql; this.params = []; }
  bind(...a) { this.params = a; return this; }
  async first() { const r = this.db.prepare(this.sql).get(...this.params); return r === undefined ? null : r; }
  async all() { return { results: this.db.prepare(this.sql).all(...this.params) }; }
  async run() { const r = this.db.prepare(this.sql).run(...this.params); return { meta: { changes: r.changes, last_row_id: Number(r.lastInsertRowid) } }; }
}
class D1 { constructor(db) { this.db = db; } prepare(sql) { return new Stmt(this.db, sql); } }

const sdb = new DatabaseSync(':memory:');
sdb.exec(fs.readFileSync(new URL('../schema.sql', import.meta.url), 'utf8'));
const env = { DB: new D1(sdb), ALLOWED_ORIGIN: '*', ORS_KEY: '', FIREBASE_CREDENTIALS: '' };

const worker = (await import('../src/index.js')).default;
const base = 'https://x/depanauto/api/';
async function call(name, data, method = 'POST') {
  let url = base + name, init = { method };
  if (method === 'POST') init.body = new URLSearchParams(data);
  else url += '?' + new URLSearchParams(data);
  const res = await worker.fetch(new Request(url, init), env, {});
  return { status: res.status, body: await res.json() };
}
const A = (c, m) => { if (!c) { console.error('FAIL:', m); process.exit(1); } console.log('ok -', m); };

let r = await call('auth.php', { action: 'register', role: 'depanator', name: 'Dep One', email: 'dep@x.ro', phone: '070', password: 'parola1', consent: '1' });
A(r.body.success && r.body.token.length === 64, 'register depanator'); const depTok = r.body.token;
r = await call('auth.php', { action: 'register', role: 'client', name: 'Cli One', email: 'cli@x.ro', phone: '071', password: 'parola1', consent: '1' });
A(r.body.success, 'register client'); const cliTok = r.body.token;
r = await call('auth.php', { action: 'register', role: 'client', name: 'X', email: 'y@x.ro', password: 'parola1' });
A(!r.body.success, 'register fara consent respins');

r = await call('auth.php', { action: 'login', email: 'cli@x.ro', password: 'parola1' }); A(r.body.success && r.body.token === cliTok, 'login client');
r = await call('auth.php', { action: 'login', email: 'cli@x.ro', password: 'gresit' }); A(!r.body.success, 'login parola gresita respins');

await call('update_location.php', { token: cliTok, lat: '44.43', lng: '26.10' });
await call('update_location.php', { token: depTok, lat: '44.44', lng: '26.11' });
r = await call('create_request.php', { token: cliTok, lat: '44.43', lng: '26.10', problem_type: 'baterie', description: 'Nu face contact dimineata' }); A(r.body.success && r.body.request_id, 'create_request'); const reqId = r.body.request_id;
r = await call('create_request.php', { token: cliTok, lat: '44.43', lng: '26.10' }); A(!r.body.success, 'a doua cerere activa respinsa');

r = await call('get_status.php', { token: cliTok }, 'GET'); A(r.body.active_request && r.body.active_request.status === 'waiting', 'client vede cerere waiting');
A(r.body.active_request.problem_type === 'baterie' && r.body.active_request.problem_desc === 'Nu face contact dimineata', 'clientul vede tipul problemei pe cererea proprie');
A(typeof r.body.active_request.waiting_seconds === 'number' && r.body.active_request.waiting_seconds >= 0, 'cererea waiting include waiting_seconds (pt timeout cautare)');
r = await call('get_status.php', { token: depTok }, 'GET'); A(r.body.waiting_requests.length === 1, 'depanator vede 1 cerere in asteptare');
A(r.body.waiting_requests[0].problem_type === 'baterie' && r.body.waiting_requests[0].problem_desc === 'Nu face contact dimineata', 'depanatorul vede tipul problemei pe cererea in asteptare');

r = await call('action.php', { token: depTok, action: 'accept_request', request_id: reqId, dep_lat: '44.44', dep_lng: '26.11' });
A(r.body.success && r.body.price_ron >= 25, 'accept_request cu pret >= minim (' + r.body.price_ron + ' RON)');
r = await call('get_status.php', { token: cliTok }, 'GET'); A(r.body.active_request.status === 'accepted' && r.body.depanator_info.name === 'Dep One', 'client vede accepted + info depanator');

r = await call('action.php', { token: depTok, action: 'complete_request' }); A(r.body.success, 'complete_request');
r = await call('rating.php', { token: cliTok, request_id: reqId, stars: '5' }); A(r.body.success && r.body.new_avg === 5, 'rating 5 stele');
r = await call('rating.php', { token: cliTok, request_id: reqId, stars: '4' }); A(!r.body.success, 'rating dublu respins');

r = await call('history.php', { token: cliTok }, 'GET'); A(r.body.success && r.body.total === 1 && r.body.history[0].my_rating === 5, 'history client cu rating');

r = await call('settings.php', {}, 'GET'); A(r.body.success && r.body.settings.price_per_km === 4.5 && r.body.settings.price_min === 25, 'settings.php public (preturi pt landing)');

const enc = (s) => new TextEncoder().encode(s); const b64 = (b) => Buffer.from(b).toString('base64');
const salt = crypto.getRandomValues(new Uint8Array(16));
const key = await crypto.subtle.importKey('raw', enc('admin123'), 'PBKDF2', false, ['deriveBits']);
const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' }, key, 256);
sdb.prepare('INSERT INTO admins (name,email,password_hash,is_super) VALUES (?,?,?,1)').run('Admin', 'a@x.ro', `pbkdf2$100000$${b64(salt)}$${b64(new Uint8Array(bits))}`);
r = await call('admin.php', { action: 'login', email: 'a@x.ro', password: 'admin123' }); A(r.body.success && r.body.is_super === true, 'admin login'); const aTok = r.body.token;
r = await call('admin.php', { action: 'get_stats', admin_token: aTok }, 'GET'); A(r.body.stats.total_requests === 1 && r.body.stats.completed_requests === 1, 'admin stats corecte');
r = await call('admin.php', { action: 'get_stats', admin_token: 'gresit' }, 'GET'); A(r.status === 401, 'admin fara sesiune -> 401');

// --- Regresie: calculatorul de distanta (la final, ca sa nu afecteze statisticile) ---
// get_route fara ORS -> fallback Haversine, cu distanta + durata pozitive si polyline (gol)
r = await call('get_route.php', { from_lat: '44.43', from_lng: '26.10', to_lat: '44.50', to_lng: '26.20' }, 'GET');
A(r.body.success && r.body.source === 'haversine' && r.body.distance_km > 0 && r.body.duration_min > 0 && Array.isArray(r.body.polyline), 'get_route fallback Haversine cu durata');
// coordonate invalide -> eroare
r = await call('get_route.php', { from_lat: '999', from_lng: '26', to_lat: '44', to_lng: '26' }, 'GET'); A(!r.body.success, 'get_route respinge coordonate invalide');
// 0,0 (Null Island / GPS lipsa) -> eroare
r = await call('get_route.php', { from_lat: '0', from_lng: '0', to_lat: '44', to_lng: '26' }, 'GET'); A(!r.body.success, 'get_route respinge 0,0 (GPS lipsa)');
// plafon de distanta la acceptare: depanator prea departe -> respins, fara pret aberant in DB
r = await call('auth.php', { action: 'register', role: 'client', name: 'Far', email: 'far@x.ro', phone: '072', password: 'parola1', consent: '1' }); const farTok = r.body.token;
await call('update_location.php', { token: farTok, lat: '44.43', lng: '26.10' });
r = await call('create_request.php', { token: farTok, lat: '44.43', lng: '26.10' }); const farReq = r.body.request_id;
r = await call('action.php', { token: depTok, action: 'accept_request', request_id: farReq, dep_lat: '52.52', dep_lng: '13.40' });
A(!r.body.success, 'accept respins cand distanta depaseste plafonul de siguranta');

console.log('\nTOATE TESTELE AU TRECUT ✅');
