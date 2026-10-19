/**
 * Does every PHP file still parse?
 *
 * One fault has caused this eight times: a possessive or a contraction
 * written into a single-quoted PHP string -- nearly always a CSS comment
 * inside one of the inline-style functions. The apostrophe closes the
 * string and the whole file stops parsing. Every time it was caught by
 * php -l, and every time that was after the edit had been written, staged
 * and queued for a release.
 *
 * It is not a thing to spot by eye. It is a thing to run:
 *
 *   node qa/quotecheck.mjs [dir]
 *
 * Needs the php binary, so run it wherever that is -- the sandbox rather
 * than the laptop. It prints the offending line and points at an
 * apostrophe if it finds one there, and exits non-zero so it can gate a
 * commit.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const files = [];
const walk = (dir) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === 'node_modules' || e.name === '.git') continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full);
    else if (e.name.endsWith('.php')) files.push(full);
  }
};
walk(process.argv[2] || '.');

let bad = 0;
for (const file of files) {
  try {
    execFileSync('php', ['-l', file], { stdio: 'pipe' });
  } catch (e) {
    bad++;
    const out = String(e.stdout || '') + String(e.stderr || '');
    console.log('FAIL ' + file);
    console.log('     ' + (out.split('\n').find((l) => l.trim()) || '').trim());
    const m = out.match(/on line (\d+)/);
    if (m) {
      const n = Number(m[1]);
      const lines = fs.readFileSync(file, 'utf8').split('\n');
      for (let k = Math.max(0, n - 3); k < Math.min(lines.length, n); k++) {
        const hit = /[A-Za-z]'[A-Za-z]/.test(lines[k]);
        console.log('     ' + String(k + 1).padStart(5) + '  ' + lines[k].trim().slice(0, 84)
          + (hit ? '   <-- apostrophe' : ''));
      }
    }
  }
}

if (!bad) {
  console.log('ok   ' + files.length + ' php files parse');
  process.exit(0);
}
console.log('\n' + bad + ' file(s) will not parse. An apostrophe marked above, inside a');
console.log('single-quoted string, is the usual cause: reword it or use double quotes.');
process.exit(1);
