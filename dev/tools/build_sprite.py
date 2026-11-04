"""Télécharge des icônes Lucide (lucide-static) et les assemble en sprite SVG de <symbol>."""
import re, sys, urllib.request, pathlib

VERSION = "1.55.0"
dl_dir = pathlib.Path(sys.argv[1]); out = pathlib.Path(sys.argv[2]); lic_out = pathlib.Path(sys.argv[3])
names = sys.argv[4].split()
base = f"https://cdn.jsdelivr.net/npm/lucide-static@{VERSION}"

def fetch(url, dest):
    if not dest.exists():
        with urllib.request.urlopen(url, timeout=30) as r:
            dest.write_bytes(r.read())
    return dest.read_text(encoding="utf-8")

ALLOWED = {"path", "circle", "rect", "line", "polyline", "polygon", "ellipse"}
symbols = []
for n in names:
    svg = fetch(f"{base}/icons/{n}.svg", dl_dir / f"{n}.svg")
    inner = re.search(r"<svg[^>]*>(.*)</svg>", svg, re.S).group(1)
    inner = re.sub(r"<!--.*?-->", "", inner, flags=re.S).strip()
    # Contrôle : uniquement des formes simples, aucun script ni attribut d'événement
    tags = set(re.findall(r"<\s*([a-zA-Z]+)", inner))
    if not tags <= ALLOWED or re.search(r"\bon[a-z]+\s*=|javascript:|href", inner, re.I):
        sys.exit(f"Contenu inattendu dans {n}: {tags}")
    inner = re.sub(r"\s+", " ", inner).replace("> <", "><")
    symbols.append(f'<symbol id="{n}" viewBox="0 0 24 24">{inner}</symbol>')

lic = fetch(f"{base}/LICENSE", dl_dir / "LICENSE")
lic_out.write_text(lic, encoding="utf-8")
out.write_text(
    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" '
    'stroke-linecap="round" stroke-linejoin="round">\n'
    f"<!-- Icônes Lucide v{VERSION} (https://lucide.dev), licence ISC : voir LICENSE-lucide.txt -->\n"
    + "\n".join(symbols) + "\n</svg>\n",
    encoding="utf-8",
)
print(f"{len(symbols)} icônes -> {out} ({out.stat().st_size} octets)")
