#!/usr/bin/env python3
"""Mueve el panel 'Relaciones entre equipos' de la pestana Mantenimiento a Resumen.

La ficha del equipo es donde se consulta y donde se cargan los datos; una
relacion tractor-acoplado no es un tema de mantenimiento preventivo, asi que
vivir en esa pestana la hacia invisible.
"""
from __future__ import annotations

import pathlib
import sys

RUTA = pathlib.Path(__file__).resolve().parents[1] / "frontend/src/pages/operations/EquipmentDetailPage.vue"


def main() -> int:
    crudo = RUTA.read_bytes()
    texto = crudo.decode("utf-8")
    separador = "\r\n" if "\r\n" in texto else "\n"
    lineas = texto.split(separador)

    inicio = next(
        (i for i, linea in enumerate(lineas) if '<PanelCard title="Relaciones entre equipos"' in linea),
        None,
    )
    if inicio is None:
        print("No se encontro el panel de relaciones.")
        return 1

    fin = next(
        (
            i
            for i in range(inicio, len(lineas))
            if "</PanelCard>" in lineas[i] and "data.relations.pagination" in lineas[i - 1]
        ),
        None,
    )
    if fin is None:
        print("No se pudo cerrar el bloque del panel de relaciones.")
        return 1

    bloque = lineas[inicio : fin + 1]
    del lineas[inicio : fin + 1]

    seccion = next(
        (i for i, linea in enumerate(lineas) if '<section v-if="data.can.edit" class="mb-6 grid gap-6' in linea),
        None,
    )
    if seccion is None:
        print("No se encontro la seccion de datos del equipo.")
        return 1

    cierre = next((i for i in range(seccion, len(lineas)) if lineas[i].strip() == "</section>"), None)
    if cierre is None:
        print("No se pudo cerrar la seccion de datos del equipo.")
        return 1

    lineas[cierre + 1 : cierre + 1] = [""] + bloque

    RUTA.write_bytes(separador.join(lineas).encode("utf-8"))
    print(f"MOVIDO {len(bloque)} lineas: panel de relaciones -> pestana Resumen")
    return 0


if __name__ == "__main__":
    sys.exit(main())
