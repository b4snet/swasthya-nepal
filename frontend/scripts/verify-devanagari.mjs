#!/usr/bin/env node
/**
 * Phase 22 (National Scale) — Devanagari rendering gate.
 *
 * Proves the ACTUAL shipped styles/tokens.css carries the Devanagari-first
 * font stacks for the Nepali interface (html[lang='ne']). Vitest stubs CSS
 * imports, so this check reads the real file at gate time. Fails loudly if
 * the rule is missing, reordered, or the Devanagari face ever drops out of
 * the body stack.
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const css = readFileSync(resolve(root, 'src/styles/tokens.css'), 'utf8');

const failures = [];

if (!css.includes("html[lang='ne']")) {
  failures.push('missing html[lang=\'ne\'] rule — Nepali UI would not get Devanagari-first stacks');
}
if (!css.includes("'Noto Sans Devanagari'")) {
  failures.push('missing "Noto Sans Devanagari" face in tokens.css');
}

const block = css.slice(css.indexOf("html[lang='ne']"));
const bodyLine = block.split('\n').find((l) => l.includes('--font-body')) ?? '';
const devanagariIdx = bodyLine.indexOf('Noto Sans Devanagari');
const publicSansIdx = bodyLine.indexOf('Public Sans');
if (devanagariIdx < 0) {
  failures.push('html[lang=\'ne\'] --font-body does not reference Noto Sans Devanagari');
} else if (publicSansIdx >= 0 && devanagariIdx > publicSansIdx) {
  failures.push('Devanagari face does not LEAD the lang=ne --font-body stack');
}

if (failures.length > 0) {
  console.error('DEVANAGARI GATE FAILED:');
  for (const f of failures) console.error(`  - ${f}`);
  process.exit(1);
}

console.log('DEVANAGARI GATE PASS: html[lang="ne"] Devanagari-first font stacks verified in shipped tokens.css');
