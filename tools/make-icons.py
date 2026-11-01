#!/usr/bin/env python3
"""Writes the Safara app icon as SVG variants. Run tools/render-icons.mjs afterwards for the PNGs."""
import pathlib

OUT = pathlib.Path(__file__).resolve().parent.parent / 'public' / 'brand'

# An airliner seen from above, climbing up and to the right over a dotted flight path and a
# faint horizon arc. Drawn in a 512 box; everything stays inside the central 80% circle so
# Android's maskable crop never cuts it.
PLANE = """
    <path id="p" d="M256 84C275 84 287 106 287 138L287 214 408 302C414 307 416 312 416 318L416 338 287 300 287 382 330 414C334 417 336 421 336 426L336 440 256 420 176 440 176 426C176 421 178 417 182 414L225 382 225 300 96 338 96 318C96 312 98 307 104 302L225 214 225 138C225 106 237 84 256 84Z"/>
"""
MARK = f'''
  <circle cx="256" cy="256" r="196" fill="none" stroke="#3FB58A" stroke-opacity=".18" stroke-width="3"/>
  <path d="M126 384C108 400 90 414 64 424" fill="none" stroke="#B9CBC3" stroke-opacity=".8" stroke-width="10" stroke-linecap="round" stroke-dasharray="0.1 19"/>
  <g transform="translate(256 244) rotate(45) scale(.92) translate(-256 -256)">
    <defs>{PLANE}<clipPath id="l"><rect x="0" y="0" width="256" height="512"/></clipPath></defs>
    <use href="#p" fill="#3FB58A"/>
    <use href="#p" fill="#7BE5BC" clip-path="url(#l)"/>
    <path d="M256 84C275 84 287 106 287 138L287 168 225 168 225 138C225 106 237 84 256 84Z" fill="#E9FFF6" opacity=".55"/>
    <rect x="251" y="150" width="10" height="250" rx="5" fill="#0E1F1A" opacity=".16"/>
  </g>
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
