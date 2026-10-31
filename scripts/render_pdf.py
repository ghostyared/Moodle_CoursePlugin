#!/usr/bin/env python3
"""Render the pages of a PDF as JPEG images for the Yared.AI question importer.

Usage: render_pdf.py <pdf> <output-dir> <max-pages> <max-side>

The script prints one JSON object on stdout. PyMuPDF is used when available; the
importer falls back to the native PDF path when this helper cannot run.
"""

import json
import os
import sys


# PyMuPDF prints MuPDF warnings to stdout. Keep the real stdout only for the final JSON line, so a
# warning about a slightly damaged PDF cannot corrupt the answer the PHP side parses.
REAL_STDOUT = sys.stdout
sys.stdout = sys.stderr


def emit(payload):
    REAL_STDOUT.write(json.dumps(payload) + "\n")
    REAL_STDOUT.flush()


def fail(message):
    emit({"ok": False, "error": message})
    return 2


def main():
    if len(sys.argv) < 3:
        return fail("usage")

    pdf_path = sys.argv[1]
    out_dir = sys.argv[2]
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 15
    max_side = int(sys.argv[4]) if len(sys.argv) > 4 else 2200

    try:
        import pymupdf
    except Exception:
        try:
            import fitz as pymupdf
        except Exception:
            return fail("pymupdf_not_installed")

    try:
        pymupdf.TOOLS.mupdf_display_errors(False)
        pymupdf.TOOLS.mupdf_display_warnings(False)
    except Exception:
        pass

    try:
        document = pymupdf.open(pdf_path)
    except Exception as exc:
        return fail("open_failed: " + str(exc)[:300])

    pages = []
    first_error = ""
    try:
        for index, page in enumerate(document):
            if index >= max_pages:
                break
            try:
                rect = page.rect
                longest = max(float(rect.width), float(rect.height), 1.0)
                zoom = min(2.0, float(max_side) / longest)
                zoom = max(0.5, zoom)
                pixmap = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), alpha=False)
                filename = "page-%d.jpg" % (index + 1)
                path = os.path.join(out_dir, filename)
                pixmap.save(path, jpg_quality=90)
                pages.append({
                    "page": index + 1,
                    "file": filename,
                    "width": pixmap.width,
                    "height": pixmap.height,
                })
            except Exception as exc:
                # One damaged page must not discard the pages that did render.
                if not first_error:
                    first_error = "page %d: %s" % (index + 1, str(exc)[:200])
    finally:
        document.close()

    if not pages:
        return fail("nopages: " + first_error if first_error else "nopages")

    emit({"ok": True, "pages": pages})
    return 0


if __name__ == "__main__":
    sys.exit(main())
