#!/usr/bin/env python3
"""
Convert the user manual Markdown into clean, semantic HTML that LibreOffice maps
onto proper Word heading/table styles. Handles only the markdown subset the
manual actually uses, deliberately - no general-purpose parser needed.
"""
import html
import re
import sys

SRC = sys.argv[1]
OUT = sys.argv[2]

lines = open(SRC, encoding="utf-8").read().split("\n")


def inline(t: str) -> str:
    """Escape, then apply inline markdown."""
    t = html.escape(t, quote=False)
    t = re.sub(r"`([^`]+)`", r"<code>\1</code>", t)
    t = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", t)
    t = re.sub(r"(?<![*\w])\*([^*\n]+)\*(?!\*)", r"<em>\1</em>", t)
    # links: keep the label, drop internal anchors so Word has no dead links
    t = re.sub(r"\[([^\]]+)\]\((#[^)]*)\)", r"\1", t)
    t = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<a href="\2">\1</a>', t)
    return t


out = []
i = 0
n = len(lines)

while i < n:
    ln = lines[i]
    s = ln.strip()

    # fenced code block (the pipeline diagram)
    if s.startswith("```"):
        i += 1
        buf = []
        while i < n and not lines[i].strip().startswith("```"):
            buf.append(html.escape(lines[i], quote=False))
            i += 1
        i += 1
        out.append("<pre>" + "\n".join(buf) + "</pre>")
        continue

    # horizontal rule
    if re.fullmatch(r"-{3,}", s):
        out.append("<hr/>")
        i += 1
        continue

    # headings
    m = re.match(r"^(#{1,6})\s+(.*)$", s)
    if m:
        lvl = len(m.group(1))
        text = m.group(2)
        text = re.sub(r"^[\U0001F300-\U0001FAFF\u2600-\u27BF]\s*", "", text)  # strip leading emoji
        out.append(f"<h{lvl}>{inline(text)}</h{lvl}>")
        i += 1
        continue

    # table: header row, separator row, then body
    if s.startswith("|") and i + 1 < n and re.match(r"^\|[\s:\-|]+\|$", lines[i + 1].strip()):
        def cells(row):
            return [c.strip() for c in row.strip().strip("|").split("|")]

        head = cells(s)
        aligns = []
        for spec in cells(lines[i + 1].strip()):
            if spec.endswith(":") and spec.startswith(":"):
                aligns.append("center")
            elif spec.endswith(":"):
                aligns.append("right")
            else:
                aligns.append("left")
        i += 2
        body = []
        while i < n and lines[i].strip().startswith("|"):
            body.append(cells(lines[i].strip()))
            i += 1

        t = ['<table border="1" cellspacing="0" cellpadding="5">', "<thead><tr>"]
        for idx, h in enumerate(head):
            a = aligns[idx] if idx < len(aligns) else "left"
            t.append(f'<th align="{a}">{inline(h)}</th>')
        t.append("</tr></thead><tbody>")
        for row in body:
            t.append("<tr>")
            for idx, c in enumerate(row):
                a = aligns[idx] if idx < len(aligns) else "left"
                t.append(f'<td align="{a}">{inline(c)}</td>')
            t.append("</tr>")
        t.append("</tbody></table>")
        out.append("".join(t))
        continue

    # blockquote (may span several lines)
    if s.startswith(">"):
        buf = []
        while i < n and lines[i].strip().startswith(">"):
            buf.append(lines[i].strip().lstrip(">").strip())
            i += 1
        out.append(
            '<table class="note" border="0" cellspacing="0" cellpadding="8" width="100%">'
            '<tr><td bgcolor="#f4f7fa">' + inline(" ".join(buf).strip()) + "</td></tr></table>"
        )
        continue

    # unordered list
    if re.match(r"^[-*]\s+", s):
        out.append("<ul>")
        while i < n and re.match(r"^[-*]\s+", lines[i].strip()):
            item = re.sub(r"^[-*]\s+", "", lines[i].strip())
            i += 1
            while i < n and lines[i].startswith("  ") and lines[i].strip() and not re.match(r"^[-*\d]", lines[i].strip()):
                item += " " + lines[i].strip()
                i += 1
            out.append(f"<li>{inline(item)}</li>")
        out.append("</ul>")
        continue

    # ordered list
    if re.match(r"^\d+\.\s+", s):
        out.append("<ol>")
        while i < n and re.match(r"^\d+\.\s+", lines[i].strip()):
            item = re.sub(r"^\d+\.\s+", "", lines[i].strip())
            i += 1
            while i < n and lines[i].startswith("   ") and lines[i].strip() and not re.match(r"^\d+\.", lines[i].strip()):
                item += " " + lines[i].strip()
                i += 1
            out.append(f"<li>{inline(item)}</li>")
        out.append("</ol>")
        continue

    # blank
    if s == "":
        i += 1
        continue

    # paragraph: gather until blank or a new block starts
    buf = [s]
    i += 1
    while i < n:
        nxt = lines[i].strip()
        if nxt == "" or nxt.startswith(("#", ">", "|", "```")) or re.match(r"^([-*]\s+|\d+\.\s+|-{3,}$)", nxt):
            break
        buf.append(nxt)
        i += 1
    out.append("<p>" + inline(" ".join(buf)) + "</p>")

CSS = """
body{font-family:'Calibri','Carlito',sans-serif;font-size:11pt;color:#000;line-height:1.45}
h1{font-family:'Montserrat','Calibri',sans-serif;font-size:24pt;color:#0a1035;margin:0 0 4pt}
h2{font-family:'Montserrat','Calibri',sans-serif;font-size:16pt;color:#0a1035;margin:20pt 0 6pt;
   border-bottom:1pt solid #cccccc;padding-bottom:3pt}
h3{font-family:'Montserrat','Calibri',sans-serif;font-size:12.5pt;color:#004668;margin:14pt 0 4pt}
p{margin:0 0 8pt}
ul,ol{margin:0 0 10pt 0}
li{margin:0 0 4pt}
table{border-collapse:collapse;width:100%;font-size:10pt;margin:8pt 0 12pt}
th{background:#eef1f7;color:#0a1035;font-weight:bold;border:0.5pt solid #b9c0d0;padding:5pt}
td{border:0.5pt solid #d5dae6;padding:5pt;vertical-align:top}
code{font-family:'Consolas','DejaVu Sans Mono',monospace;font-size:9.5pt;background:#f2f4f8}
pre{font-family:'Consolas','DejaVu Sans Mono',monospace;font-size:9pt;background:#f6f7fa;
    border:0.5pt solid #d5dae6;padding:8pt;white-space:pre}
table.note{border:none;margin:8pt 0 12pt;font-size:11pt;width:100%}
table.note td{border:none;border-left:3pt solid #004668;background:#f4f7fa;padding:7pt 10pt}
hr{border:none;border-top:0.5pt solid #cccccc;margin:14pt 0}
a{color:#004668}
"""

doc = (
    '<!doctype html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n'
    "<title>Leave Management System - User Manual</title>\n"
    f"<style>{CSS}</style>\n</head>\n<body>\n" + "\n".join(out) + "\n</body>\n</html>\n"
)
open(OUT, "w", encoding="utf-8").write(doc)
print(f"{OUT}: {len(doc)} bytes, {doc.count('<table')} tables, "
      f"{doc.count('<h2')} h2, {doc.count('<h3')} h3, {doc.count('<pre')} pre")
