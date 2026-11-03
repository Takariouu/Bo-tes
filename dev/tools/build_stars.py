"""Génère deux tuiles SVG d'étoiles (fond fixe + couche scintillante) pour le thème « ciel nocturne »."""
import random, sys, pathlib

out = pathlib.Path(sys.argv[1])
COLORS = ["#e2e8f0"] * 6 + ["#c7d2fe"] * 3 + ["#ffd9a8"] * 2  # blanc lunaire, bleuté, chaud (Arcturus)

def tile(name, size, count, rmin, rmax, omin, omax, seed, sparkles=0):
    rnd = random.Random(seed)
    parts = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{size}" height="{size}" viewBox="0 0 {size} {size}">']
    for _ in range(count):
        x, y = rnd.uniform(2, size - 2), rnd.uniform(2, size - 2)
        r = rnd.uniform(rmin, rmax) ** 1.0
        o = rnd.uniform(omin, omax)
        parts.append(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="{r:.2f}" fill="{rnd.choice(COLORS)}" fill-opacity="{o:.2f}"/>')
    for _ in range(sparkles):
        # Étoile à quatre branches, en écho à l'étoile du logo
        x, y, s = rnd.uniform(20, size - 20), rnd.uniform(20, size - 20), rnd.uniform(3.2, 4.6)
        k = s * 0.22
        d = (f"M{x:.1f} {y - s:.1f}L{x + k:.1f} {y - k:.1f}L{x + s:.1f} {y:.1f}L{x + k:.1f} {y + k:.1f}"
             f"L{x:.1f} {y + s:.1f}L{x - k:.1f} {y + k:.1f}L{x - s:.1f} {y:.1f}L{x - k:.1f} {y - k:.1f}Z")
        parts.append(f'<path d="{d}" fill="{rnd.choice(["#e2e8f0", "#ffd9a8"])}" fill-opacity="0.85"/>')
    parts.append("</svg>\n")
    (out / name).write_text("".join(parts), encoding="utf-8")
    print(name, (out / name).stat().st_size, "octets")

tile("stars-small.svg", 640, 120, 0.35, 0.95, 0.25, 0.8, seed=1793)
tile("stars-bright.svg", 960, 22, 0.9, 1.5, 0.6, 1.0, seed=2026, sparkles=5)
