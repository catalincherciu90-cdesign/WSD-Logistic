// Genereaza un hash de parola compatibil cu Worker-ul (PBKDF2), pentru a crea
// manual primul admin in D1.
// Folosire:  node scripts/hash-password.mjs "parola-ta"
import { webcrypto as crypto } from 'node:crypto';

const password = process.argv[2];
if (!password) {
  console.error('Folosire: node scripts/hash-password.mjs "parola"');
  process.exit(1);
}
const enc = (s) => new TextEncoder().encode(s);
const b64 = (bytes) => Buffer.from(bytes).toString('base64');

const salt = crypto.getRandomValues(new Uint8Array(16));
const key = await crypto.subtle.importKey('raw', enc(password), 'PBKDF2', false, ['deriveBits']);
const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' }, key, 256);
const hash = `pbkdf2$100000$${b64(salt)}$${b64(new Uint8Array(bits))}`;

console.log('\nHash parola:\n' + hash + '\n');
console.log('Exemplu INSERT (admin super):');
console.log(`INSERT INTO admins (name,email,password_hash,is_super) VALUES ('Admin','admin@wsdlogistics.ro','${hash}',1);\n`);
