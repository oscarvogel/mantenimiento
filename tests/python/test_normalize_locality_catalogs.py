import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))

from normalize_locality_catalogs import (  # noqa: E402
    CSV_HEADER,
    normalize_brazil_records,
    normalized_row,
    parse_coordinate,
    validate_catalog,
)


class NormalizeLocalityCatalogsTest(unittest.TestCase):
    def test_brazil_categories_with_same_ibge_code_become_one_locality(self):
        source_rows = [
            {
                "CD_UF": "11",
                "NM_UF": "Rondônia",
                "CD_LOCALIDADE": "110001500007",
                "NM_LOCALIDADE": "Aldeia Exemplo",
                "NM_MUN": "Alta Floresta D'Oeste",
                "CT_LOCALIDADE": "Povoado",
                "SCT_LOCALIDADE": "",
                "LAT_LOCALIDADE": "-11.98",
                "LONG_LOCALIDADE": "-61.98",
            },
            {
                "CD_UF": "11",
                "NM_UF": "Rondônia",
                "CD_LOCALIDADE": "110001500007",
                "NM_LOCALIDADE": "Aldeia Exemplo",
                "NM_MUN": "Alta Floresta D'Oeste",
                "CT_LOCALIDADE": "Localidade Indígena",
                "SCT_LOCALIDADE": "Agrupamento Indígena",
                "LAT_LOCALIDADE": "-11.98",
                "LONG_LOCALIDADE": "-61.98",
            },
        ]

        result = normalize_brazil_records(source_rows)

        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]["codigo_localidad"], "110001500007")
        self.assertEqual(
            result[0]["categoria"],
            "Localidade Indígena / Agrupamento Indígena | Povoado",
        )
        self.assertEqual(result[0]["contexto"], "Alta Floresta D'Oeste")

    def test_coordinate_outside_valid_range_is_rejected(self):
        with self.assertRaises(ValueError):
            parse_coordinate("-91", "latitude")

    def test_normalized_csv_has_the_shared_import_contract(self):
        self.assertEqual(
            CSV_HEADER,
            [
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
            ],
        )

    def test_normalized_rows_require_a_global_source_code(self):
        with self.assertRaisesRegex(ValueError, "codigo_localidad"):
            normalized_row(
                country="URUGUAY",
                source="INE_URUGUAY_CENSO_2023",
                province_code="01",
                province="Montevideo",
                locality_code="",
                locality="Montevideo",
                version="2023",
            )

    def test_catalog_rejects_duplicate_codes_from_the_same_source(self):
        row = normalized_row(
            country="CHILE",
            source="INE_CHILE_CENSO_2017",
            province_code="1",
            province="Tarapacá",
            locality_code="3597",
            locality="Iquique",
            version="2017",
        )
        with self.assertRaisesRegex(ValueError, "código de localidad duplicado"):
            validate_catalog([row, row], "CHILE")


if __name__ == "__main__":
    unittest.main()
