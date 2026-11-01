#!/usr/bin/env python3
"""Writes the Safara app icon as SVG variants. Run tools/render-icons.mjs afterwards for the PNGs."""
import pathlib

OUT = pathlib.Path(__file__).resolve().parent.parent / 'public' / 'brand'

# A paper plane on a dotted flight path. Drawn in a 512 box; the mark stays inside the
# central 80% circle so Android's maskable crop never cuts it.
MARK = '''
  <path d="M84 402C150 412 202 362 230 318" fill="none" stroke="#B9CBC3" stroke-opacity=".75" stroke-width="10" stroke-linecap="round" stroke-dasharray="0.1 21"/>
  <path d="M392 128 128 252l104 40Z" fill="#6FDDB2"/>
  <path d="M392 128 232 292l44 100Z" fill="#3FB58A"/>
  <path d="M232 292l44 100 24-70Z" fill="#2A8C69"/>
'''

DEFS = '''<defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1D4034"/><stop offset="1" stop-color="#0E1F1A"/></linearGradient>
  </defs>'''


def svg(rect, mark_transform=''):
    g = f'<g transform="{mark_transform}">{MARK}</g>' if mark_transform else f'<g>{MARK}</g>'
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">\n  {DEFS}\n  {rect}\n  {g}\n</svg>\n'


OUT.mkdir(parents=True, exist_ok=True)
# Rounded, for browsers and the "any" icons.
(OUT / 'icon.svg').write_text(svg('<rect width="512" height="512" rx="112" fill="url(#bg)"/>'))
# Full-bleed, for the iOS home screen (iOS rounds it) and the maskable icon (Android crops it).
(OUT / 'icon-fullbleed.svg').write_text(svg('<rect width="512" height="512" fill="url(#bg)"/>'))
# Maskable: same, with the mark a little smaller so even a circular crop keeps it whole.
(OUT / 'icon-maskable.svg').write_text(svg('<rect width="512" height="512" fill="url(#bg)"/>', 'translate(256 256) scale(.86) translate(-256 -256)'))
print('wrote', *sorted(p.name for p in OUT.glob('*.svg')))
