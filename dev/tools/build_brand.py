"""Génère les fichiers de marque Boötes : symbole SVG, favicons (SVG + PNG) et masques PNG des logos."""
import json, re, sys, pathlib
from PIL import Image, ImageDraw

sym = json.load(open(sys.argv[1]))
images_dir = pathlib.Path(sys.argv[2]); out = pathlib.Path(sys.argv[3])
W, H = sym["width"], sym["height"]
moon, star = sym["paths"][0]["d"], sym["paths"][1]["d"]
MOON, STAR, NIGHT = "#e2e8f0", "#f5a442", "#0b1222"

# 1. Symbole vectoriel (classes pour la mise en forme en ligne, couleurs par défaut sinon)
(out / "logo-symbol.svg").write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" class="logo-symbol">'
    f'<path class="logo-symbol__moon" fill="{MOON}" d="{moon}"/>'
    f'<path class="logo-symbol__star" fill="{STAR}" d="{star}"/></svg>\n', encoding="utf-8")

# 2. Favicon SVG : carré arrondi bleu nuit, symbole centré
xs = [v for p in sym["paths"] for v in (p["bbox"][0], p["bbox"][2])]
ys = [v for p in sym["paths"] for v in (p["bbox"][1], p["bbox"][3])]
bx0, by0, bx1, by1 = min(xs), min(ys), max(xs), max(ys)
S = 64; scale = (S * 0.70) / (by1 - by0)
tx = S / 2 - (bx0 + bx1) / 2 * scale; ty = S / 2 - (by0 + by1) / 2 * scale
(out / "favicon.svg").write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {S} {S}">'
    f'<rect width="{S}" height="{S}" rx="14" fill="{NIGHT}"/>'
    f'<g transform="translate({tx:.2f} {ty:.2f}) scale({scale:.4f})">'
    f'<path fill="{MOON}" d="{moon}"/><path fill="{STAR}" d="{star}"/></g></svg>\n', encoding="utf-8")

# 3. Rastérisation des courbes (aplatissement des Bézier, suréchantillonnage x4)
def flatten(d, steps=24):
    nums = [float(v) for v in re.findall(r"-?\d+(?:\.\d+)?", d)]
    x, y = nums[0], nums[1]; pts = [(x, y)]; i = 2
    while i + 5 < len(nums) + 0:
        c1x, c1y, c2x, c2y, ex, ey = nums[i:i + 6]
        for s in range(1, steps + 1):
            t = s / steps; u = 1 - t
            pts.append((u**3 * x + 3 * u * u * t * c1x + 3 * u * t * t * c2x + t**3 * ex,
                        u**3 * y + 3 * u * u * t * c1y + 3 * u * t * t * c2y + t**3 * ey))
        x, y = ex, ey; i += 6
    return pts

def raster(size, radius_ratio, fill_ratio, rounded=True):
    k = 4; big = size * k
    img = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    dr = ImageDraw.Draw(img)
    if rounded:
        dr.rounded_rectangle((0, 0, big - 1, big - 1), radius=int(big * radius_ratio), fill=NIGHT)
    else:
        dr.rectangle((0, 0, big, big), fill=NIGHT)
    sc = (big * fill_ratio) / (by1 - by0)
    ox = big / 2 - (bx0 + bx1) / 2 * sc; oy = big / 2 - (by0 + by1) / 2 * sc
    for d, col in ((moon, MOON), (star, STAR)):
        dr.polygon([(ox + px * sc, oy + py * sc) for px, py in flatten(d)], fill=col)
    return img.resize((size, size), Image.LANCZOS)

raster(32, 0.22, 0.74).save(out / "favicon-32.png", optimize=True)
raster(180, 0, 0.62, rounded=False).save(out / "apple-touch-icon.png", optimize=True)
raster(192, 0.22, 0.66).save(out / "icon-192.png", optimize=True)
raster(512, 0.22, 0.66).save(out / "icon-512.png", optimize=True)

# 4. Masques PNG des logos (forme seule : la couleur est appliquée en CSS selon le thème)
def mask(src, dest):
    im = Image.open(images_dir / src).convert("RGBA")
    a = im.getchannel("A"); im = Image.new("L", im.size, 0)
    out_im = Image.merge("LA", (im, a)).crop(a.getbbox())
    out_im.save(out / dest, optimize=True)
    return out_im.size

print("logo-horizontal", mask("Logo_Horizontal_Sans_Slogan_Noir.png", "logo-horizontal.png"))
print("logo-vertical", mask("Logo_Vertical_Noir.png", "logo-vertical.png"))
for f in sorted(out.glob("*")):
    print(f"{f.name:22} {f.stat().st_size:>7} octets")
