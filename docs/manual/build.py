"""
Build the manuals as Word documents, to edit and to send: docs/MANUAL-USER.md and docs/MANUAL-ADMIN.md become
docs/MANUAL-USER.docx and docs/MANUAL-ADMIN.docx. The manuals are documents only; the application does not show them.
Building again replaces the Word files, so edits made in Word are lost unless they are also made in the Markdown.

Needs Python with `markdown` and `python-docx` (pip install markdown python-docx). Run from the repository root:

    python docs/manual/build.py

The screenshots are in docs/manual/img and are referenced from the Markdown as manual/img/<name>.png.
"""
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import unquote

import markdown
from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.shared import Cm, Pt, RGBColor

ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / 'docs'
MANUALS = [('MANUAL-USER', 'manual-user'), ('MANUAL-ADMIN', 'manual-admin')]



def html_of(md_text: str) -> tuple[str, str]:
    """The title, and the body HTML with a cover page and the images as absolute file URLs."""
    title = re.search(r'^# (.+)$', md_text, re.M).group(1).strip()
    body = markdown.markdown(md_text, extensions=['tables', 'toc', 'sane_lists', 'attr_list'])
    body = re.sub(r'src="(manual/img/[^"]+)"', lambda m: 'src="' + (DOCS / m.group(1)).as_uri() + '"', body)
    # the H1 and the paragraphs before the first rule make the cover
    head, _, rest = body.partition('<hr />')
    return title, f'<section class="cover">{head}</section>{rest}'


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
                h.runs[0].font.color.rgb = RGBColor(0x46, 0x5F, 0xFF)
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
    for source, _ in MANUALS:
        title, body = html_of((DOCS / f'{source}.md').read_text(encoding='utf-8'))
        build_docx(title, body, DOCS / f'{source}.docx')
        print(f'{source}: docs/{source}.docx')


if __name__ == '__main__':
    sys.exit(main())
