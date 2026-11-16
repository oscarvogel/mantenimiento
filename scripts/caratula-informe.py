#!/usr/bin/env python3
"""Genera una caratula A4 con el logo de Vogel y la antepone a un PDF.

Uso:
    python scripts/caratula-informe.py \
        --input output/informe-tsa/INFORME.pdf \
        --output output/informe-tsa/INFORME-con-caratula.pdf \
        --titulo "Informe de mantenimiento" \
        --empresa "TSA" \
        --subtitulo "Mantenimientos realizados, problemas correctivos y planes preventivos" \
        --fecha "Corte al 16 de noviembre de 2026" \
        --web "vogelconsultoria.com.ar"
"""
from __future__ import annotations

import argparse
import pathlib
import tempfile

import numpy as np
from PIL import Image
from pypdf import PdfReader, PdfWriter
from reportlab.lib.colors import HexColor, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas

FONDO = HexColor("#031A3E")
AZUL = HexColor("#0863C6")
NARANJA = HexColor("#F2A93B")
CLARO = HexColor("#C9D3E4")
BLANCO_SUAVE = HexColor("#DCE3EE")
LINEA = HexColor("#1E3A66")


def recortar_logo(origen: pathlib.Path, destino: pathlib.Path) -> None:
    imagen = Image.open(origen).convert("RGB")
    pixeles = np.asarray(imagen).astype(int)
    fondo = np.array(imagen.getpixel((2, 2)))
    mascara = np.abs(pixeles - fondo).sum(axis=2) > 30
    ys, xs = np.where(mascara)
    if len(xs) == 0:
        raise RuntimeError("No se encontro contenido visible en el logo.")

    margen = 24
    caja = (
        max(0, int(xs.min()) - margen),
        max(0, int(ys.min()) - margen),
        min(imagen.width, int(xs.max()) + margen),
        min(imagen.height, int(ys.max()) + margen),
    )
    recorte = np.asarray(imagen.crop(caja)).astype(float)
    distancia = np.sqrt(((recorte - fondo) ** 2).sum(axis=2))
    alfa = np.clip((distancia - 12.0) / 20.0, 0.0, 1.0)
    alfa_seguro = np.maximum(alfa, 0.001)[..., None]
    frente = (recorte - (1.0 - alfa_seguro) * fondo) / alfa_seguro
    frente = np.where(alfa[..., None] < 0.02, fondo, np.clip(frente, 0, 255))
    rgba = np.dstack([frente, alfa * 255.0]).astype(np.uint8)
    Image.fromarray(rgba, mode="RGBA").save(destino)


def dibujar_caratula(
    destino: pathlib.Path,
    logo: pathlib.Path,
    titulo: str,
    empresa: str,
    subtitulo: str,
    fecha: str,
    web: str,
) -> None:
    ancho, alto = A4
    lienzo = canvas.Canvas(str(destino), pagesize=A4)

    lienzo.setFillColor(FONDO)
    lienzo.rect(0, 0, ancho, alto, fill=1, stroke=0)

    with Image.open(logo) as imagen:
        relacion = imagen.height / imagen.width

    ancho_logo = 132 * mm
    alto_logo = ancho_logo * relacion
    lienzo.drawImage(
        ImageReader(str(logo)),
        (ancho - ancho_logo) / 2,
        alto - 20 * mm - alto_logo,
        width=ancho_logo,
        height=alto_logo,
        mask="auto",
    )

    lienzo.setFillColor(white)
    lienzo.setFont("Helvetica-Bold", 24)
    lienzo.drawCentredString(ancho / 2, 164 * mm, titulo)

    lienzo.setFillColor(AZUL)
    lienzo.setFont("Helvetica-Bold", 46)
    lienzo.drawCentredString(ancho / 2, 143 * mm, empresa)

    lienzo.setFillColor(CLARO)
    lienzo.setFont("Helvetica", 11.5)
    lienzo.drawCentredString(ancho / 2, 124 * mm, subtitulo)

    lienzo.setStrokeColor(NARANJA)
    lienzo.setLineWidth(2)
    lienzo.line(ancho / 2 - 20 * mm, 111 * mm, ancho / 2 + 20 * mm, 111 * mm)

    lienzo.setFillColor(BLANCO_SUAVE)
    lienzo.setFont("Helvetica", 10.5)
    lienzo.drawCentredString(ancho / 2, 99 * mm, fecha)

    lienzo.setStrokeColor(LINEA)
    lienzo.setLineWidth(0.8)
    lienzo.line(25 * mm, 32 * mm, ancho - 25 * mm, 32 * mm)

    lienzo.setFillColor(white)
    lienzo.setFont("Helvetica-Bold", 12)
    lienzo.drawCentredString(ancho / 2, 21 * mm, web)

    lienzo.showPage()
    lienzo.save()


def unir(caratula: pathlib.Path, informe: pathlib.Path, destino: pathlib.Path) -> None:
    escritor = PdfWriter()
    escritor.add_page(PdfReader(str(caratula)).pages[0])
    for pagina in PdfReader(str(informe)).pages:
        escritor.add_page(pagina)
    with destino.open("wb") as salida:
        escritor.write(salida)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--logo", default="assets/brand/vogel-consultoria.png")
    parser.add_argument("--titulo", default="Informe de mantenimiento")
    parser.add_argument("--empresa", default="TSA")
    parser.add_argument("--subtitulo", default="Mantenimientos realizados, problemas correctivos y planes preventivos")
    parser.add_argument("--fecha", default="Corte al 16 de noviembre de 2026")
    parser.add_argument("--web", default="vogelconsultoria.com.ar")
    parser.add_argument("--conservar-pdf-original", action="store_true")
    args = parser.parse_args()

    informe = pathlib.Path(args.input).resolve()
    destino = pathlib.Path(args.output).resolve()
    if not informe.is_file():
        print("ERROR: no existe el PDF de entrada.")
        return 1

    destino.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="caratula-vogel-") as temporal:
        temporal = pathlib.Path(temporal)
        logo = temporal / "logo.png"
        recortar_logo(pathlib.Path(args.logo).resolve(), logo)
        caratula = temporal / "caratula.pdf"
        dibujar_caratula(caratula, logo, args.titulo, args.empresa, args.subtitulo, args.fecha, args.web)
        unir(caratula, informe, destino)

    print(f"PDF con caratula: {destino}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
