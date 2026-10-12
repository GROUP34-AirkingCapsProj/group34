"""Regenerate the Airking DFD set (drawio pages, single-page drawio, SVG) from dfd-model.json.

Layout is computed, not hand-placed:
  * three bands - left column, centre column (processes), right column
  * node heights grow with degree so every flow gets its own attachment point
  * flows are routed through vertical channels with greedy interval packing, so
    no two vertical segments share an x and no two horizontal segments share a y
  * the order of the outer columns is searched, not guessed: a barycentre pass
    seeds a hill-climb that moves one node at a time and keeps whatever leaves
    the fewest flow crossings (see count_crossings)
  * flow labels are placed by a collision resolver run against the other labels
    and against every node box

Usage:  python tools/gen_dfd.py      (run from the docs/ directory)
"""
import json, os, re

HERE = os.path.dirname(os.path.abspath(__file__))
DOCS = os.path.dirname(HERE)
MODEL = os.path.join(HERE, 'dfd-model.json')

TOP = 74
ENT_W, ENT_H = 200, 72
PROC_W, PROC_H = 290, 88
STORE_W, STORE_H = 320, 56
CTX_W, CTX_H = 320, 230

PORT_GAP = 16          # minimum spacing between attachment points on one side
ROW_GAP = 60           # minimum vertical gap between boxes in a column
LANE_GAP = 26          # horizontal spacing between routing lanes
LANE_PAD = 18          # vertical clearance required before a lane can be reused
MARGIN = 40

FL_CHAR = 4.72         # mean advance of Arial at the 8.8px flow-label size


def wrap(text, maxchars):
    words, lines, cur = text.split(), [], ''
    for w in words:
        trial = (cur + ' ' + w).strip()
        if len(trial) <= maxchars or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def esc(s):
    return (s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
             .replace('"', '&quot;'))


def pack_lanes(edges, key):
    """Greedy interval packing: each edge takes the lowest-numbered lane whose
    previous occupant clears it vertically."""
    lanes, out = [], {}
    for e in sorted(edges, key=lambda e: key(e)[0]):
        lo, hi = key(e)
        for i, used in enumerate(lanes):
            if lo - LANE_PAD > used:
                lanes[i] = hi
                out[e['id']] = i
                break
        else:
            lanes.append(hi)
            out[e['id']] = len(lanes) - 1
    return out, len(lanes)


MAX_ORDER_PASSES = 12   # the swap search always converges well inside this


def _orient(p, q, r):
    v = (q[0] - p[0]) * (r[1] - p[1]) - (q[1] - p[1]) * (r[0] - p[0])
    return (v > 0) - (v < 0)


def count_crossings(page):
    """How many pairs of flow segments cross at a point.

    Only a true X counts: the segments must meet strictly inside both, so a
    shared endpoint or a T-junction - which read fine - is not penalised.
    """
    segs = []
    for e in page['edges']:
        for i in range(len(e['pts']) - 1):
            a, b = e['pts'][i], e['pts'][i + 1]
            if a != b:
                segs.append((e['id'], a, b))

    n = 0
    for i in range(len(segs)):
        ei, a1, a2 = segs[i]
        for j in range(i + 1, len(segs)):
            ej, b1, b2 = segs[j]
            if ei == ej:
                continue
            d1, d2 = _orient(b1, b2, a1), _orient(b1, b2, a2)
            d3, d4 = _orient(a1, a2, b1), _orient(a1, a2, b2)
            if d1 * d2 < 0 and d3 * d4 < 0:
                n += 1
    return n


def build_page(page):
    nodes = {n['id']: dict(n) for n in page['nodes']}
    edges = [dict(e) for e in page['edges']]
    is_ctx = not any(n['kind'] == 'store' for n in page['nodes'])

    mid = [nodes[n['id']] for n in page['nodes'] if n['kind'] == 'process']
    if is_ctx:
        ents = [nodes[n['id']] for n in page['nodes'] if n['kind'] == 'entity']
        half = (len(ents) + 1) // 2
        left, right = ents[:half], ents[half:]
    else:
        left = [nodes[n['id']] for n in page['nodes'] if n['kind'] == 'entity']
        right = [nodes[n['id']] for n in page['nodes'] if n['kind'] == 'store']
    midix = {n['id']: i for i, n in enumerate(mid)}

    col = {}
    for n in left:
        col[n['id']] = 'L'
    for n in mid:
        col[n['id']] = 'C'
    for n in right:
        col[n['id']] = 'R'

    for e in edges:
        cs, ct = col[e['src']], col[e['tgt']]
        if cs == 'C' and ct == 'C':
            if midix[e['tgt']] - midix[e['src']] == 1:
                e['route'], e['ssid'], e['tsid'] = 'down', 'B', 'T'
            else:
                e['route'], e['ssid'], e['tsid'] = 'ch1', 'L', 'L'
        elif 'R' in (cs, ct):
            e['route'] = 'ch2'
            e['ssid'] = 'L' if cs == 'R' else 'R'
            e['tsid'] = 'L' if ct == 'R' else 'R'
        else:
            e['route'] = 'ch1'
            e['ssid'] = 'R' if cs == 'L' else 'L'
            e['tsid'] = 'R' if ct == 'L' else 'L'

    deg = {}
    for e in edges:
        deg[(e['src'], e['ssid'])] = deg.get((e['src'], e['ssid']), 0) + 1
        deg[(e['tgt'], e['tsid'])] = deg.get((e['tgt'], e['tsid']), 0) + 1

    for n in list(left) + list(mid) + list(right):
        if n['kind'] == 'process':
            w, h = (CTX_W, CTX_H) if is_ctx else (PROC_W, PROC_H)
        elif n['kind'] == 'store':
            w, h = STORE_W, STORE_H
        else:
            w, h = ENT_W, ENT_H
        need = max(deg.get((n['id'], s), 0) for s in 'LRTB')
        n['w'], n['h'] = w, max(h, PORT_GAP * (need + 1))

    def bary(n):
        rows = [midix[o] for e in edges for o in (e['src'], e['tgt'])
                if o in midix and n['id'] in (e['src'], e['tgt'])]
        return sum(rows) / len(rows) if rows else 0

    def place(left, right):
        """Lay the three columns out and route every flow. The result
        depends on the order of `left` and `right`, which is what the
        search below explores."""
        def natural(c):
            return sum(n['h'] for n in c) + ROW_GAP * (len(c) - 1)

        span = max(natural(c) for c in (left, mid, right) if c)

        for c in (left, mid, right):
            if not c:
                continue
            extra = span - natural(c)
            gap = ROW_GAP + (extra / (len(c) - 1) if len(c) > 1 else 0)
            y = TOP + (extra / 2 if len(c) == 1 else 0)
            for n in c:
                n['y'] = round(y)
                y += n['h'] + gap

        def far_y(e, end):
            other = nodes[e['tgt'] if end == 'src' else e['src']]
            return other['y'] + other['h'] / 2

        ports, bucket = {}, {}
        for e in edges:
            bucket.setdefault((e['src'], e['ssid']), []).append((e, 'src'))
            bucket.setdefault((e['tgt'], e['tsid']), []).append((e, 'tgt'))
        for (nid, side), lst in bucket.items():
            n = nodes[nid]
            lst.sort(key=lambda p: far_y(p[0], p[1]))
            m = len(lst)
            for k, (e, end) in enumerate(lst):
                f = (k + 1) / (m + 1)
                if side in 'LR':
                    ports[(e['id'], end)] = (0, round(n['y'] + n['h'] * f), side, f)
                else:
                    ports[(e['id'], end)] = (0, n['y'] if side == 'T' else n['y'] + n['h'], side, f)

        def yspan(e):
            return (min(ports[(e['id'], 'src')][1], ports[(e['id'], 'tgt')][1]),
                    max(ports[(e['id'], 'src')][1], ports[(e['id'], 'tgt')][1]))

        ch1 = [e for e in edges if e['route'] == 'ch1']
        ch2 = [e for e in edges if e['route'] == 'ch2']
        lane1, n1 = pack_lanes(ch1, yspan)
        lane2, n2 = pack_lanes(ch2, yspan)

        # channels must also be wide enough for the widest flow label they carry,
        # otherwise a long label has no free ground and spills onto a box
        def widest(group):
            return max([len(e['label']) * FL_CHAR for e in group], default=0)

        c1w = max(150, (n1 + 1) * LANE_GAP, widest(ch1) + 46)
        c2w = max(150, (n2 + 1) * LANE_GAP, widest(ch2) + 46)
        lx = MARGIN
        lw = max([n['w'] for n in left], default=0)
        cx = lx + lw + c1w
        cw = max(n['w'] for n in mid)
        rx = cx + cw + c2w
        rw = max([n['w'] for n in right], default=0)
        for n in left:
            n['x'] = lx
        for n in mid:
            n['x'] = cx
        for n in right:
            n['x'] = rx
        width = (rx + rw if right else cx + cw) + MARGIN
        height = TOP + span + MARGIN

        for e in edges:
            for end in ('src', 'tgt'):
                _, py, side, f = ports[(e['id'], end)]
                n = nodes[e['src'] if end == 'src' else e['tgt']]
                px = (n['x'] if side == 'L' else n['x'] + n['w'] if side == 'R'
                      else round(n['x'] + n['w'] * f))
                ports[(e['id'], end)] = (px, py, side, f)

        lane_x = {}
        for e in ch1:
            lane_x[e['id']] = round(lx + lw + c1w * (lane1[e['id']] + 1) / (n1 + 1))
        for e in ch2:
            lane_x[e['id']] = round(cx + cw + c2w * (lane2[e['id']] + 1) / (n2 + 1))

        for e in edges:
            sx, sy, ss, sf = ports[(e['id'], 'src')]
            tx, ty, ts, tf = ports[(e['id'], 'tgt')]
            if e['route'] == 'down':
                if sx == tx:
                    e['pts'], e['wps'] = [(sx, sy), (tx, ty)], []
                else:
                    ym = (sy + ty) // 2
                    e['pts'] = [(sx, sy), (sx, ym), (tx, ym), (tx, ty)]
                    e['wps'] = [(sx, ym), (tx, ym)]
            else:
                lxx = lane_x[e['id']]
                e['pts'] = [(sx, sy), (lxx, sy), (lxx, ty), (tx, ty)]
                e['wps'] = [(lxx, sy), (lxx, ty)]
            e['ports'] = (ss, sf, ts, tf)

        # nudge any two horizontal runs that would sit collinear and overlapping
        runs = []
        for e in edges:
            for i in range(len(e['pts']) - 1):
                (x0, y0), (x1, y1) = e['pts'][i], e['pts'][i + 1]
                if y0 == y1 and x0 != x1:
                    runs.append([e, i, min(x0, x1), max(x0, x1), y0])
        runs.sort(key=lambda r: (r[4], r[2]))
        for i in range(1, len(runs)):
            a, b = runs[i - 1], runs[i]
            if a[4] == b[4] and b[2] < a[3] and a[0] is not b[0]:
                e, idx = b[0], b[1]
                e['pts'][idx] = (e['pts'][idx][0], e['pts'][idx][1] + 4)
                e['pts'][idx + 1] = (e['pts'][idx + 1][0], e['pts'][idx + 1][1] + 4)
                b[4] += 4

        return dict(name=page['name'], is_ctx=is_ctx, left=left, mid=mid, right=right,
                    nodes=nodes, edges=edges, width=width, height=height,
                    cols=(lx, lw, cx, cw, rx, rw))

    # A barycentre pass is a decent first guess at the column order, but it is
    # only a heuristic and crossings are what make a routed diagram hard to
    # read. So treat the order as something to search: seed with barycentre and
    # two cheap alternatives, then improve each by moving one node at a time -
    # swapping neighbours, and lifting a node out and re-inserting it elsewhere,
    # which is what gets past the local minimum a swap-only search settles in.
    #
    # A column here is at most a dozen nodes, so the whole search costs a second
    # or so, and it is deterministic: no randomness anywhere, and a tie always
    # keeps the order found first.
    by_bary = sorted(left, key=bary), sorted(right, key=bary)
    seeds = [
        by_bary,
        (list(reversed(by_bary[0])), by_bary[1]),
        (list(left), list(right)),          # the order the model declares
    ]

    def improve(col_left, col_right):
        """Hill-climb one seed to a local minimum.

        Best-improvement rather than first-improvement: every single-node move
        is scored and only the best one is taken each round. It costs more
        evaluations but does not depend on the order moves happen to be tried
        in, which makes the result stable and usually lands lower.
        """
        score = count_crossings(place(col_left, col_right))
        for _ in range(MAX_ORDER_PASSES):
            best_move = None
            for which in (0, 1):
                current = col_left if which == 0 else col_right
                for a in range(len(current)):
                    for b in range(len(current)):
                        if a == b:
                            continue
                        trial = list(current)
                        trial.insert(b, trial.pop(a))   # covers swaps too
                        cand_l = trial if which == 0 else col_left
                        cand_r = trial if which == 1 else col_right
                        trial_score = count_crossings(place(cand_l, cand_r))
                        if trial_score < score and (best_move is None or trial_score < best_move[0]):
                            best_move = (trial_score, cand_l, cand_r)
            if best_move is None:
                break
            score, col_left, col_right = best_move
        return score, col_left, col_right

    best = None
    for seed_left, seed_right in seeds:
        result = improve(list(seed_left), list(seed_right))
        if best is None or result[0] < best[0]:
            best = result

    # place() writes x/y onto the shared node dicts, so the winning order has to
    # be the one laid out last.
    return place(best[1], best[2])


def place_labels(page):
    boxes = [(n['x'] - 3, n['y'] - 3, n['x'] + n['w'] + 3, n['y'] + n['h'] + 3)
             for n in list(page['left']) + list(page['mid']) + list(page['right'])]
    placed = []

    def hits(b):
        for o in boxes + placed:
            if b[0] < o[2] and o[0] < b[2] and b[1] < o[3] and o[1] < b[3]:
                return True
        return False

    for e in sorted(page['edges'], key=lambda e: -len(e['label'])):
        w = len(e['label']) * FL_CHAR
        segs = []
        for i in range(len(e['pts']) - 1):
            (x0, y0), (x1, y1) = e['pts'][i], e['pts'][i + 1]
            if y0 == y1 and abs(x1 - x0) > 4:
                segs.append((x0, x1, y0, abs(x1 - x0)))
        segs.sort(key=lambda s: -s[3])
        scored = []
        for si, (x0, x1, y, length) in enumerate(segs):
            rightward = x1 > x0
            anchor = 'start' if rightward else 'end'
            # try several points along the run before giving up on it
            for frac in (0.0, 0.35, 0.7):
                slide = length * frac * (1 if rightward else -1)
                ax = x0 + slide + (6 if rightward else -6)
                for dy in (-6, 13, -19, 26, -32, 39):
                    bx0 = ax if rightward else ax - w
                    cost = si * 40 + abs(dy) + frac * 30
                    scored.append((cost, ax, y + dy, anchor,
                                   (bx0, y + dy - 8, bx0 + w, y + dy + 3)))
        scored.sort(key=lambda c: c[0])
        cands = [c[1:] for c in scored]
        if not segs:
            (x0, y0), (x1, y1) = e['pts'][0], e['pts'][-1]
            ym = (y0 + y1) // 2
            for dx in (8, -8):
                anchor = 'start' if dx > 0 else 'end'
                bx0 = x0 + dx if dx > 0 else x0 + dx - w
                cands.append((x0 + dx, ym, anchor, (bx0, ym - 8, bx0 + w, ym + 3)))
        for ax, ay, anchor, box in cands:
            if not hits(box):
                e['lbl'] = (ax, ay, anchor)
                placed.append(box)
                break
        else:
            ax, ay, anchor, box = cands[0]
            e['lbl'] = (ax, ay, anchor)
            placed.append(box)


SVG_STYLE = """  <style>
    svg { background:#ffffff; }
    .ext  { fill:#eef1f4; stroke:#000; stroke-width:1.7; }
    .proc { fill:#ffffff; stroke:#000; stroke-width:1.7; }
    .store{ fill:#ffffff; stroke:#000; stroke-width:1.7; }
    .ln   { fill:none; stroke:#000; stroke-width:1.25; }
    .en   { font-family:Arial,Helvetica,sans-serif; font-size:11.5px; fill:#000; }
    .pn   { font-family:Arial,Helvetica,sans-serif; font-size:11.5px; font-weight:700; fill:#000; }
    .pnum { font-family:Arial,Helvetica,sans-serif; font-size:11px; font-weight:700; fill:#000; }
    .sn   { font-family:Arial,Helvetica,sans-serif; font-size:11.5px; fill:#000; }
    .snum { font-family:Arial,Helvetica,sans-serif; font-size:11px; font-weight:700; fill:#000; }
    .fl   { font-family:Arial,Helvetica,sans-serif; font-size:8.8px; fill:#33404a;
            paint-order:stroke; stroke:#ffffff; stroke-width:3px; stroke-linejoin:round; }
    .cap  { font-family:Arial,Helvetica,sans-serif; font-size:10px; letter-spacing:.12em; fill:#6b7681; }
  </style>
  <defs><marker id="ar" viewBox="0 0 10 10" refX="9.5" refY="5" markerWidth="7" markerHeight="7"
    orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#000"/></marker></defs>
"""


def svg_page(p):
    lx, lw, cx, cw, rx, rw = p['cols']
    o = ['<?xml version="1.0" encoding="UTF-8"?>',
         '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" '
         'role="img" aria-label="Gane and Sarson data flow diagram: %s">'
         % (p['width'], p['height'], p['width'], p['height'], esc(p['name'])),
         SVG_STYLE,
         '<text class="cap" x="%d" y="34">EXTERNAL ENTITIES</text>' % lx,
         '<text class="cap" x="%d" y="34">PROCESSES</text>' % cx]
    if p['right']:
        o.append('<text class="cap" x="%d" y="34">%s</text>'
                 % (rx, 'EXTERNAL ENTITIES' if p['is_ctx'] else 'DATA STORES'))

    for e in p['edges']:
        o.append('<path class="ln" d="M%s" marker-end="url(#ar)"/>'
                 % ' L'.join('%d,%d' % pt for pt in e['pts']))

    for n in list(p['left']) + list(p['right']):
        if n['kind'] == 'store':
            continue
        o.append('<rect class="ext" x="%d" y="%d" width="%d" height="%d"/>'
                 % (n['x'], n['y'], n['w'], n['h']))
        lines = wrap(n['label'], 26)
        y0 = n['y'] + n['h'] / 2 - (len(lines) - 1) * 7 + 4
        for i, ln in enumerate(lines):
            o.append('<text class="en" x="%d" y="%.0f" text-anchor="middle">%s</text>'
                     % (n['x'] + n['w'] / 2, y0 + i * 14, esc(ln)))

    for n in p['mid']:
        num, title = n['label'].split('|', 1)
        o.append('<rect class="proc" x="%d" y="%d" width="%d" height="%d" rx="9"/>'
                 % (n['x'], n['y'], n['w'], n['h']))
        o.append('<line class="ln" x1="%d" y1="%d" x2="%d" y2="%d"/>'
                 % (n['x'], n['y'] + 25, n['x'] + n['w'], n['y'] + 25))
        o.append('<text class="pnum" x="%d" y="%d" text-anchor="middle">%s</text>'
                 % (n['x'] + n['w'] / 2, n['y'] + 18, esc(num)))
        lines = wrap(title, 30)
        y0 = n['y'] + 25 + (n['h'] - 25) / 2 - (len(lines) - 1) * 7 + 4
        for i, ln in enumerate(lines):
            o.append('<text class="pn" x="%d" y="%.0f" text-anchor="middle">%s</text>'
                     % (n['x'] + n['w'] / 2, y0 + i * 14, esc(ln)))

    for n in p['right']:
        if n['kind'] != 'store':
            continue
        num, title = n['label'].split(' ', 1)
        o.append('<path class="store" d="M%d,%d H%d M%d,%d H%d M%d,%d V%d"/>'
                 % (n['x'], n['y'], n['x'] + n['w'], n['x'], n['y'] + n['h'],
                    n['x'] + n['w'], n['x'], n['y'], n['y'] + n['h']))
        o.append('<line class="ln" x1="%d" y1="%d" x2="%d" y2="%d"/>'
                 % (n['x'] + 36, n['y'], n['x'] + 36, n['y'] + n['h']))
        o.append('<text class="snum" x="%d" y="%d" text-anchor="middle">%s</text>'
                 % (n['x'] + 18, n['y'] + n['h'] / 2 + 4, esc(num)))
        o.append('<text class="sn" x="%d" y="%d">%s</text>'
                 % (n['x'] + 46, n['y'] + n['h'] / 2 + 4, esc(title)))

    for e in p['edges']:
        ax, ay, anchor = e['lbl']
        o.append('<text class="fl" x="%d" y="%d" text-anchor="%s">%s</text>'
                 % (ax, ay, anchor, esc(e['label'])))
    o.append('</svg>')
    return '\n'.join(o)


ENT_STYLE = ('rounded=0;whiteSpace=wrap;html=1;fillColor=#eef1f4;strokeColor=#000000;'
             'fontColor=#000000;fontSize=11;')
PROC_STYLE = ('rounded=1;arcSize=12;whiteSpace=wrap;html=1;fillColor=#ffffff;strokeColor=#000000;'
              'fontColor=#000000;fontSize=11;verticalAlign=middle;')
STORE_STYLE = ('shape=partialRectangle;right=0;whiteSpace=wrap;html=1;fillColor=#ffffff;'
               'strokeColor=#000000;fontColor=#000000;fontSize=11;align=left;spacingLeft=8;')


def mx_page(p):
    o = []
    for n in list(p['left']) + list(p['right']) + list(p['mid']):
        if n['kind'] == 'process':
            num, title = n['label'].split('|', 1)
            val = '&lt;b&gt;%s&lt;/b&gt;&lt;br&gt;%s' % (esc(num), esc(title))
            st = PROC_STYLE
        elif n['kind'] == 'store':
            num, title = n['label'].split(' ', 1)
            val = '&lt;b&gt;%s&lt;/b&gt;&amp;nbsp;&amp;nbsp;%s' % (esc(num), esc(title))
            st = STORE_STYLE
        else:
            val, st = esc(n['label']), ENT_STYLE
        o.append('<mxCell id="%s" value="%s" style="%s" vertex="1" parent="1">'
                 '<mxGeometry x="%d" y="%d" width="%d" height="%d" as="geometry"/></mxCell>'
                 % (n['id'], val, st, n['x'], n['y'], n['w'], n['h']))

    for e in p['edges']:
        ss, sf, ts, tf = e['ports']
        ex, ey = ((0, sf) if ss == 'L' else (1, sf) if ss == 'R'
                  else (sf, 0) if ss == 'T' else (sf, 1))
        nx, ny = ((0, tf) if ts == 'L' else (1, tf) if ts == 'R'
                  else (tf, 0) if ts == 'T' else (tf, 1))
        st = ('edgeStyle=orthogonalEdgeStyle;rounded=0;html=1;strokeColor=#000000;'
              'fontColor=#33404a;fontSize=9;endArrow=block;endFill=1;'
              'labelBackgroundColor=#ffffff;align=left;verticalAlign=bottom;'
              'exitX=%.4f;exitY=%.4f;exitDx=0;exitDy=0;entryX=%.4f;entryY=%.4f;entryDx=0;entryDy=0;'
              % (ex, ey, nx, ny))
        pts = e['pts']
        seglen = [abs(pts[i + 1][0] - pts[i][0]) + abs(pts[i + 1][1] - pts[i][1])
                  for i in range(len(pts) - 1)]
        total = sum(seglen) or 1
        ax, ay, anchor = e['lbl']
        best, run = 0.0, 0.0
        for i in range(len(pts) - 1):
            (x0, y0), (x1, y1) = pts[i], pts[i + 1]
            if y0 == y1 and min(x0, x1) - 8 <= ax <= max(x0, x1) + 8:
                best = run + abs(ax - x0)
                break
            run += seglen[i]
        rel = max(-0.98, min(0.98, 2 * best / total - 1))
        wps = ''.join('<mxPoint x="%d" y="%d"/>' % pt for pt in e['wps'])
        arr = '<Array as="points">%s</Array>' % wps if wps else ''
        off = 4 if anchor == 'start' else -4 - int(len(e['label']) * FL_CHAR)
        o.append('<mxCell id="%s" value="%s" style="%s" edge="1" parent="1" source="%s" target="%s">'
                 '<mxGeometry x="%.4f" relative="1" as="geometry">%s'
                 '<mxPoint x="%d" y="-2" as="offset"/></mxGeometry></mxCell>'
                 % (e['id'], esc(e['label']), st, e['src'], e['tgt'], rel, arr, off))
    return ''.join(o)


def mx_model(p, body):
    return ('<mxGraphModel dx="1400" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" '
            'connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="%d" pageHeight="%d" '
            'math="0" shadow="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>%s'
            '</root></mxGraphModel>' % (p['width'], p['height'], body))


def main():
    pages = [build_page(pg) for pg in json.load(open(MODEL, encoding='utf-8'))]
    for p in pages:
        place_labels(p)
    os.makedirs(os.path.join(DOCS, 'dfd-svg'), exist_ok=True)
    os.makedirs(os.path.join(DOCS, 'dfd-drawio-single'), exist_ok=True)

    parts = []
    for i, p in enumerate(pages):
        body = mx_page(p)
        parts.append('<diagram id="pg%d" name="%s">%s</diagram>'
                     % (i, esc(p['name']), mx_model(p, body)))
        base = re.sub(r'[^A-Za-z0-9]+', '-', p['name']).strip('-')
        with open(os.path.join(DOCS, 'dfd-drawio-single', base + '.drawio'), 'w',
                  encoding='utf-8') as fh:
            fh.write(mx_model(p, body))
        with open(os.path.join(DOCS, 'dfd-svg', base + '.svg'), 'w', encoding='utf-8') as fh:
            fh.write(svg_page(p))
        print('%-44s %5dx%-5d %2d flows' % (p['name'], p['width'], p['height'], len(p['edges'])))

    with open(os.path.join(DOCS, 'Airking-DFD.drawio'), 'w', encoding='utf-8') as fh:
        fh.write('<mxfile host="app.diagrams.net" agent="gen_dfd.py" type="device">'
                 + ''.join(parts) + '</mxfile>')


if __name__ == '__main__':
    main()
