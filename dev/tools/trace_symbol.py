"""Vectorise le symbole du logo Boötes (croissant + étoile) à partir du canal alpha.

Marching squares avec interpolation sous-pixel (exploite l'anticrénelage), simplification
Douglas-Peucker, puis lissage Catmull-Rom -> courbes de Bézier (angles vifs conservés).
Sortie : JSON {width, height, paths: [{d, area, bbox}]} trié par aire décroissante.
"""
import json, math, sys
from PIL import Image

src, x0, y0, x1, y1, out = sys.argv[1], *map(int, sys.argv[2:6]), sys.argv[6]
alpha = Image.open(src).convert("RGBA").getchannel("A").crop((x0, y0, x1, y1))
W, H = alpha.size
PAD = 1
a = [[0.0] * (W + 2 * PAD) for _ in range(H + 2 * PAD)]
for y in range(H):
    for x in range(W):
        a[y + PAD][x + PAD] = alpha.getpixel((x, y)) / 255.0
T = 0.5

def interp(p, q, vp, vq):
    t = (T - vp) / (vq - vp) if vq != vp else 0.5
    return (p[0] + (q[0] - p[0]) * t, p[1] + (q[1] - p[1]) * t)

# Segments par cellule ; chaque point est identifié par l'arête qui le porte
segments = {}
for y in range(H + 2 * PAD - 1):
    for x in range(W + 2 * PAD - 1):
        tl, tr, br, bl = a[y][x], a[y][x + 1], a[y + 1][x + 1], a[y + 1][x]
        idx = (tl > T) << 3 | (tr > T) << 2 | (br > T) << 1 | (bl > T)
        if idx in (0, 15):
            continue
        E = {  # arête -> (identifiant, point interpolé)
            "t": (("h", x, y), interp((x, y), (x + 1, y), tl, tr)),
            "r": (("v", x + 1, y), interp((x + 1, y), (x + 1, y + 1), tr, br)),
            "b": (("h", x, y + 1), interp((x, y + 1), (x + 1, y + 1), bl, br)),
            "l": (("v", x, y), interp((x, y), (x, y + 1), tl, bl)),
        }
        center = (tl + tr + br + bl) / 4
        table = {1: ["lb"], 2: ["br"], 3: ["lr"], 4: ["rt"], 5: ["lt", "rb"] if center > T else ["lb", "rt"],
                 6: ["bt"], 7: ["lt"], 8: ["tl"], 9: ["tb"], 10: ["tr", "bl"] if center > T else ["tl", "br"],
                 11: ["tr"], 12: ["rl"], 13: ["rb"], 14: ["bl"]}
        for pair in table[idx]:
            (ida, pa), (idb, pb) = E[pair[0]], E[pair[1]]
            segments.setdefault(ida, []).append((idb, pa, pb))
            segments.setdefault(idb, []).append((ida, pb, pa))

# Chaînage des segments en contours fermés
points, visited, contours = {}, set(), []
for k, lst in segments.items():
    for (o, pa, pb) in lst:
        points[k] = pa
for start in list(segments):
    if start in visited:
        continue
    contour, cur, prev = [], start, None
    while True:
        visited.add(cur)
        contour.append(points[cur])
        nxt = [o for (o, _, _) in segments[cur] if o != prev and o not in visited]
        if not nxt:
            break
        prev, cur = cur, nxt[0]
    if len(contour) > 8:
        contours.append([(px - PAD, py - PAD) for (px, py) in contour])

def dp(pts, eps):
    if len(pts) < 3:
        return pts
    (ax, ay), (bx, by) = pts[0], pts[-1]
    L = math.hypot(bx - ax, by - ay) or 1e-9
    dmax, imax = 0, 0
    for i in range(1, len(pts) - 1):
        px, py = pts[i]
        d = abs((bx - ax) * (ay - py) - (ax - px) * (by - ay)) / L
        if d > dmax:
            dmax, imax = d, i
    if dmax > eps:
        return dp(pts[: imax + 1], eps)[:-1] + dp(pts[imax:], eps)
    return [pts[0], pts[-1]]

def simplify_closed(pts, eps):
    far = max(range(len(pts)), key=lambda i: math.hypot(pts[i][0] - pts[0][0], pts[i][1] - pts[0][1]))
    a1 = dp(pts[: far + 1], eps)
    a2 = dp(pts[far:] + [pts[0]], eps)
    return a1[:-1] + a2[:-1]

def area(pts):
    return 0.5 * sum(pts[i][0] * pts[(i + 1) % len(pts)][1] - pts[(i + 1) % len(pts)][0] * pts[i][1] for i in range(len(pts)))

def to_path(pts, corner_deg=55):
    n = len(pts)
    def turn(i):
        p0, p1, p2 = pts[i - 1], pts[i], pts[(i + 1) % n]
        v1 = (p1[0] - p0[0], p1[1] - p0[1]); v2 = (p2[0] - p1[0], p2[1] - p1[1])
        ang = math.degrees(abs(math.atan2(v1[0] * v2[1] - v1[1] * v2[0], v1[0] * v2[0] + v1[1] * v2[1])))
        return ang
    corner = [turn(i) > corner_deg for i in range(n)]
    def tan(i):
        if corner[i]:
            return (0.0, 0.0)
        p0, p2 = pts[i - 1], pts[(i + 1) % n]
        return ((p2[0] - p0[0]) / 6, (p2[1] - p0[1]) / 6)
    f = lambda v: f"{v:.2f}".rstrip("0").rstrip(".")
    d = [f"M{f(pts[0][0])} {f(pts[0][1])}"]
    for i in range(n):
        p1, p2 = pts[i], pts[(i + 1) % n]
        t1, t2 = tan(i), tan((i + 1) % n)
        c1 = (p1[0] + t1[0], p1[1] + t1[1]); c2 = (p2[0] - t2[0], p2[1] - t2[1])
        d.append(f"C{f(c1[0])} {f(c1[1])} {f(c2[0])} {f(c2[1])} {f(p2[0])} {f(p2[1])}")
    return "".join(d) + "Z"

result = []
for c in contours:
    s = simplify_closed(c, 0.22)
    xs = [p[0] for p in s]; ys = [p[1] for p in s]
    result.append({"area": abs(area(s)), "points": len(s), "bbox": [min(xs), min(ys), max(xs), max(ys)], "d": to_path(s)})
result.sort(key=lambda r: -r["area"])
json.dump({"width": W, "height": H, "paths": result}, open(out, "w"))
for r in result:
    print(f"contour aire={r['area']:.0f} points={r['points']} bbox={[round(v,1) for v in r['bbox']]} longueur d={len(r['d'])}")
