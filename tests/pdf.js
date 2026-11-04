// Assembleur PDF du scan (js/scanpdf.js) : structure et table xref vérifiées sans outil externe.
// Usage : node tests/pdf.js [sortie.pdf]  (le fichier écrit peut être contrôlé par pdfinfo/qpdf)
const fs = require('fs'), assert = require('assert');
require('../js/scanpdf.js');
const jpeg = new Uint8Array(Buffer.from('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA0JCgsKCA0LCgsODg0PEyAVExISEyccHhcgLikxMC4pLSwzOko+MzZGNywtQFdBRkxOUlNSMj5aYVpQYEpRUk//2wBDAQ4ODhMREyYVFSZPNS01T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT0//wAARCAAwAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDrvFPiV/D8tjFFp7Xsl4WVVWTacjbgAbTkndWX/wAJnrf/AEJuofm//wAbo8cf8jL4U/6/P/Z4q7SvS/cUqFOUqfM5X6vu10ZGrb1OL/4TPW/+hN1D83/+N0f8Jnrf/Qm6h+b/APxuu0orP6xh/wDnyvvl/mOz7nF/8Jnrf/Qm6h+b/wDxuj/hM9b/AOhN1D83/wDjddpRR9Yw/wDz5X3y/wAws+5xf/CZ63/0Juofm/8A8brU8LeJX8QS30UuntZSWZVWVpNxyd2QRtGCNtdBXF+B/wDkZfFf/X5/7PLWn7irQqSjT5XG3V90urFqmtQ8cf8AIy+FP+vz/wBnirtK4vxx/wAjL4U/6/P/AGeKu0rPEf7vR9H/AOlMa3YUUUVwlBRRRQAVxfgf/kZfFf8A1+f+zy12lcX4H/5GXxX/ANfn/s8td2H/AN3rei/9KRL3QeOP+Rl8Kf8AX5/7PFXaVz/inw0/iCWxli1BrKSzLMrLHuOTtwQdwwRtrL/4QzW/+hy1D8n/APjlafuKtCnGVTlcb9H3b6IWqb0O0ori/wDhDNb/AOhy1D8n/wDjlH/CGa3/ANDlqH5P/wDHKz+r4f8A5/L7pf5Du+x2lFcX/wAIZrf/AEOWofk//wAco/4QzW/+hy1D8n/+OUfV8P8A8/l90v8AILvsdpXF+B/+Rl8V/wDX5/7PLR/whmt/9DlqH5P/APHK1PC3hp/D8t9LLqDXsl4VZmaPacjdkk7jkndWn7ilQqRjU5nK3R90+qFq2tD/2Q==', 'base64'));
const pdf = Buffer.from(globalThis.CoordinationScan.buildPdf([{ jpeg, width: 64, height: 48 }, { jpeg, width: 48, height: 64 }]));
const text = pdf.toString('latin1');
let n = 0; const ok = (cond, label) => { assert.ok(cond, label); n++; console.log('PASS ' + label); };
ok(text.startsWith('%PDF-1.4') && text.trimEnd().endsWith('%%EOF'), 'PDF header and EOF marker');
const xref = Number(/startxref\n(\d+)/.exec(text)[1]);
ok(text.slice(xref, xref + 4) === 'xref', 'startxref points to xref table');
const entries = text.slice(xref).split('\n').slice(3).filter(l => / n $/.test(l)).map(l => Number(l.slice(0, 10)));
ok(entries.length === 8 && entries.every((off, i) => text.startsWith((i + 1) + ' 0 obj', off)), 'every xref offset points to its object');
ok((text.match(/\/Type \/Page /g) || []).length === 2 && text.includes('/Count 2'), 'two pages declared');
ok(text.includes('/MediaBox [0 0 841.89 595.28]') && text.includes('/MediaBox [0 0 595.28 841.89]'), 'landscape and portrait photos get matching A4 orientation');
ok(pdf.indexOf(Buffer.from(jpeg)) > 0 && text.includes('/Filter /DCTDecode /Length ' + jpeg.length), 'JPEG embedded byte-for-byte');
assert.throws(() => globalThis.CoordinationScan.buildPdf([])); n++; console.log('PASS empty scan refused');
if (process.argv[2]) fs.writeFileSync(process.argv[2], pdf);
console.log(n + ' PDF assembly checks passed');
