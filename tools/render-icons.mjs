// Renders the SVG icons in public/brand to PNG with headless Chromium, and builds favicon.ico.
// Usage: PW=/path/to/node_modules/playwright node tools/render-icons.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PW || 'playwright');
const dir = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../public/brand');

const jobs = [
  ['icon.svg', 'icon-192.png', 192], ['icon.svg', 'icon-512.png', 512],
  ['icon-maskable.svg', 'icon-maskable-192.png', 192], ['icon-maskable.svg', 'icon-maskable-512.png', 512],
  ['icon-fullbleed.svg', 'apple-touch-icon.png', 180],
  ['icon.svg', 'favicon-32.png', 32], ['icon.svg', 'favicon-48.png', 48],
];
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }).catch(() => chromium.launch());
const page = await b.newPage();
for (const [src, out, size] of jobs) {
  const svg = fs.readFileSync(path.join(dir, src), 'utf8');
  await page.setViewportSize({ width: size, height: size });
  await page.setContent(`<style>html,body{margin:0;background:transparent}svg{display:block;width:${size}px;height:${size}px}</style>${svg}`);
  await page.screenshot({ path: path.join(dir, out), omitBackground: true, clip: { x: 0, y: 0, width: size, height: size } });
  console.log('wrote', out);
}
await b.close();

// favicon.ico holding the 32 and 48 px PNGs.
const pngs = [32, 48].map((s) => fs.readFileSync(path.join(dir, `favicon-${s}.png`)));
const header = Buffer.alloc(6); header.writeUInt16LE(1, 2); header.writeUInt16LE(pngs.length, 4);
let offset = 6 + 16 * pngs.length;
const entries = pngs.map((png, i) => {
  const e = Buffer.alloc(16); const s = [32, 48][i];
  e[0] = s; e[1] = s; e.writeUInt16LE(1, 4); e.writeUInt16LE(32, 6); e.writeUInt32LE(png.length, 8); e.writeUInt32LE(offset, 12);
  offset += png.length; return e;
});
fs.writeFileSync(path.resolve(dir, '../favicon.ico'), Buffer.concat([header, ...entries, ...pngs]));
console.log('wrote favicon.ico');
