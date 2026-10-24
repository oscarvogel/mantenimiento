#!/usr/bin/env python3
"""Genera un PDF legible a partir de un Markdown (reportlab / Platypus).

Uso:
    python scripts/md2pdf.py <entrada.md> <salida.pdf> [encabezado]

El encabezado opcional es el texto que va en el pie de cada pagina; por
defecto se usa el nombre del archivo de entrada.

Soporta: titulo y metadatos de portada, encabezados h2-h4, parrafos, listas,
tablas con ancho proporcional, citas, bloques de codigo, separadores y
marcado en linea (**negrita**, *cursiva*, `codigo`).
"""

import os
import re
import sys
from xml.sax.saxutils import escape

from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import cm
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    KeepTogether,
    PageBreak,
    PageTemplate,
    Paragraph,
    Preformatted,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.platypus.tableofcontents import TableOfContents

TINTA = colors.HexColor("#1B2A41")
TINTA_SUAVE = colors.HexColor("#4A5B72")
ACENTO = colors.HexColor("#1F4E79")
LINEA = colors.HexColor("#C7CEDB")
FONDO = colors.HexColor("#F4F6F9")
FONDO_ALT = colors.HexColor("#FAFBFC")
CODIGO_FONDO = colors.HexColor("#F2F3F5")

ANCHO_UTIL = A4[0] - 4 * cm


# --------------------------------------------------------------------------- #
# Markdown -> bloques
# --------------------------------------------------------------------------- #

def leer_bloques(lineas):
    """Devuelve una lista de (tipo, payload)."""
    bloques = []
    i = 0
    n = len(lineas)
    while i < n:
        linea = lineas[i]
        vacia = not linea.strip()

        if vacia:
            i += 1
            continue

        # Bloque de codigo
        if linea.strip().startswith("```"):
            i += 1
            codigo = []
            while i < n and not lineas[i].strip().startswith("```"):
                codigo.append(lineas[i])
                i += 1
            i += 1
            bloques.append(("codigo", "\n".join(codigo)))
            continue

        # Separador
        if re.fullmatch(r"\s*(-{3,}|\*{3,}|_{3,})\s*", linea):
            bloques.append(("separador", None))
            i += 1
            continue

        # Encabezado
        m = re.match(r"^(#{1,6})\s+(.*)$", linea)
        if m:
            bloques.append(("h%d" % len(m.group(1)), m.group(2).strip()))
            i += 1
            continue

        # Tabla
        if linea.strip().startswith("|") and i + 1 < n and re.match(
            r"^\s*\|[\s:|-]+\|\s*$", lineas[i + 1]
        ):
            cabecera = _celdas(linea)
            i += 2
            filas = []
            while i < n and lineas[i].strip().startswith("|"):
                filas.append(_celdas(lineas[i]))
                i += 1
            bloques.append(("tabla", (cabecera, filas)))
            continue

        # Cita
        if linea.lstrip().startswith(">"):
            partes = []
            while i < n and lineas[i].lstrip().startswith(">"):
                partes.append(re.sub(r"^\s*>\s?", "", lineas[i]).rstrip())
                i += 1
            bloques.append(("cita", "\n".join(partes)))
            continue

        # Lista
        if re.match(r"^\s*([-*+]|\d+[.)])\s+", linea):
            items = []
            while i < n and re.match(r"^\s*([-*+]|\d+[.)])\s+", lineas[i]):
                items.append(re.sub(r"^\s*([-*+]|\d+[.)])\s+", "", lineas[i]).rstrip())
                i += 1
            bloques.append(("lista", items))
            continue

        # Parrafo
        parrafo = []
        while i < n and lineas[i].strip() and not re.match(
            r"^\s*(#{1,6}\s|```|>)", lineas[i]
        ) and not re.match(r"^\s*([-*+]|\d+[.)])\s+", lineas[i]) and not es_tabla(lineas, i):
            parrafo.append(lineas[i].strip())
            i += 1
        if not parrafo:
            # Red de seguridad: una linea que no encaja en ninguna regla no
            # puede dejar el cursor quieto (bucle infinito).
            parrafo.append(lineas[i].strip())
            i += 1
        bloques.append(("parrafo", " ".join(parrafo)))

    return bloques


def _celdas(linea):
    partes = linea.strip().strip("|").split("|")
    return [p.strip() for p in partes]


def es_tabla(lineas, i):
    """True si en la posicion i arranca una tabla de Markdown."""
    n = len(lineas)
    if not lineas[i].strip().startswith("|"):
        return False
    return i + 1 < n and bool(re.match(r"^\s*\|[\s:|-]+\|\s*$", lineas[i + 1]))


# --------------------------------------------------------------------------- #
# Marcado en linea
# --------------------------------------------------------------------------- #

def en_linea(texto):
    t = escape(texto)
    t = re.sub(
        r"`([^`]+)`",
        r'<font face="Courier" size="8.4" color="#1F4E79">\1</font>',
        t,
    )
    t = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", t, flags=re.S)
    t = re.sub(r"(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])", r"<i>\1</i>", t)
    t = re.sub(
        r"\[([^\]]+)\]\(([^)]+)\)",
        r'<font color="#1F4E79">\1</font>',
        t,
    )
    return t


# --------------------------------------------------------------------------- #
# Documento
# --------------------------------------------------------------------------- #

class Documento(BaseDocTemplate):
    def __init__(self, ruta, **kwargs):
        BaseDocTemplate.__init__(self, ruta, **kwargs)
        self.titulo_doc = ""
        self._seq = 0

    def beforeDocument(self):
        # multiBuild reejecuta el documento varias veces: el contador de anclas
        # debe reiniciarse en cada pasada para que el indice converja.
        self._seq = 0

    def afterFlowable(self, flowable):
        if isinstance(flowable, Paragraph):
            if flowable.style.name == "H2":
                self._seq += 1
                clave = "sec-%d" % self._seq
                self.canv.bookmarkPage(clave)
                self.canv.addOutlineEntry(flowable.getPlainText(), clave, 0, 0)
                self.notify("TOCEntry", (0, flowable.getPlainText(), self.page, clave))


def construir_estilos():
    ss = getSampleStyleSheet()
    estilos = {}

    estilos["cuerpo"] = ParagraphStyle(
        "cuerpo",
        parent=ss["Normal"],
        fontName="Helvetica",
        fontSize=9.6,
        leading=13.6,
        alignment=TA_LEFT,
        spaceAfter=6,
        textColor=colors.HexColor("#1F2430"),
    )
    estilos["H2"] = ParagraphStyle(
        "H2",
        parent=ss["Normal"],
        fontName="Helvetica-Bold",
        fontSize=15,
        leading=18,
        textColor=ACENTO,
        spaceBefore=16,
        spaceAfter=7,
    )
    estilos["H2SinIndice"] = ParagraphStyle(
        "H2SinIndice",
        parent=estilos["H2"],
    )
    estilos["H3"] = ParagraphStyle(
        "H3",
        parent=ss["Normal"],
        fontName="Helvetica-Bold",
        fontSize=11.4,
        leading=14,
        textColor=TINTA,
        spaceBefore=11,
        spaceAfter=4,
    )
    estilos["H4"] = ParagraphStyle(
        "H4",
        parent=ss["Normal"],
        fontName="Helvetica-BoldOblique",
        fontSize=10,
        leading=13,
        textColor=TINTA_SUAVE,
        spaceBefore=9,
        spaceAfter=3,
    )
    estilos["meta"] = ParagraphStyle(
        "meta",
        parent=estilos["cuerpo"],
        fontSize=9.4,
        leading=14,
        spaceAfter=2,
    )
    estilos["portada_titulo"] = ParagraphStyle(
        "portada_titulo",
        parent=ss["Normal"],
        fontName="Helvetica-Bold",
        fontSize=23,
        leading=28,
        textColor=TINTA,
        spaceAfter=8,
    )
    estilos["portada_sub"] = ParagraphStyle(
        "portada_sub",
        parent=ss["Normal"],
        fontName="Helvetica",
        fontSize=11.5,
        leading=16,
        textColor=TINTA_SUAVE,
        spaceAfter=22,
    )
    estilos["celda"] = ParagraphStyle(
        "celda",
        parent=ss["Normal"],
        fontName="Helvetica",
        fontSize=8.3,
        leading=11,
        textColor=colors.HexColor("#1F2430"),
    )
    estilos["celda_cab"] = ParagraphStyle(
        "celda_cab",
        parent=estilos["celda"],
        fontName="Helvetica-Bold",
        fontSize=8.4,
        leading=11,
        textColor=colors.white,
    )
    # El fondo y la barra lateral los aporta la tabla de una celda que envuelve
    # cada trozo de cita, no el Paragraph: asi el fondo nunca pisa la ultima
    # linea del bloque anterior.
    estilos["cita"] = ParagraphStyle(
        "cita",
        parent=estilos["cuerpo"],
        fontName="Helvetica-Oblique",
        fontSize=9.4,
        leading=13.4,
        leftIndent=4,
        rightIndent=2,
        textColor=colors.HexColor("#39424F"),
        spaceAfter=0,
        spaceBefore=0,
        borderWidth=0,
    )
    estilos["item"] = ParagraphStyle(
        "item",
        parent=estilos["cuerpo"],
        leftIndent=13,
        bulletIndent=3,
        spaceAfter=3,
    )
    estilos["codigo"] = ParagraphStyle(
        "codigo",
        parent=ss["Normal"],
        fontName="Courier",
        fontSize=8.2,
        leading=10.6,
        textColor=colors.HexColor("#20293A"),
    )
    estilos["toc1"] = ParagraphStyle(
        "toc1",
        parent=ss["Normal"],
        fontName="Helvetica",
        fontSize=10,
        leading=16,
        textColor=colors.HexColor("#1F2430"),
        leftIndent=2,
        firstLineIndent=0,
        spaceBefore=2,
    )
    return estilos


MAX_TROZO_CITA = 1400


def trocear_cita(texto, maximo=MAX_TROZO_CITA):
    """Divide una cita en trozos manejables, respetando parrafos.

    Un unico Paragraph con miles de caracteres hace que reportlab demore
    una eternidad al partirlo entre paginas y que multiBuild no converja.
    Troceando por parrafo, cada bloque entra holgado en una pagina.
    """
    parrafos = [p.strip() for p in re.split(r"\n\s*\n", texto.strip()) if p.strip()]
    trozos = []
    actual = ""
    for parrafo in parrafos:
        # Un parrafo todavia mas largo se parte por lineas.
        for linea in parrafo.split("\n"):
            while len(linea) > maximo:
                if actual:
                    trozos.append(actual)
                    actual = ""
                trozos.append(linea[:maximo])
                linea = linea[maximo:]
            if not actual:
                actual = linea
                continue
            if len(actual) + 2 + len(linea) > maximo:
                trozos.append(actual)
                actual = linea
            else:
                actual = actual + "\n" + linea
        # Se cierra el parrafo: el siguiente arranca en un bloque aparte.
        if actual:
            trozos.append(actual)
            actual = ""
    if actual:
        trozos.append(actual)
    return trozos or [texto]


def celda_cita(parrafo):
    """Envuelve un trozo de cita en una tabla de una celda con fondo."""
    tabla = Table([[parrafo]], colWidths=[ANCHO_UTIL], hAlign="LEFT")
    tabla.setStyle(
        TableStyle(
            [
                ("BACKGROUND", (0, 0), (-1, -1), FONDO),
                ("LINEBEFORE", (0, 0), (0, -1), 2, ACENTO),
                ("LEFTPADDING", (0, 0), (-1, -1), 9),
                ("RIGHTPADDING", (0, 0), (-1, -1), 6),
                ("TOPPADDING", (0, 0), (-1, -1), 6),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ]
        )
    )
    return tabla


def largo_texto(flujo):
    """Cantidad de caracteres de un flujo, sea Paragraph o tabla de una celda."""
    if hasattr(flujo, "getPlainText"):
        return len(flujo.getPlainText())
    if isinstance(flujo, Table) and flujo._cellvalues:
        celdas = flujo._cellvalues[0]
        if celdas and hasattr(celdas[0], "getPlainText"):
            return len(celdas[0].getPlainText())
    return 9999


def anchos_columnas(cabecera, filas, ancho_total):
    n = len(cabecera)
    pesos = []
    for c in range(n):
        largos = [len(cabecera[c])]
        for f in filas:
            if c < len(f):
                largos.append(len(f[c]))
        # raiz para que una columna larga no se apropie de todo
        pesos.append(max(4.0, min(90.0, max(largos) ** 0.55)))
    suma = sum(pesos)
    anchos = [ancho_total * p / suma for p in pesos]
    minimo = 2.15 * cm
    deficit = sum(max(0.0, minimo - a) for a in anchos)
    if deficit > 0:
        anchos = [a + deficit * (a / suma) for a in anchos]
        sobrante = sum(anchos) - ancho_total
        if sobrante > 0:
            anchos = [a - sobrante * (a / sum(anchos)) for a in anchos]
    return anchos


def construir_story(bloques, estilos, titulo, subtitulo, meta):
    story = []

    # --- Portada -----------------------------------------------------------
    story.append(Spacer(1, 3.4 * cm))
    story.append(Paragraph(en_linea(titulo), estilos["portada_titulo"]))
    story.append(
        Table(
            [[""]],
            colWidths=[3.2 * cm],
            rowHeights=[2.4],
            style=TableStyle(
                [("BACKGROUND", (0, 0), (-1, -1), ACENTO), ("LINEBELOW", (0, 0), (-1, -1), 0, ACENTO)]
            ),
        )
    )
    story.append(Spacer(1, 0.5 * cm))
    if subtitulo:
        story.append(Paragraph(en_linea(subtitulo), estilos["portada_sub"]))
    else:
        story.append(Spacer(1, 0.4 * cm))
    for clave, valor in meta:
        story.append(
            Paragraph(
                '<b>%s</b> %s' % (escape(clave), en_linea(valor)),
                estilos["meta"],
            )
        )
    story.append(Spacer(1, 1.2 * cm))

    story.append(Paragraph("Contenido", estilos["H2SinIndice"]))
    toc = TableOfContents()
    toc.levelStyles = [estilos["toc1"]]
    story.append(toc)
    story.append(PageBreak())

    # --- Cuerpo ------------------------------------------------------------
    # Se arman primero los flowables con su etiqueta para poder emparejar un
    # encabezado con el bloque que sigue sin dejar titulos huerfanos.
    items = []

    for tipo, datos in bloques:
        if tipo == "h1":
            continue
        if tipo == "h2":
            items.append(("encabezado", Paragraph(en_linea(datos), estilos["H2"])))
        elif tipo == "h3":
            items.append(("encabezado", Paragraph(en_linea(datos), estilos["H3"])))
        elif tipo == "h4":
            items.append(("encabezado", Paragraph(en_linea(datos), estilos["H4"])))
        elif tipo == "parrafo":
            items.append(("texto", Paragraph(en_linea(datos), estilos["cuerpo"])))
        elif tipo == "separador":
            items.append(
                (
                    "otro",
                    Table(
                        [[""]],
                        colWidths=[ANCHO_UTIL],
                        rowHeights=[1],
                        style=TableStyle(
                            [
                                ("BACKGROUND", (0, 0), (-1, -1), LINEA),
                                ("LINEBELOW", (0, 0), (-1, -1), 0, LINEA),
                            ]
                        ),
                    ),
                )
            )
            items.append(("otro", Spacer(1, 5)))
        elif tipo == "cita":
            # Se unen las lineas con salto real para que el marcado en linea
            # pueda cruzar de una linea a otra; recien despues el salto se
            # convierte en <br/>.
            #
            # Los bloques largos se trocean por parrafo: un unico Paragraph
            # gigante hace que reportlab demore al partirlo y que multiBuild
            # no converja. Cada trozo se pinta como tabla de una celda, con
            # fondo y barra lateral propios.
            trozos = trocear_cita(datos)
            for indice, trozo in enumerate(trozos):
                texto = en_linea(trozo).replace("\n", "<br/>")
                items.append(("cita", celda_cita(Paragraph(texto, estilos["cita"]))))
                if indice < len(trozos) - 1:
                    items.append(("otro", Spacer(1, 0)))
            items.append(("otro", Spacer(1, 7)))
        elif tipo == "lista":
            for item in datos:
                items.append(
                    ("texto", Paragraph(en_linea(item), estilos["item"], bulletText="\u2022"))
                )
            items.append(("otro", Spacer(1, 3)))
        elif tipo == "codigo":
            lineas_codigo = datos.split("\n")
            for indice in range(0, len(lineas_codigo), 22):
                trozo = "\n".join(lineas_codigo[indice : indice + 22])
                pre = Preformatted(trozo, estilos["codigo"])
                tabla = Table([[pre]], colWidths=[ANCHO_UTIL])
                tabla.setStyle(
                    TableStyle(
                        [
                            ("BACKGROUND", (0, 0), (-1, -1), CODIGO_FONDO),
                            ("BOX", (0, 0), (-1, -1), 0.5, LINEA),
                            ("LEFTPADDING", (0, 0), (-1, -1), 8),
                            ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                            ("TOPPADDING", (0, 0), (-1, -1), 6),
                            ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                        ]
                    )
                )
                items.append(("otro", tabla))
                items.append(("otro", Spacer(1, 4)))
            items.append(("otro", Spacer(1, 4)))
        elif tipo == "tabla":
            cabecera, filas = datos
            n = len(cabecera)
            filas = [f + [""] * (n - len(f)) for f in filas]
            cab = [Paragraph(en_linea(c), estilos["celda_cab"]) for c in cabecera]
            cuerpo = [[Paragraph(en_linea(c), estilos["celda"]) for c in f] for f in filas]
            datos_tabla = [cab] + cuerpo
            anchos = anchos_columnas(cabecera, filas, ANCHO_UTIL)
            tabla = Table(datos_tabla, colWidths=anchos, repeatRows=1, hAlign="LEFT")
            estilo = [
                ("BACKGROUND", (0, 0), (-1, 0), ACENTO),
                ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
                ("GRID", (0, 0), (-1, -1), 0.4, LINEA),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 5),
                ("RIGHTPADDING", (0, 0), (-1, -1), 5),
                ("TOPPADDING", (0, 0), (-1, -1), 4),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
            ]
            for r in range(1, len(datos_tabla)):
                if r % 2 == 0:
                    estilo.append(("BACKGROUND", (0, r), (-1, r), FONDO_ALT))
            tabla.setStyle(TableStyle(estilo))
            items.append(("tabla", tabla))
            items.append(("otro", Spacer(1, 9)))

    # Emparejar encabezado con el bloque siguiente. Sin esto un titulo puede
    # quedar solo al pie de una pagina.
    indice = 0
    while indice < len(items):
        etiqueta, flujo = items[indice]
        if etiqueta == "encabezado" and indice + 1 < len(items):
            etiqueta2, flujo2 = items[indice + 1]
            if etiqueta2 in ("texto", "cita"):
                largo = largo_texto(flujo2)
                if largo <= 340:
                    items[indice] = ("otro", KeepTogether([flujo, flujo2]))
                    del items[indice + 1]
                    continue
        indice += 1

    for _, flujo in items:
        story.append(flujo)
    return story


def main():
    if len(sys.argv) not in (3, 4):
        print(__doc__)
        return 2

    origen, destino = sys.argv[1], sys.argv[2]
    encabezado = sys.argv[3] if len(sys.argv) == 4 else os.path.basename(origen)
    with open(origen, "r", encoding="utf-8") as fh:
        lineas = fh.read().split("\n")

    # Separar portada (hasta el primer "## ") del cuerpo.
    corte = None
    for i, l in enumerate(lineas):
        if l.startswith("## "):
            corte = i
            break
    if corte is None:
        corte = len(lineas)

    cabecera = lineas[:corte]
    cuerpo = lineas[corte:]

    titulo = ""
    subtitulo = ""
    meta = []
    clave_actual = None
    for l in cabecera:
        texto = l.strip()
        if not texto:
            continue
        if re.fullmatch(r"(-{3,}|\*{3,}|_{3,})", texto):
            continue
        if texto.startswith("# "):
            titulo = texto[2:].strip()
            continue
        m = re.match(r"^\*\*(.+?)\*\*\s*(.*)$", texto)
        if m:
            # Una entrada de metadatos puede seguir en la linea siguiente.
            clave_actual = [m.group(1), m.group(2)]
            meta.append(clave_actual)
            continue
        if clave_actual is not None:
            clave_actual[1] = (clave_actual[1] + " " + texto).strip()
            continue
        if not subtitulo:
            subtitulo = texto

    estilos = construir_estilos()
    story = construir_story(leer_bloques(cuerpo), estilos, titulo, subtitulo, meta)

    doc = Documento(
        destino,
        pagesize=A4,
        leftMargin=2 * cm,
        rightMargin=2 * cm,
        topMargin=1.9 * cm,
        bottomMargin=1.8 * cm,
        title=titulo,
        author="Vogel Consultoria",
        subject=subtitulo,
    )

    marco = Frame(
        doc.leftMargin,
        doc.bottomMargin,
        ANCHO_UTIL,
        A4[1] - doc.topMargin - doc.bottomMargin,
        id="principal",
        leftPadding=0,
        rightPadding=0,
        topPadding=0,
        bottomPadding=0,
    )

    def pintar(canvas, documento):
        canvas.saveState()
        ancho, alto = A4
        # Pie
        canvas.setStrokeColor(LINEA)
        canvas.setLineWidth(0.5)
        canvas.line(2 * cm, 1.35 * cm, ancho - 2 * cm, 1.35 * cm)
        canvas.setFont("Helvetica", 7.6)
        canvas.setFillColor(TINTA_SUAVE)
        canvas.drawString(2 * cm, 0.95 * cm, titulo[:96])
        canvas.drawRightString(ancho - 2 * cm, 0.95 * cm, "Página %d" % canvas.getPageNumber())
        # Encabezado desde la pagina 2
        if canvas.getPageNumber() > 1:
            canvas.setFont("Helvetica", 7.6)
            canvas.setFillColor(TINTA_SUAVE)
            canvas.drawRightString(ancho - 2 * cm, alto - 1.25 * cm, encabezado)
            canvas.setStrokeColor(LINEA)
            canvas.line(2 * cm, alto - 1.45 * cm, ancho - 2 * cm, alto - 1.45 * cm)
        canvas.restoreState()

    doc.addPageTemplates(
        [PageTemplate(id="todos", frames=[marco], onPage=pintar)]
    )
    doc.multiBuild(story)
    print("PDF generado: %s" % destino)
    return 0


if __name__ == "__main__":
    sys.exit(main())
