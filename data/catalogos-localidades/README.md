# Catálogos oficiales de localidades

Descargados el 30/10/2026 desde organismos públicos. Los archivos originales están en `originales/`; `extraidos/` contiene las capas GeoPackage necesarias para inspeccionar Brasil y Uruguay.

| País | Fuente y versión | Archivo | Registros/cobertura comprobada |
|---|---|---|---:|
| Argentina | [GeoRef v2.0](https://www.argentina.gob.ar/georef/localidades-y-asentamientos) | `originales/argentina-georef-localidades-v2.csv` | 4.028 localidades; trae provincia, departamento, código y centroide |
| Brasil | [IBGE, Localidades do Brasil 2022](https://www.ibge.gov.br/geociencias/organizacao-do-territorio/estrutura-territorial/27385-localidades.html) | `originales/brasil-ibge-localidades-2022-gpkg.zip` | 96.163 filas por categoría; representan 87.362 códigos únicos de localidad, con UF, municipio, tipo y coordenadas |
| Chile | INE, [Ciudades, Pueblos y Aldeas Censos 1992–2017](https://www.ine.gob.cl/herramientas/portal-de-mapas/geodatos-abiertos) | `originales/chile-ine-ciudades-pueblos-aldeas-2017.geojson` | 3.894 puntos; ciudades, pueblos y aldeas, con comuna, región, categoría y coordenadas |
| Uruguay | INE, cartografía [Censo 2023](https://www.gub.uy/instituto-nacional-estadistica/politicas-y-gestion/unidades-geostadisticas) | `originales/uruguay-ine-cartografia-censo-2023.zip` | 652 localidades censales; incluye puntos y polígonos con códigos, departamento y coordenadas |
| Uruguay | INE, localidades censales 2023 | `originales/uruguay-ine-localidades-censales-2023.xlsx` | Tabla complementaria de nombres y códigos |

## Notas de uso

- Son catálogos oficiales con definiciones distintas; no representan exactamente el mismo tipo de asentamiento ni el mismo año de referencia.
- Para Argentina se descargó el recurso **localidades** de GeoRef (BAHRA), no el recurso más extenso de **asentamientos** que también contiene parajes y sitios.
- El archivo de Brasil conserva asentamientos de varias categorías; no se filtraron pueblos pequeños, comunidades indígenas ni quilombolas. El mismo código IBGE puede tener varias categorías, por lo que el importador debe consolidar esas filas en una sola localidad y conservar sus categorías juntas.
- La capa chilena disponible en esta descarga es Censo 2017. La capa de Uruguay corresponde a 2023.
- En Uruguay el GeoPackage es de unos 60 MB y la capa `loc_23_pt.gpkg` tiene los puntos de centro de cada localidad.
- Las coordenadas sirven como referencia de ubicación. No son distancia vial ni sustituyen un cálculo posterior de recorrido por OSM.
- Los paquetes originales son datos abiertos y se dejan sin modificar. `scripts/normalize_locality_catalogs.py` convierte cada país al formato normalizado del sistema y valida códigos, jerarquías, duplicados y coordenadas.

## Archivos normalizados para importar

Generados con `scripts/normalize_locality_catalogs.py`. Todos usan el encabezado `pais_codigo,fuente,codigo_provincia,provincia,codigo_localidad,localidad,contexto,categoria,latitud,longitud,version` y UTF-8.

| País | Localidades únicas | Archivo | Tamaño | SHA-256 |
|---|---:|---|---:|---|
| Argentina | 4.028 | `normalizados/argentina.csv` | 480.014 bytes | `b12cebf88793905bc461af7708d9b2564122dfeb762a04e65c932672b68a0a44` |
| Brasil | 87.362 | `normalizados/brasil.csv` | 12.191.795 bytes | `172ea5d0ec4a1037fa3a5cbce103b0d672d4c828487fd4a4405eb5a7ac8a7c19` |
| Chile | 3.894 | `normalizados/chile.csv` | 476.415 bytes | `99b07dcb8e8cd0dfa7e5536d42e440381a1d021a55ec2cf0572d74f683115179` |
| Uruguay | 652 | `normalizados/uruguay.csv` | 74.903 bytes | `5462f7c85c42b4d19298130623d2f5b519519bf48cb37c9967fc24996a111c2e` |

Las cuatro salidas pasaron controles de identidad única, campos obligatorios y rango de coordenadas.

También está disponible `normalizados/brasil.csv.gz` (2.175.838 bytes) para subir el catálogo de Brasil comprimido; la pantalla acepta `.csv` y `.csv.gz`. El hash guardado en auditoría corresponde al archivo que efectivamente se subió.

## Importar en el sistema

1. Entrá como Superadministrador a **Administración global → Localidades** (`superadmin/geografia/localidades/importar`).
2. Subí uno de los CSV normalizados de esta carpeta. Se admite `.csv` o `.csv.gz`, hasta 10 MB; para Brasil usá `brasil.csv.gz`.
3. Revisá país, fuente, versión, filas válidas y errores. Si hay errores, no se guarda ninguna fila: corregí el catálogo y volvé a subirlo.
4. Confirmá la importación. Argentina, Brasil, Chile y Uruguay quedan en un catálogo global compartido por todas las empresas.

La importación usa los códigos oficiales como identidad. Repetir el mismo archivo no crea duplicados; una actualización cambia los datos de la localidad conservando su ID y los viajes asociados. Las filas sin cambios quedan contadas en el historial de importaciones. No se eliminan localidades, no se modifican kilómetros reales y no se calcula distancia vial en esta etapa.

El formato de entrada es el encabezado fijo de los archivos normalizados. Para generar nuevamente los cuatro catálogos, ejecutá `python scripts/normalize_locality_catalogs.py`; antes de importar una versión nueva, revisá en esta guía su año de referencia y los conteos.

## SHA-256 de las fuentes descargadas

| Archivo original | SHA-256 |
|---|---|
| `originales/argentina-georef-localidades-v2.csv` | `7743fd6a6af96fce138696680afe297e71fc41f37f8d1986b3763913d0c86197` |
| `originales/brasil-ibge-localidades-2022-gpkg.zip` | `1d96d5f0b379972a506d4b18c3696af974fbe59f8f6680557cb3a6dd38a0eb57` |
| `originales/chile-ine-ciudades-pueblos-aldeas-2017.geojson` | `4632d50b85a56b1d192482f7ad29baaa9ef2d30467b3e226a47cfb13f469ed21` |
| `originales/uruguay-ine-cartografia-censo-2023.zip` | `7df176f6637425452636e89a6c4d04e2781debe27e012b9c3d3cc8f1322ec3e7` |
| `originales/uruguay-ine-localidades-censales-2023.xlsx` | `44262423348c966501d99d024cfb23eb07e01241b4a370b7d87f62b6a1ed3fc1` |
