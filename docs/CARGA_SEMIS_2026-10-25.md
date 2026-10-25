# Carga de los semirremolques de TSA (SEMI.docx → Importaciones → Equipos)

**Fecha:** 25 de octubre de 2026
**Origen:** `docs/SEMI.docx` (27 unidades), `docs/PLAN_SEMIS_ACOPLADOS_Y_EQUIPOS_FRIGORIFICOS.md` (v2, 8/10/2026)
**Alcance:** datos y archivos de carga. No hay cambios en el código de la aplicación.
**Contexto afectado:** Registro de activos (dueño) e Importaciones (carga).

---

## 1. Resumen

El sistema ya tiene el camino de carga: el módulo **Importaciones** importa equipos desde
CSV o XLSX con el tipo `EQUIPOS`. Se prepararon los dos CSV con las 27 unidades del
documento, un generador que los produce desde el texto del documento, y pruebas que
verifican que los archivos pasan el validador real del importador.

El "Sin semi" que aparece en el formulario de viaje no es un error del combo: **no hay
ningún semirremolque cargado en el sistema**. El combo "Acoplado o semirremolque" arma su
lista con los equipos cuyo tipo de equipo tiene nombre de semirremolque, y hoy no hay
ninguno.

---

## 2. Cómo detecta el sistema que es un semirremolque y no un tractor

La regla vive en el **nombre del tipo de equipo** (`tipos_equipo.nombre`), no en un campo
propio. Hoy está escrita en dos adaptadores, con el mismo criterio:

| Criterio | Patrón | Dónde |
|---|---|---|
| Es semirremolque | `/acoplado\|semirremolque\|remolque\|batea/` | `app/Infrastructure/Assets/CodeIgniterEquipmentFleetLookup.php:83` |
| Es tractor/camión | `/camion\|tractor\|chasis\|cabina\|utilitario\|pickup/` | `app/Infrastructure/Assets/CodeIgniterEquipmentFleetLookup.php:88` |
| Alimenta el combo del viaje | mismos dos patrones | `app/Infrastructure/Trips/CodeIgniterTripFormReadModel.php:82-87` |

Consecuencias que hay que tener presentes:

1. **Si el tipo no dice "semirremolque" (ni acoplado, remolque o batea), la unidad no
   aparece en el combo de semirremolques.** Por eso el tipo cargado es `Semirremolque`
   para las 27 unidades, incluso las 13 carretas que el documento llama "CARRETA". Un tipo
   llamado "Carreta" las dejaría invisibles en los dos combos.
2. **La regla es frágil**: es una regla de negocio escrita sobre texto libre. Renombrar
   el tipo (por ejemplo "Semirremolque" → "Remolque sider") cambia el comportamiento en
   silencio. El propio código lo warns en el docblock y señala la forma definitiva: un
   atributo explícito en `tipos_equipo` (por ejemplo `rol` con valores `TRACTOR` /
   `SEMIRREMOLQUE` / `MAQUINA`) con su carga de datos y su pantalla de catálogo.
   Queda como pendiente (§8).
3. La regla compara sin acentos ni mayúsculas: "Semirremolque", "SEMIRREMOLQUE" y
   "semirremolque" se comportan igual.

---

## 3. Qué se entregó

| Archivo | Qué es |
|---|---|
| `scripts/generar-csv-semis.php` | Genera los CSV **parseando** el texto de `docs/SEMI.docx` (no transcrito a mano). Toma los encabezados del propio importador (`ImportType::EQUIPOS->templateHeaders()`), así que si el contrato de importación cambia, el script falla en vez de producir un archivo rechazable. |
| `docs/carga_semis/semis_argentina.csv` | 14 semirremolques → sucursal `TSAARG` |
| `docs/carga_semis/semis_brasil.csv` | 13 carretas → sucursal `TSABR` |
| `tests/unit/Application/Importations/SemisEquipmentImportFileTest.php` | 9 pruebas: encabezados, validación fila por fila con el validador real, clasificación tractor/semirremolque contra el código de producción, inventario, duplicados y el riesgo de acceso por sucursal. |

Regenerar los CSV (por ejemplo si cambia el documento o la fecha de alta):

```bash
php scripts/generar-csv-semis.php
php scripts/generar-csv-semis.php --fecha-alta=2026-10-25 --tipo=Semirremolque
```

El generador **falla con el detalle de la fila que no pudo interpretar** si el documento
cambia de formato: es preferible a generar un archivo incompleto.

---

## 4. Cómo se carga (en la aplicación)

1. **Previo: crear las 3 marcas.** En *Maestros → Equipos* crear las marcas `Vulcano`,
   `Randon` y `Facchini`. Sin ellas, la importación rechaza las 27 filas con
   "La marca no existe o esta inactiva en la empresa".
2. **Importaciones → Equipos**: subir `docs/carga_semis/semis_argentina.csv`.
3. Revisar la previsualización: las 14 filas deben salir como válidas.
4. Confirmar.
5. Repetir con `docs/carga_semis/semis_brasil.csv` (13 filas).

Permiso necesario: `importaciones.cargar`.

---

## 5. Requisitos que hay que verificar antes de subir

| # | Requisito | Cómo se verifica | Si falta |
|---|---|---|---|
| 1 | Tipo de equipo activo llamado exactamente `Semirremolque` | *Maestros → Equipos* (lista de tipos) | El importador rechaza las filas ("El tipo de equipo no existe o esta inactivo"). Los tipos de equipo **no se crean desde la pantalla**: solo por seeder o migración. Si en la base el tipo se llama distinto (por ejemplo `Acoplado / Semirremolque`), hay que regenerar el CSV con `--tipo="<nombre exacto>"`. |
| 2 | Marcas `Vulcano`, `Randon`, `Facchini` activas en la empresa | *Maestros → Equipos* | Crear desde la pantalla (2 minutos). |
| 3 | El usuario tiene acceso a `TSAARG` **y** a `TSABR` | Administración de usuarios → sucursales | Si el usuario sólo tiene TSA Argentina, las 13 filas de Brasil salen con error "La sucursal no esta autorizada para el actor". Es el riesgo R1 del plan. |
| 4 | No hay equipos cargados con las mismas patentes | Previsualización de la importación | Sale como advertencia, no como error. |
| 5 | Sucursales `TSAARG` y `TSABR` activas | *Maestros* / administración de sucursales | El importador rechaza las filas. |

---

## 6. Decisiones tomadas

| Decisión | Valor | Por qué |
|---|---|---|
| Tipo de equipo | `Semirremolque` para las 27 | Es la palabra que la regla de clasificación reconoce. Un tipo "Carreta" las dejaría fuera de ambos combos. |
| Marca | `Vulcano` (14), `Randon` (8), `Facchini` (4) | Del documento. Las 13 marcas de los argentinos son **inferidas** del WMI del chasis (`8B8GSRA3…` / `8C9ST1553` = Vulcano); el documento sólo lo dice para AH137XF. Cada ficha lo dice en observaciones para que no quede cargada en silencio. |
| Sucursal | 14 → `TSAARG`, 13 → `TSABR` | Decisión D3 del plan, ya confirmada con el cliente. Coincide con los dos grupos del documento. |
| Código de equipo | Igual a la patente | Es lo que ya hace la importación de unidades de TSA y como están cargados los camiones (`AB4990K - AB4990K`). |
| Fecha de alta | Fecha de la carga | El documento informa año de modelo, no fecha de alta al sistema. |
| Chasis con espacios | Se carga el texto del documento sin espacios | `AE611RN` viene como `8B8C SRA 3CM 009053`. El plan proponía `8B8GSRA3CMM009053`, pero esas letras **no están** en el documento: se carga lo que dice y la ficha deja la pregunta escrita. |
| Modelo | Vacío | El modelo exige que exista en el catálogo con esa marca y ese tipo. No hay modelos cargados. |
| Motor | Vacío | El documento no informa motor de caja. Para las frigoríficas informa la marca del equipo de frío (Carrier / Thermoking), que va en observaciones. |
| Póliza y medidas | En observaciones | `equipos` todavía no tiene columnas de póliza ni de medidas (PR 2 del plan). Van en texto para no perderlas. |

---

## 7. Bugs encontrados durante el trabajo

El generador es la parte nueva del código y falló cuatro veces contra el documento real.
Se registran porque el documento tiene formatos inconsistentes y volver a tropezar con
esto es fácil:

1. **`preg_match` sin el array de coincidencias.** Cuatro funciones devolvían
   `$matches[1]` sin haberlo pedido: el año, la póliza, el vencimiento y el equipo de
   frío salían siempre vacíos, sin error visible (sólo warnings).
2. **El bloque argentino se volcaba con el grupo ya cambiado.** La última unidad
   argentina (AF902UB) terminaba asignada a TSA Brasil, porque el encabezado "BRASILEROS"
   se procesaba antes de cerrar el bloque anterior.
3. **El chasis arrastraba texto de la línea.** En las líneas donde el chasis convive con
   las medidas o con el equipo de frío ("CHASIS 955L1513DES358431 MEDIDAS: ..."), se
   guardaba "955L1513DES358431MEDIDAS" como chasis.
4. **Una limpieza de caracteres rompía chasis.** El patrón que quitaba el "N°" se
   comía la "N" de los chasis que la tienen en el medio ("8B8GSRA3CNM010193" →
   "8B8GSRA3CM010193"). Un chasis mal escrito se carga sin que nadie lo note.

Además, el documento tiene mayúsculas y acentos heterogéneos ("PÓLIZA NACIÓN SEGUROS",
"POLIZA SEGURO N°", "POLIZA N02852...") y espacios separadores que no son normales
(U+00A0). El parser los tolera a propósito.

---

## 8. Pendientes

Datos:

- [ ] **R2 — Marca de los 14 argentinos.** Inferida del WMI del chasis. Confirmar con el
      cliente y corregir las fichas si hace falta.
- [ ] **R3 — Chasis de AE611RN.** Confirmar si es `8B8CSRA3CM009053` o
      `8B8GSRA3CMM009053`.
- [ ] **IYQ3E75.** La línea del documento dice "CARRETA SIDER MARCA: FACCHINI" pero la
      placa dice "PLACA SIDER RANDON". Se cargó Randon, que es lo que asigna el plan. La
      ficha lo dice. Confirmar.
- [ ] **Equipo de frío como equipo propio.** Las 7 frigoríficas tienen equipo Carrier o
      Thermoking, que son mantenibles aparte (PR 6 y 7 del plan). Hoy van anotados en
      observaciones, no cargados como equipos.
- [ ] **Pólizas como vencimientos.** 27 filas de vencimiento (14 al 22/08/2027 y 13 al
      22/04/2027) con los dos números de póliza. Se pueden cargar con la importación
      `VENCIMIENTOS` una vez exista el tipo de vencimiento `POLIZA SEGURO`.
- [ ] **Planes de mantenimiento** de las 27 unidades: requieren los PR 1 a 7 del plan.

Código:

- [ ] **Atributo explícito de rol en `tipos_equipo`.** Es la forma definitiva de que
      "esto es un semirremolque" no dependa del nombre. Hoy la regla está duplicada en
      dos adaptadores y sobre texto libre; el test
      `SemisEquipmentImportFileTest::testLaReglaDeClasificacionEsLaMismaEnLaFlotaYEnElFormularioDeViaje`
      avisa si las dos copias divergen, pero no evita que la regla sea frágil.
- [ ] **Carga de la composición** (bitrén): queda pendiente del plan (PR 5).

---

## 9. Evidencia

```text
$ php vendor/bin/phpunit --no-coverage --filter SemisEquipmentImportFileTest
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.5.4
.........
OK (9 tests, 359 assertions)
```

Las pruebas verifican, contra el código de la aplicación y no contra una copia:

- los encabezados son los de `ImportType::EQUIPOS`;
- las 27 filas pasan `ImportRowValidator` sin un solo error;
- el tipo `Semirremolque` se clasifica como semirremolque y **no** como tractor según
  `CodeIgniterEquipmentFleetLookup` (invocado por reflexión, sin base de datos);
- la regla de la flota y la del formulario de viaje dicen lo mismo;
- el inventario (patente, sucursal, año, chasis, marca) coincide con el documento;
- no hay códigos ni patentes repetidos;
- sin acceso a TSA Brasil, las 13 filas de Brasil se rechazan (riesgo R1) y las 14 de
  Argentina se aceptan.

Suite completa del proyecto, con los 9 tests nuevos incluidos:

```text
$ php vendor/bin/phpunit --no-coverage
Tests: 1506, Assertions: 6061, Skipped: 44.
OK, but some tests were skipped!
```

Sin fallos ni warnings. Antes de este trabajo la suite corría 1497 pruebas; la diferencia
son los 9 tests de esta carga.
