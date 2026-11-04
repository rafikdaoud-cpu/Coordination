// Détection de feuille et redressement (js/docscan.js) sur des images synthétiques.
const assert = require('assert');
require('../js/docscan.js');
const S = globalThis.CoordinationDocScan;
let n = 0; const ok = (c, label) => { assert.ok(c, label); n++; console.log('PASS ' + label); };

function inside(q, x, y) { // point dans le quadrilatère convexe
  let sign = 0;
  for (let i = 0; i < 4; i++) { const a = q[i], b = q[(i + 1) % 4]; const c = (b[0] - a[0]) * (y - a[1]) - (b[1] - a[1]) * (x - a[0]); if (c !== 0) { if (sign && Math.sign(c) !== sign) return false; sign = Math.sign(c); } }
  return true;
}
/** Image grise : fond `bg`, feuille `paper` avec lignes de « texte » sombres, bruit léger. */
function scene(w, h, quad, bg = 60, paper = 225, seed = 7) {
  const g = new Uint8Array(w * h); let r = seed;
  const rnd = () => (r = (r * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    let v = quad && inside(quad, x + 0.5, y + 0.5) ? (y % 9 < 2 && x % 23 > 4 ? 70 : paper) : bg + (x * 0.08);
    g[y * w + x] = Math.max(0, Math.min(255, v + (rnd() - 0.5) * 16));
  }
  return g;
}
const near = (a, b, tol) => a.every((p, i) => Math.hypot(p[0] - b[i][0], p[1] - b[i][1]) <= tol);
const W = 320, H = 240;

let q = [[90, 40], [230, 52], [222, 210], [80, 196]];
let d = S.detectQuad(scene(W, H, q), W, H);
ok(d && near(d, q, 5), 'tilted sheet found with ordered corners (TL, TR, BR, BL)');

q = [[160, 18], [292, 112], [170, 226], [36, 128]];
d = S.detectQuad(scene(W, H, q), W, H);
ok(d && near(d, q, 6), 'sheet rotated about 45 degrees found');

q = [[110, 30], [210, 30], [210, 215], [110, 215]];
d = S.detectQuad(scene(W, H, q, 200, 40), W, H);
ok(d && near(d, q, 5), 'dark card on light table found (reverse contrast)');

ok(S.detectQuad(scene(W, H, null), W, H) === null, 'empty table: nothing detected');
ok(S.detectQuad(scene(W, H, [[-20, 20], [200, 20], [200, 220], [-20, 220]]), W, H) === null, 'sheet cut by the frame edge is not captured');
ok(S.detectQuad(scene(W, H, [[150, 110], [175, 110], [175, 130], [150, 130]]), W, H) === null, 'small object ignored');
const blob = scene(W, H, null); for (let y = 60; y < 180; y++) for (let x = 100; x < 220; x++) if (Math.hypot(x - 160, y - 120) < 60) blob[y * W + x] = 230;
ok(S.detectQuad(blob, W, H) === null, 'round object (not a sheet) rejected');

const Hm = S.homography([[0, 0], [100, 0], [100, 140], [0, 140]], [[12, 7], [118, 15], [110, 160], [5, 150]]);
ok([[0, 0, 12, 7], [100, 0, 118, 15], [100, 140, 110, 160], [0, 140, 5, 150]].every(([x, y, u, v]) => { const [a, b] = S.applyH(Hm, x, y); return Math.abs(a - u) < 1e-6 && Math.abs(b - v) < 1e-6; }), 'homography maps the four corners exactly');

// Redressement : un damier dans une zone inclinée redevient un rectangle droit.
const sw = 200, sh = 160, src = new Uint8ClampedArray(sw * sh * 4), quad = [[30, 20], [170, 35], [160, 140], [25, 130]];
const M = S.homography(quad, [[0, 0], [80, 0], [80, 60], [0, 60]]);
for (let y = 0; y < sh; y++) for (let x = 0; x < sw; x++) { const [u, v] = S.applyH(M, x + 0.5, y + 0.5); const c = (Math.floor(u / 20) + Math.floor(v / 20)) % 2 ? 255 : 0; src.set([c, c, c, 255], (y * sw + x) * 4); }
const [ow, oh] = S.outputSize(quad, 1800); const out = S.warpPixels(src, sw, sh, quad, ow, oh);
const cellAt = (fx, fy) => out[(Math.floor(fy * oh) * ow + Math.floor(fx * ow)) * 4];
ok(ow > oh && cellAt(0.125, 0.165) < 60 && cellAt(0.375, 0.165) > 200 && cellAt(0.125, 0.5) > 200, 'perspective corrected: checkerboard straight again');
ok(S.outputSize([[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 1800).join() === '1800,1350', 'output capped at 1800 px, ratio kept');

let t = Date.now(); for (let i = 0; i < 20; i++) S.detectQuad(scene(W, H, [[90, 40], [230, 52], [222, 210], [80, 196]], 60, 225, i), W, H);
const ms = (Date.now() - t) / 20; ok(ms < 40, `detection fast enough for live preview (${ms.toFixed(1)} ms/frame on this machine)`);
console.log(n + ' document detection checks passed');
