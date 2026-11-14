#!/usr/bin/env python3
"""Render the PWA icons for ERP Pilot's offline till (F22 / U21).

Written from scratch rather than pulled from an icon kit for two reasons. First, the only thing here
that must be *exactly* right is the colour: the tile is `--primary` from `resources/css/themes.css`
(hue 234), because an app icon that disagrees with the app is a small permanent embarrassment. Second,
the sandbox has no image library and installing one to draw four flat shapes is the wrong trade.

PNG is simple enough to emit directly: a signature, an IHDR header, one zlib stream of scanlines, an
IEND. No compression tuning, no palette — these are flat colour at 512 px, so a few KB each.

Usage:  python3 tools/make-pwa-icons.py
"""

import colorsys
import struct
import zlib
from pathlib import Path

# The repo is the only home for this: run it from a checkout and it writes beside itself.
OUT = Path(__file__).resolve().parent.parent / "public" / "pwa"

# --primary, light mode: `234 89% 56%`.
HUE, SAT, LIG = 234 / 360, 0.89, 0.56


def hsl(h: float, s: float, l: float) -> tuple[int, int, int]:
    r, g, b = colorsys.hls_to_rgb(h, l, s)  # colorsys orders this h,l,s
    return round(r * 255), round(g * 255), round(b * 255)


def mix(c: tuple[int, int, int], other: tuple[int, int, int], t: float) -> tuple[int, int, int]:
    return tuple(round(a + (b - a) * t) for a, b in zip(c, other))  # type: ignore[return-value]


BRAND = hsl(HUE, SAT, LIG)
BRAND_DEEP = hsl(HUE, SAT, 0.34)
WHITE = (252, 252, 253)


def in_rounded_rect(x: int, y: int, size: int, box: tuple[float, float, float, float], radius: float) -> bool:
    """Inside `box` (x0,y0,x1,y1 as fractions of `size`) with corners rounded by `radius` fractions."""
    x0, y0, x1, y1 = (v * size for v in box)

    if not (x0 <= x < x1 and y0 <= y < y1):
        return False

    r = radius * (x1 - x0)
    cx = min(max(x, x0 + r), x1 - r)
    cy = min(max(y, y0 + r), y1 - r)

    return (x - cx) ** 2 + (y - cy) ** 2 <= r * r or (x0 + r <= x < x1 - r) or (y0 + r <= y < y1 - r)


def zigzag_bottom(x: int, y: int, size: int, left: float, right: float, base: float, teeth: int, depth: float) -> bool:
    """The torn edge of a receipt: a triangle wave along the bottom of the slip."""
    span = (right - left) * size
    pos = (x - left * size) / span * teeth

    if pos < 0 or pos > teeth:
        return False

    frac = pos - int(pos)
    cutoff = (base - depth * abs(1 - 2 * frac)) * size

    return y >= cutoff


def draw(size: int, maskable: bool) -> bytes:
    """One RGBA raster of the icon, returned as raw scanlines with filter bytes."""
    # Maskable icons are cropped by the platform: the artwork has to fit inside the centre 80 %.
    pad = 0.14 if maskable else 0.0
    tile = (pad, pad, 1 - pad, 1 - pad)
    radius = 0.22 if not maskable else 0.0

    # The slip: a narrow white rectangle with a torn bottom, punched with three brand lines.
    slip = (0.32, 0.24, 0.68, 0.80)
    lines = [(0.38, 0.34, 0.62, 0.375), (0.38, 0.44, 0.58, 0.475), (0.38, 0.54, 0.52, 0.575)]

    rows = []

    for y in range(size):
        row = bytearray()

        for x in range(size):
            colour = (0, 0, 0, 0)  # transparent outside the tile, for the non-maskable sizes

            if in_rounded_rect(x, y, size, tile, radius):
                # A flat tile with a shade at the bottom: enough depth to read as an object.
                t = max(0.0, (y / size) - 0.55) / 0.45
                colour = (*mix(BRAND, BRAND_DEEP, min(1.0, t) * 0.55), 255)

                sx0, sy0, sx1, sy1 = slip
                inside_slip = sx0 * size <= x < sx1 * size and sy0 * size <= y < (sy1 - 0.03) * size
                torn = zigzag_bottom(x, y, size, sx0, sx1, sy1 - 0.02, 7, 0.028)

                if inside_slip or torn:
                    colour = (*WHITE, 255)

                    for (lx0, ly0, lx1, ly1) in lines:
                        if lx0 * size <= x < lx1 * size and ly0 * size <= y < ly1 * size:
                            colour = (*BRAND_DEEP, 255)

                            break

            row += bytes(colour)

        rows.append(b"\x00" + bytes(row))  # filter byte 0 = None

    return b"".join(rows)


def png(size: int, maskable: bool) -> bytes:
    raw = draw(size, maskable)
    out = bytearray(b"\x89PNG\r\n\x1a\n")

    def chunk(kind: bytes, payload: bytes) -> None:
        out.extend(struct.pack(">I", len(payload)))
        out.extend(kind + payload)
        out.extend(struct.pack(">I", zlib.crc32(kind + payload) & 0xFFFFFFFF))

    chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 6, 0, 0, 0))
    chunk(b"IDAT", zlib.compress(raw, 9))
    chunk(b"IEND", b"")

    return bytes(out)


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)

    targets = {
        "icon-192.png": (192, False),
        "icon-512.png": (512, False),
        "icon-512-maskable.png": (512, True),
        "apple-touch-icon.png": (180, True),  # iOS never rounds it, so the art must already be inset
    }

    for name, (size, maskable) in targets.items():
        data = png(size, maskable)
        (OUT / name).write_bytes(data)
        print(f"{name:26} {size}x{size}  {len(data) / 1024:6.1f} kB")


if __name__ == "__main__":
    main()
