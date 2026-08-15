/**
 * HS256 JWT verification for Supabase Edge Functions.
 *
 * Runtime-agnostic (WebCrypto + TextEncoder are globals in Deno and Node), so
 * the exact same module runs inside Supabase (Deno) and in the local Node
 * harness. It mirrors `backend/app/Support/JwtClaims.php` semantics:
 *
 *  1. alg pinned to HS256 — alg:none and algorithm confusion are rejected;
 *  2. signature compared in constant time;
 *  3. exp / iat / nbf validated with a small clock-skew leeway;
 *  4. issuer and audience pinned (the function supplies its expected values).
 *
 * In Supabase, the GoTrue-issued access token is verified with the project's
 * JWT secret (injected as `SUPABASE_JWT_SECRET` into edge functions). `sign`
 * is provided for the LOCAL HARNESS only, to mint GoTrue-shaped test tokens —
 * it is never used in deployed functions.
 */
import { JwtError } from './errors.ts';

export interface JwtVerifyOptions {
  secret: string;
  issuer: string;
  audience: string;
  leewaySeconds?: number;
}

function b64urlEncode(bytes: Uint8Array): string {
  let bin = '';
  for (const byte of bytes) bin += String.fromCharCode(byte);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64urlDecode(input: string): Uint8Array {
  const b64 = input.replace(/-/g, '+').replace(/_/g, '/');
  const padded = b64 + '='.repeat((4 - (b64.length % 4)) % 4);
  const bin = atob(padded);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function encodeJson(data: unknown): string {
  return JSON.stringify(data);
}

function decodeJson<T>(bytes: Uint8Array): T | null {
  try {
    return JSON.parse(new TextDecoder().decode(bytes)) as T;
  } catch {
    return null;
  }
}

/** Copy a Uint8Array into a standalone ArrayBuffer (safe for views and
 *  SharedArrayBuffer-backed buffers that the WebCrypto types reject). */
function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer;
}

async function hmacSha256(data: Uint8Array, key: Uint8Array): Promise<Uint8Array> {
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    toArrayBuffer(key),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  return new Uint8Array(await crypto.subtle.sign('HMAC', cryptoKey, toArrayBuffer(data)));
}

function constantTimeEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
  return diff === 0;
}

/**
 * Verify and decode an HS256 JWT.
 *
 * @throws JwtError INVALID_TOKEN (structure/signature/algorithm/issuer/audience)
 *                  or TOKEN_EXPIRED
 */
export async function verifyJwt(token: string, opts: JwtVerifyOptions): Promise<Record<string, unknown>> {
  const parts = token.split('.');
  if (parts.length !== 3 || parts[0] === '' || parts[1] === '' || parts[2] === '') {
    throw new JwtError('INVALID_TOKEN', 'The access token is invalid.');
  }
  const [headerRaw, payloadRaw, signatureRaw] = parts;

  const header = decodeJson<{ alg?: unknown }>(b64urlDecode(headerRaw));
  const payload = decodeJson<Record<string, unknown>>(b64urlDecode(payloadRaw));
  if (header === null || payload === null || header.alg !== 'HS256') {
    throw new JwtError('INVALID_TOKEN', 'The access token is invalid.');
  }

  const expected = await hmacSha256(
    new TextEncoder().encode(`${headerRaw}.${payloadRaw}`),
    new TextEncoder().encode(opts.secret),
  );
  if (!constantTimeEqual(expected, b64urlDecode(signatureRaw))) {
    throw new JwtError('INVALID_TOKEN', 'The access token is invalid.');
  }

  const now = Math.floor(Date.now() / 1000);
  const leeway = opts.leewaySeconds ?? 30;

  if (typeof payload.exp !== 'number' || payload.exp < now - leeway) {
    throw new JwtError('TOKEN_EXPIRED', 'The access token has expired.');
  }
  if (typeof payload.nbf === 'number' && payload.nbf > now + leeway) {
    throw new JwtError('INVALID_TOKEN', 'The access token is invalid.');
  }
  if (typeof payload.iat === 'number' && payload.iat > now + leeway) {
    throw new JwtError('INVALID_TOKEN', 'The access token is invalid.');
  }
  if (payload.iss !== opts.issuer) {
    throw new JwtError('INVALID_TOKEN', 'The access token is invalid.');
  }
  if (payload.aud !== opts.audience) {
    throw new JwtError('INVALID_TOKEN', 'The access token is invalid.');
  }

  return payload;
}

/**
 * Local-harness only: mint an HS256 JWT shaped like a GoTrue access token
 * (standard claims + the application's app_* claims). Never used in deployed
 * functions — real tokens are issued by Supabase Auth.
 */
export async function signJwt(payload: Record<string, unknown>, secret: string): Promise<string> {
  const header = b64urlEncode(new TextEncoder().encode(encodeJson({ alg: 'HS256', typ: 'JWT' })));
  const body = b64urlEncode(new TextEncoder().encode(encodeJson(payload)));
  const unsigned = `${header}.${body}`;
  const signature = await hmacSha256(new TextEncoder().encode(unsigned), new TextEncoder().encode(secret));
  return `${unsigned}.${b64urlEncode(signature)}`;
}
