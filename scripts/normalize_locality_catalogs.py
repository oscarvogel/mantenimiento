#!/usr/bin/env python3
"""Normalize the four downloaded official locality datasets into one CSV contract."""

from __future__ import annotations

import argparse
import csv
import json
import sqlite3
import tempfile
import unicodedata
import zipfile
from collections import defaultdict
from pathlib import Path
from typing import Any, Iterable


CSV_HEADER = [
    "pais_codigo",
    "fuente",
    "codigo_provincia",
    "provincia",
    "codigo_localidad",
    "localidad",
    "contexto",
    "categoria",
    "latitud",
    "longitud",
    "version",
]


def clean_text(value: Any) -> str:
    if value is None:
        return ""
    return unicodedata.normalize("NFC", str(value)).strip()


def parse_coordinate(value: Any, axis: str) -> str:
    text = clean_text(value)
    if not text:
        return ""
    try:
        number = float(text)
    except ValueError as exception:
        raise ValueError(f"Coordenada {axis} inválida: {text}") from exception

    low, high = (-90, 90) if axis == "latitude" else (-180, 180)
    if not low <= number <= high:
        raise ValueError(f"Coordenada {axis} fuera del rango {low}..{high}: {text}")
    return f"{number:.8f}".rstrip("0").rstrip(".")


def normalized_row(
    *,
    country: str,
    source: str,
    province_code: Any,
    province: Any,
    locality_code: Any,
    locality: Any,
    context: Any = "",
    category: Any = "",
    latitude: Any = "",
    longitude: Any = "",
    version: str,
) -> dict[str, str]:
    row = {
        "pais_codigo": clean_text(country).upper(),
        "fuente": clean_text(source).upper(),
        "codigo_provincia": clean_text(province_code),
        "provincia": clean_text(province),
        "codigo_localidad": clean_text(locality_code),
        "localidad": clean_text(locality),
        "contexto": clean_text(context),
        "categoria": clean_text(category),
        "latitud": parse_coordinate(latitude, "latitude"),
        "longitud": parse_coordinate(longitude, "longitude"),
        "version": clean_text(version),
    }
    required = ("pais_codigo", "fuente", "codigo_provincia", "provincia", "codigo_localidad", "localidad")
    missing = [field for field in required if not row[field]]
    if missing:
        raise ValueError("Faltan campos obligatorios: " + ", ".join(missing))
    return row


def normalize_brazil_records(records: Iterable[dict[str, Any]]) -> list[dict[str, str]]:
    grouped: dict[str, dict[str, Any]] = {}
    categories: dict[str, set[str]] = defaultdict(set)

    for record in records:
        code = clean_text(record.get("CD_LOCALIDADE"))
        if not code:
            raise ValueError("Brasil: falta CD_LOCALIDADE")
        description = clean_text(record.get("CT_LOCALIDADE"))
        subcategory = clean_text(record.get("SCT_LOCALIDADE"))
        if subcategory and subcategory.casefold() != description.casefold():
            description = f"{description} / {subcategory}" if description else subcategory
        if description:
            categories[code].add(description)

        values = {
            "CD_UF": clean_text(record.get("CD_UF")),
            "NM_UF": clean_text(record.get("NM_UF")),
            "NM_LOCALIDADE": clean_text(record.get("NM_LOCALIDADE")),
            "NM_MUN": clean_text(record.get("NM_MUN")),
            "LAT_LOCALIDADE": clean_text(record.get("LAT_LOCALIDADE")),
            "LONG_LOCALIDADE": clean_text(record.get("LONG_LOCALIDADE")),
        }
        previous = grouped.get(code)
        if previous is not None and previous != values:
            raise ValueError(f"Brasil: el código {code} tiene datos geográficos contradictorios")
        grouped[code] = values

    result = []
    for code, record in grouped.items():
        result.append(
            normalized_row(
                country="BRASIL",
                source="IBGE_LOCALIDADES_2022",
                province_code=record["CD_UF"],
                province=record["NM_UF"],
                locality_code=code,
                locality=record["NM_LOCALIDADE"],
                context=record["NM_MUN"],
                category=" | ".join(sorted(categories[code], key=str.casefold)),
                latitude=record["LAT_LOCALIDADE"],
                longitude=record["LONG_LOCALIDADE"],
                version="2022",
            )
        )
    return sorted(result, key=lambda row: (row["codigo_provincia"], row["localidad"].casefold(), row["codigo_localidad"]))


def normalize_argentina(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as source_file:
        rows = csv.DictReader(source_file)
        result = [
            normalized_row(
                country="ARGENTINA",
                source="GEOREF_V2",
                province_code=row["provincia_id"],
                province=row["provincia_nombre"],
                locality_code=row["id"],
                locality=row["nombre"],
                context=row["departamento_nombre"],
                category=row["categoria"],
                latitude=row["centroide_lat"],
                longitude=row["centroide_lon"],
                version="2026-04",
            )
            for row in rows
        ]
    return result


def normalize_brazil(path: Path) -> list[dict[str, str]]:
    with zipfile.ZipFile(path) as source_zip:
        candidates = [name for name in source_zip.namelist() if name.lower().endswith(".gpkg")]
        if len(candidates) != 1:
            raise ValueError("Brasil: se esperaba un GeoPackage en el ZIP del IBGE")
        with tempfile.TemporaryDirectory(prefix="ibge-localidades-") as temp_dir:
            gpkg = Path(temp_dir) / "brasil.gpkg"
            with source_zip.open(candidates[0]) as source_file, gpkg.open("wb") as target_file:
                target_file.write(source_file.read())
            connection = sqlite3.connect(gpkg)
            connection.row_factory = sqlite3.Row
            try:
                table = "BR_localidades_2022"
                records = [dict(row) for row in connection.execute(f'SELECT * FROM "{table}"')]
            finally:
                connection.close()
    return normalize_brazil_records(records)


def normalize_chile(path: Path) -> list[dict[str, str]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    result = []
    for feature in data.get("features", []):
        properties = feature.get("properties") or {}
        coordinates = (feature.get("geometry") or {}).get("coordinates") or []
        if len(coordinates) < 2:
            raise ValueError("Chile: la localidad no tiene coordenadas")
        object_id = clean_text(properties.get("OBJECTID"))
        if not object_id:
            raise ValueError("Chile: falta OBJECTID de la capa INE")
        result.append(
            normalized_row(
                country="CHILE",
                source="INE_CHILE_CENSO_2017",
                province_code=properties.get("REGION"),
                province=properties.get("NOM_REGION"),
                locality_code=object_id,
                locality=properties.get("NOMBRE"),
                context=properties.get("NOM_COMUNA"),
                category=properties.get("CATEGORIA"),
                longitude=coordinates[0],
                latitude=coordinates[1],
                version="2017",
            )
        )
    return sorted(result, key=lambda row: (row["codigo_provincia"], row["localidad"].casefold(), row["codigo_localidad"]))


def normalize_uruguay(path: Path) -> list[dict[str, str]]:
    with zipfile.ZipFile(path) as source_zip:
        try:
            entry_name = next(name for name in source_zip.namelist() if name.lower().endswith("loc_23_pt.gpkg"))
        except StopIteration as exception:
            raise ValueError("Uruguay: falta la capa de puntos loc_23_pt.gpkg") from exception
        with tempfile.TemporaryDirectory(prefix="ine-uruguay-localidades-") as temp_dir:
            gpkg = Path(temp_dir) / "uruguay.gpkg"
            with source_zip.open(entry_name) as source_file, gpkg.open("wb") as target_file:
                target_file.write(source_file.read())
            connection = sqlite3.connect(gpkg)
            connection.row_factory = sqlite3.Row
            try:
                records = [dict(row) for row in connection.execute('SELECT * FROM "loc_23_pt"')]
            finally:
                connection.close()

    result = [
        normalized_row(
            country="URUGUAY",
            source="INE_URUGUAY_CENSO_2023",
            province_code=record["DEPTO"],
            province=record["NOMDEPTO"],
            locality_code=record["CLOC_ISO"],
            locality=record["NOMLOC"],
            context="",
            category="LOCALIDAD CENSAL",
            latitude=record["Y_CEN"],
            longitude=record["X_CEN"],
            version="2023",
        )
        for record in records
    ]
    return sorted(result, key=lambda row: (row["codigo_provincia"], row["localidad"].casefold(), row["codigo_localidad"]))


def validate_catalog(rows: list[dict[str, str]], country_code: str) -> None:
    seen: set[tuple[str, str]] = set()
    for line_number, row in enumerate(rows, start=2):
        if row["pais_codigo"] != country_code:
            raise ValueError(f"Fila {line_number}: país inesperado {row['pais_codigo']}")
        source_identity = (row["fuente"], row["codigo_localidad"])
        if source_identity in seen:
            raise ValueError(f"Fila {line_number}: código de localidad duplicado {source_identity[1]}")
        seen.add(source_identity)
        if not row["localidad"] or not row["provincia"]:
            raise ValueError(f"Fila {line_number}: localidad o provincia vacía")


def write_catalog(path: Path, rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as output_file:
        writer = csv.DictWriter(output_file, fieldnames=CSV_HEADER, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def normalize_catalogs(source_dir: Path, output_dir: Path) -> dict[str, int]:
    catalogs = {
        "argentina": ("ARGENTINA", normalize_argentina(source_dir / "argentina-georef-localidades-v2.csv")),
        "brasil": ("BRASIL", normalize_brazil(source_dir / "brasil-ibge-localidades-2022-gpkg.zip")),
        "chile": ("CHILE", normalize_chile(source_dir / "chile-ine-ciudades-pueblos-aldeas-2017.geojson")),
        "uruguay": ("URUGUAY", normalize_uruguay(source_dir / "uruguay-ine-cartografia-censo-2023.zip")),
    }
    counts = {}
    for country, (country_code, rows) in catalogs.items():
        validate_catalog(rows, country_code)
        write_catalog(output_dir / f"{country}.csv", rows)
        counts[country] = len(rows)
    return counts


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-dir", type=Path, default=Path("data/catalogos-localidades/originales"))
    parser.add_argument("--output-dir", type=Path, default=Path("data/catalogos-localidades/normalizados"))
    args = parser.parse_args()
    counts = normalize_catalogs(args.source_dir, args.output_dir)
    for country, count in counts.items():
        print(f"{country}: {count:,} localidades")


if __name__ == "__main__":
    main()
