"""
Build the manuals: docs/MANUAL-USER.md and docs/MANUAL-ADMIN.md become

  resources/manuals/manual-user.pdf, resources/manuals/manual-admin.pdf   (served by the application, Manual page)
  docs/MANUAL-USER.docx, docs/MANUAL-ADMIN.docx                          (for editing and sending)

Needs Python with `markdown` and `python-docx` (pip install markdown python-docx) and Google Chrome, which prints the
PDF (set CHROME to its path if it is not in the usual place). Run from the repository root:

    python docs/manual/build.py

The screenshots are in docs/manual/img and are referenced from the Markdown as manual/img/<name>.png.
"""
import os
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import unquote

import markdown
from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.shared import Cm, Pt, RGBColor

ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / 'docs'
OUT_PDF = ROOT / 'resources' / 'manuals'
MANUALS = [('MANUAL-USER', 'manual-user'), ('MANUAL-ADMIN', 'manual-admin')]
CHROME = os.environ.get('CHROME', r'C:\Program Files\Google\Chrome\Application\chrome.exe')

CSS = """
@page { size: A4; margin: 18mm 16mm 20mm 16mm;
  @bottom-left { content: "%(title)s"; font: 8pt 'Segoe UI', Arial, sans-serif; color: #71717a; }
  @bottom-right { content: counter(page) " / " counter(pages); font: 8pt 'Segoe UI', Arial, sans-serif; color: #71717a; } }
@page :first { @bottom-left { content: none; } @bottom-right { content: none; } }
body { font: 10pt/1.5 'Segoe UI', Arial, sans-serif; color: #18181b; }
.cover { height: 240mm; display: flex; flex-direction: column; justify-content: center; border-left: 6px solid #2152C4; padding-left: 14mm; break-after: page; }
.cover h1 { font-size: 28pt; line-height: 1.2; color: #2152C4; margin: 0 0 6mm; }
.cover p { font-size: 12pt; color: #3f3f46; max-width: 140mm; }
h1 { font-size: 20pt; color: #2152C4; }
h2 { font-size: 16pt; color: #2152C4; break-before: page; border-bottom: 1px solid #d4d4d8; padding-bottom: 2mm; margin-top: 0; }
h3 { font-size: 12pt; margin: 6mm 0 2mm; break-after: avoid; }
h4 { font-size: 10.5pt; margin: 5mm 0 2mm; break-after: avoid; }
p, li { orphans: 3; widows: 3; }
a { color: #2152C4; text-decoration: none; }
code { font-family: Consolas, monospace; font-size: 9pt; background: #f4f4f5; padding: 0 1mm; border-radius: 2px; }
table { border-collapse: collapse; width: 100%%; margin: 3mm 0; font-size: 9pt; break-inside: auto; }
th { background: #eef2fb; text-align: left; }
th, td { border: 1px solid #d4d4d8; padding: 1.5mm 2mm; vertical-align: top; }
tr { break-inside: avoid; }
img { max-width: 100%%; max-height: 200mm; border: 1px solid #d4d4d8; border-radius: 2mm; display: block; margin: 3mm auto; }
p:has(> img) { break-inside: avoid; }
hr { display: none; }
"""


def html_of(md_text: str) -> tuple[str, str]:
    """The title, and the body HTML with a cover page and the images as absolute file URLs."""
    title = re.search(r'^# (.+)$', md_text, re.M).group(1).strip()
    body = markdown.markdown(md_text, extensions=['tables', 'toc', 'sane_lists', 'attr_list'])
    body = re.sub(r'src="(manual/img/[^"]+)"', lambda m: 'src="' + (DOCS / m.group(1)).as_uri() + '"', body)
    # the H1 and the paragraphs before the first rule make the cover
    head, _, rest = body.partition('<hr />')
    return title, f'<section class="cover">{head}</section>{rest}'


def build_pdf(title: str, body: str, target: Path) -> None:
    page = f'<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{title}</title><style>{CSS % {"title": title}}</style></head><body>{body}</body></html>'
    with tempfile.TemporaryDirectory() as tmp:
        src = Path(tmp) / 'manual.html'
        src.write_text(page, encoding='utf-8')
        subprocess.run([CHROME, '--headless', '--disable-gpu', '--no-pdf-header-footer', '--allow-file-access-from-files',
                        f'--print-to-pdf={target}', src.as_uri()], check=True, capture_output=True)


# ── Word

def add_runs(par, el) -> None:
    """The inline content of an element: text with bold, italic, code and links kept as formatting."""
    def walk(node, bold=False, italic=False, mono=False):
        if node.text:
            run = par.add_run(node.text)
            run.bold, run.italic = bold or None, italic or None
            if mono:
                run.font.name = 'Consolas'
        for child in node:
            walk(child, bold or child.tag == 'strong', italic or child.tag == 'em', mono or child.tag == 'code')
            if child.tail:
                run = par.add_run(child.tail)
                run.bold, run.italic = bold or None, italic or None
    walk(el)


def add_list(doc, el, level=0) -> None:
    style = 'List Number' if el.tag == 'ol' else 'List Bullet'
    style += f' {level + 1}' if level else ''
    for li in el.findall('li'):
        par = doc.add_paragraph(style=style)
        inner = ET.Element('span')
        inner.text = li.text
        for child in li:
            if child.tag in ('ul', 'ol'):
                continue
            if child.tag == 'p':  # a loose list item
                inner.text = (inner.text or '') + (child.text or '')
                inner.extend(list(child))
            else:
                inner.append(child)
        add_runs(par, inner)
        for child in li:
            if child.tag in ('ul', 'ol'):
                add_list(doc, child, min(level + 1, 2))


def add_table(doc, el) -> None:
    rows = el.findall('.//tr')
    cols = max(len(r) for r in rows)
    table = doc.add_table(rows=0, cols=cols)
    table.style = 'Table Grid'
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    for r in rows:
        cells = table.add_row().cells
        for i, cell_el in enumerate(r):
            par = cells[i].paragraphs[0]
            add_runs(par, cell_el)
            for run in par.runs:
                run.font.size = Pt(9)
                if cell_el.tag == 'th':
                    run.bold = True


def build_docx(title: str, body: str, target: Path) -> None:
    doc = Document()
    doc.styles['Normal'].font.name = 'Calibri'
    doc.styles['Normal'].font.size = Pt(10.5)
    for s in doc.sections:
        s.left_margin = s.right_margin = Cm(2)
    root = ET.fromstring(f'<div>{body}</div>')
    for el in root.iter('section'):  # the cover: title and the opening paragraphs
        for child in el:
            if child.tag == 'h1':
                h = doc.add_heading(child.text, level=0)
                h.runs[0].font.color.rgb = RGBColor(0x21, 0x52, 0xC4)
            else:
                add_runs(doc.add_paragraph(), child)
        doc.add_page_break()
    for el in root:
        if el.tag == 'section':
            continue
        if el.tag in ('h2', 'h3', 'h4'):
            doc.add_heading(''.join(el.itertext()), level={'h2': 1, 'h3': 2, 'h4': 3}[el.tag])
        elif el.tag == 'p' and el.find('img') is not None and not (el.text or '').strip():
            src = el.find('img').get('src')
            path = Path(unquote(src.removeprefix('file:///'))) if src.startswith('file:') else DOCS / src
            doc.add_picture(str(path), width=Cm(16.5))
        elif el.tag == 'p':
            add_runs(doc.add_paragraph(), el)
        elif el.tag in ('ul', 'ol'):
            add_list(doc, el)
        elif el.tag == 'table':
            add_table(doc, el)
    doc.save(target)


def main() -> None:
    OUT_PDF.mkdir(parents=True, exist_ok=True)
    for source, name in MANUALS:
        title, body = html_of((DOCS / f'{source}.md').read_text(encoding='utf-8'))
        build_pdf(title, body, OUT_PDF / f'{name}.pdf')
        build_docx(title, body, DOCS / f'{source}.docx')
        print(f'{source}: {OUT_PDF / (name + ".pdf")} and docs/{source}.docx')


if __name__ == '__main__':
    sys.exit(main())
