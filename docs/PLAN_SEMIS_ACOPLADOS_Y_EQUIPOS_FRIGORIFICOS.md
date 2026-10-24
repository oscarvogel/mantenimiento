# Plan de implementación: semirremolques, carretas y equipos frigoríficos

**Fecha:** 8 de octubre de 2026
**Origen:** `docs/SEMI.docx` (documento del cliente) + inspección del código y migraciones vigentes
**Estado:** propuesta v2 para revisar. No hay cambios de código implementados.
**Contextos afectados:** Registro de activos (dueño), Medición de uso (lector), Mantenimiento preventivo (motor), Recepción y triage (solicitudes), Notificaciones.

---

## 0. Correcciones respecto de la v1 de este plan

Se registran explícitamente para que quede trazable qué cambió y por qué.

| # | En la v1 decía | Corrección | Origen |
|---|---|---|---|
| C1 | El semirremolque no tiene odómetro y su km no se puede capturar | **El km sí se captura**, y hay que capturarlo **al enganchar/desenganchar**, que es la práctica que el cliente ya tiene | Comentario del usuario + DDL legacy `armado_unidades` |
| C2 | El equipo de frío se mide por horas | Confirmado, y además los planes pueden ser **por horómetro y/o fecha** | Comentario del usuario |
| C3 | "Falta la sucursal de cada unidad, bloquea la carga" | Resuelto: **14 → TSA Argentina, 13 → TSA Brasil** | Comentario del usuario |
| C4 | "La composición de los conjuntos es un dato que falta" | **No es un faltante**: se dejan sin asociar y lo carga el usuario desde el sistema. Por eso **la carga tiene que ser fácil** → es un requisito de producto, no un supuesto | Comentario del usuario |
| C5 | "`AGENTS.md` está desactualizado: ya hay neumáticos, viajes y facturación" | **Error mío.** Neumáticos, viajes y facturación **NO están implementados**: no hay migración, ni tabla, ni código. Solo existen como especificación (spec v1.2, `PLAN_AMPLIACION_NEUMATICOS_VIAJES_FACTURACION.md`) y como DDL legacy de origen para una migración futura | Comentario del usuario + verificación |

**C5 fue un error de verificación mío:** inferí implementación a partir de la especificación y de
documentos de análisis, sin comprobar migraciones ni código. Los controladores reales del proyecto
son 32 y **no existe ninguno de neumáticos, viajes ni facturación**.

---

## 1. Conclusión

**No hace falta un módulo nuevo de acoplados.** El semirremolque se modela como un `equipo` más y
se le engancha al camión con `equipo_relaciones`, que ya existe con historial temporal. El bitrén y
el tritren ya están soportados por el modelo.

Lo que falta son **cinco cosas**, de las cuales dos son datos y tres son código:

| # | Falta | Esfuerzo |
|---|---|---|
| 1 | Tipos de equipo para acoplado y equipo de frío | Datos, ~1 h |
| 2 | Plantillas y planes de service del acoplado y del equipo de frío | Datos, ~1 día |
| 3 | Km del acoplado capturado en el enganche/desenganche | Código + 1 migración, ~2 días |
| 4 | Equipo de frío como unidad mantenible propia | Código + UI, ~2 días |
| 5 | **Carga cómoda de la composición** (el usuario la va a cargar) | Código + UI, ~2 días |

El punto 5 es el que cambió de prioridad: pasó de "el cliente nos pasa el dato" a "el usuario lo carga
en el sistema, y tiene que ser fácil". Con 27 unidades, hacer 27 formularios de uno en uno no es
carga, es castigo.

---

## 2. Decisiones confirmadas

| Decisión | Valor | Efecto |
|---|---|---|
| **D1** Kilometraje del acoplado | Se captura **al enganchar y al desenganchar**, replicando la práctica actual del cliente | Los planes por km del semi salen de `SIN_DATOS` sin obligar a cargar odómetro cada semana |
| **D2** Criterios de plan del equipo de frío | **Horómetro del equipo y/o fecha** | `Unidad frigorífica` con `controla_horas = 1`; sus planes son por horas, por fecha o por ambos |
| **D3** Sucursal | **14 argentinos → TSA Argentina**, **13 brasileros → TSA Brasil** | Coincide con los dos grupos del propio documento |
| **D4** Composición tractor↔caja | **Se dejan sin asociar**; la carga el usuario desde el sistema | Requiere flujo de carga masiva (PR 5) |
| **D5** Póliza | Un `vencimiento` por unidad (27 filas) | Aceptar y agrupar el ruido de alertas |
| **D6** Medidas | `medidas VARCHAR(100) NULL` en `equipos` | Dato del activo, consultable y exportable |

### Sobre D1: por qué el km se captura y no se deriva

En la v1 propuse derivar el km del tractor y descarté la carga manual. **Eso estaba mal**, por dos
motivos que aparecieron al mirar el sistema de origen:

1. `docs/ddl_backup_neumaticos.sql`, tabla `armado_unidades`, tiene los campos
   `patente_tractor`, `km_tractor`, `patente_semi_acoplado`, `km_semi_acoplado`,
   `km_semi_acoplado_desenganche`, `fecha_enganche`, `fecha_desenganche`. El cliente **ya carga el km
   de la caja** al enganchar y al desenganchar. No es un dato inventado: es un hábito operativo.
2. El mismo `km_semi_acoplado_desenganche` da algo que la derivación no da: saber **en qué km quedó la
   caja cuando salió del camión**. Con eso se puede detectar un cambio de caja y se puede auditar.

Además, el modelo legacy tiene la columna `item`, o sea **varias filas por unidad**: es exactamente el
bitrén (un tractor, dos o tres cajas).

**Diseño resultante (sin descartar nada de lo existente):**

- La relación `equipo_relaciones` gana **dos columnas nullable**: `km_enganche` y `km_desenganche`.
- El formulario de enganche ofrece, opcionalmente, "km al enganchar". Si se informa, queda como línea
  base de la caja en esa relación.
- Mientras la relación está activa, el km vigente de la caja se puede resolver desde el del tractor.
- Al finalizar la relación, se informa "km al desenganchar" y ese queda como último km propio de la caja.
- El tipo `Acoplado / Semirremolque` se crea con **`controla_km = 1`**, para que la caja pueda tener
  lecturas propias; si el usuario nunca las carga, el resolutor usa las del tractor sin que nadie
  teclee un número al azar.
- Un plan puede ser **solo por fecha** cuando el servicio lo sea (lonas, cortinas, chequeo anual de
  GNU, revisión de estructuras). No todos los servicios del acoplado son por km.

---

## 3. Qué contiene `SEMI.docx` y cómo queda cargado

27 unidades. El documento ya viene agrupado, y esa agrupación **es** la sucursal.

### 3.1 Semirremolques argentinos — 14 → sucursal **TSA Argentina**

Póliza Nación Seguros N° 2657159, VTO 22/08/2027. Todos Vulcano.

| Patente | Año | Chasis | Motor |
|---|---|---|---|
| AH137XF | 2025 | 8C9ST1553S0VU9014 | Sider |
| ONN137 | 2015 | 8B8GSRA3FFM006431 | |
| AB264AY | 2017 | 8B8GSRA3FHM007442 | |
| IAF639 | 2009 | 8B8GSRA3F9M002577 | |
| NAR553 | 2013 | 8B8GSRA3FDM005266 | |
| AF336NP | 2022 | 8B8GSRA3CNM010193 | |
| AE766KU | 2021 | 8B8GSRA3CMM009206 | |
| AA833CX | 2017 | 8B8GSRA3FHM007327 | |
| AF854XE | 2023 | 8B8GSRA3CPM011040 | |
| AD982GM | 2019 | 8B8GSRA3FLM008759 | |
| AE928DO | 2021 | 8B8GSRA3CMM009272 | |
| AF081OH | 2021 | 8B8GSRA3CNM009767 | |
| AE611RN | 2021 | 8B8C SRA 3CM 009053 *(con espacios)* | |
| AF902UB | 2023 | 8B8GSRA3CPM010997 | |

### 3.2 Carretas brasileras — 13 → sucursal **TSA Brasil**

Póliza N° 02852.2026.0043.0644.0000357, VTO 22/04/2027.

**Sider secas — 6:**

| Patente | Año | Marca | Chasis | Medidas (mts) |
|---|---|---|---|---|
| IUW1F97 | 2014 | Randon | 955L1513DES358431 | 15.00 x 2.50 x 2.62 |
| JCE2I90 | 2023 | Facchini SRF LO | 94BF1543PPV004916 | 15.40 x 2.60 x 2.78 |
| RHB4B13 | 2021 | Facchini SRF LO | 9ADG1453GGM401290 | 15.40 x 2.50 x 2.75 |
| IYQ3E75 | 2018 | Randon | 94BF1483JJV057789 | 14.80 x 2.60 x 2.78 |
| JCJ0H66 | 2023 | Facchini | 94BF1543PRV008216 | — |
| JCN8I76 | 2024 | Facchini | 94BF1543RRV001461 | 15.40 x 2.50 x 2.75 |

**Frigoríficas — 7, todas SR/RANDON:**

| Patente | Año | Chasis | Equipo de frío | Medidas (mts) |
|---|---|---|---|---|
| LZV0A24 | 2014 | 9A9FR389ECDW8864 | **Carrier** | — |
| JAP4D98 | 2021 | 9ADF1473MMC013143 | **Carrier** (SR FG FR 03E) | — |
| BEN2E47 | 2021 | 9ADF1473LMC012181 | **Thermoking** | — |
| BEN4E07 | 2021 | 9ADF1473LMC012182 | **Thermoking** | — |
| RHB1F55 | 2022 | 9ADF1473MNC015744 | **Thermoking** | — |
| BEN5E43 | 2021 | 9ADF1473LMC012333 | **Thermoking** | — |
| RHB1B77 | 2022 | 9ADF1473MNC014630 | **Thermoking** | 14.50 |

**Consecuencia del scope por sucursal:** con las cajas brasileras en TSA Brasil, un usuario habilitado
solo para TSA Argentina **no las ve**. Hay que verificar que exista al menos un usuario con acceso a
las dos sucursales, o el usuario va a "perder" 13 equipos.

### 3.3 Datos que siguen faltando

Ya no son la sucursal ni la composición. Quedan:

- **Hora de/km al enganchar**: opcionales en la carga, no bloquean.
- **Número de serie y horas del equipo de frío**: sin ellos, los planes por horómetro nacen en
  `SIN_DATOS` hasta la primera lectura. No bloquea la carga; bloquea la primera OT de service.
- **Marca de los 14 argentinos**: inferida del WMI del chasis (`8B8GSRA3…`, `8C9ST1553` = Vulcano),
  no figura en el documento. **Es lo único que puede cargar una marca equivocada en silencio.**
- **Chasis de AE611RN** con espacios: normalizar a `8B8GSRA3CMM009053`, preguntando.

---

## 4. Estado real del código (verificado)

### 4.1 Lo que YA sirve tal cual

| Necesidad | Mecanismo | Ubicación |
|---|---|---|
| Ficha del acoplado (patente, año, chasis, motor, marca, modelo) | `equipos` + `Equipment` | `app/Domain/Assets/Equipment.php:308-342` |
| Camión con 1, 2 o 3 cajas | `equipo_relaciones`, N filas por tractor | `app/Domain/Assets/EquipmentRelation.php` |
| Historial de a qué camión estuvo enganchada la caja | `desde` / `hasta` / `finalizado_por` | `2026-08-08-110063:19-22` |
| No dar de baja una caja enganchada | `hasActiveRelations` | `app/Application/Assets/DecommissionEquipmentHandler.php:41-43` |
| Plan preventivo propio por unidad | `planes_mantenimiento.equipo_id` | `2026-08-08-110033` |
| Plantilla por tipo de equipo (o genérica) | `plantillas_mantenimiento.tipo_equipo_id` admite `NULL` | `2026-08-12-120110` |
| Orden de trabajo sobre la caja | `ordenes_trabajo.equipo_id` | `2026-08-08-110041` |
| Falla del acoplado por QR y desde el móvil | QR público + solicitudes | `app/Application/PublicEquipmentAccess` |
| Póliza y vencimientos | `tipos_vencimiento` + `vencimientos` | `2026-09-08-181500` |
| Cargar todo desde Excel | `ImportType::EQUIPOS` y `ImportType::VENCIMIENTOS` | `app/Domain/Importations/ImportType.php:11-15` |

### 4.2 El bitrén ya funciona

`CreateEquipmentRelationHandler.php:51-54` bloquea una relación `TRACTOR_ACOPLADO` si **el equipo
relacionado** (la caja) ya tiene una relación activa del mismo tipo
(`CodeIgniterEquipmentRelationRepository.php:37-44`).

- Una caja **no** puede estar enganchada a dos camiones a la vez. Correcto.
- Un camión **sí** puede tener N cajas activas. El chequeo nunca mira el `equipo_principal_id`.

**No hay que cambiar esa regla.** Coincide con el modelo legacy (`armado_unidades.item`).

### 4.3 Lo que falta

| # | Falta | Impacto |
|---|---|---|
| a | `equipo_relaciones` no tiene `km_enganche` / `km_desenganche` | El km de la caja queda sin línea de base |
| b | No hay tipo de relación para el equipo de frío | El Carrier/Thermoking no se puede montar |
| c | El desplegable de candidatos toma los **primeros 100 equipos activos**, sin filtro por tipo ni búsqueda (`app/Controllers/EquipmentManagement.php:87-89`) | Con 27 cajas más los camiones, hay que buscar una patente en un `<select>` plano |
| d | No hay carga múltiple: solo se relaciona de a uno | 27 formularios para armar los conjuntos |
| e | `equipos` no tiene columnas de medidas ni de número de serie | Las medidas de las carretas no entran |
| f | `tipos_equipo` es catálogo **global sin `empresa_id`**, con `nombre` UNIQUE a nivel sistema (`2026-08-08-110020:43`) | No se puede duplicar por empresa: el nombre importa |

---

## 5. Diseño

### 5.1 Kilometraje del acoplado (D1)

Las tres opciones por separado son incompletas; la correcta es combinarlas:

1. **Al enganchar**, el formulario ofrece el km. Si se informa, queda en `km_enganche`.
2. **Mientras está enganchada**, el km vigente de la caja se resuelve desde el del tractor.
3. **Al desenganchar**, se informa `km_desenganche`, que queda como último km propio de la caja.
4. Un plan **solo por fecha** es válido cuando el servicio lo sea.

Implementación: `UsoEquipo` (value object con `km`, `horas` y `origen` ∈ `PROPIO | TACTOR | DESCONOCIDO`),
puerto `EquipmentUsageResolver` y caso de uso en `Application`. **El `EvaluadorVencimiento` no se
toca**: sigue puro y sin infraestructura.

**Migración:** `2026-10-XX-XXXXXX_AddKilometersToEquipmentRelations.php`

```text
equipo_relaciones.km_enganche    INT UNSIGNED NULL
equipo_relaciones.km_desenganche INT UNSIGNED NULL
```

`down()` las elimina. Índices: ninguno nuevo necesario; ya existe
`idx_relaciones_principal` y `idx_relaciones_incompatibilidad`.

### 5.2 Equipo de frío (D2)

Los 7 equipos se cargan como `equipos` de tipo **`Unidad frigorífica`** con
`controla_horas = 1`, `controla_km = 0`, marca Carrier o Thermoking, y se relacionan con su caja por
un tipo de relación nuevo `UNIDAD_FRIGORIFICA`.

**Planes del equipo de frío: por horómetro y/o fecha** (D2 del usuario). Ejemplos:

| Servicio | Criterio | Intervalo típico |
|---|---|---|
| Service motor térmico | Horómetro | cada 300 h |
| Cambio de correas / filtros de aire | Horómetro | cada 600 h |
| Carga de gas refrigerant, valve deck | Horómetro | cada 1.000 h |
| Calibración de setpoint y habilitación de invierno | Fecha | anual |
| Reparación de cortinas (lona) | Fecha | anual |

**Por qué equipo propio y no atributo:** el equipo de frío se cambia de caja (por venta o rotura) y
tiene historial propio. Como equipo, la OT conserva a qué caja estaba montado y se puede dar de
baja sin dar de baja el semirremolque.

**Efecto obligatorio en el dominio:** `EquipmentRelation::isIncompatibleWithActiveRelation()`
(`app/Domain/Assets/EquipmentRelation.php:92-95`) debe devolver `true` también para
`UNIDAD_FRIGORIFICA`. **Sin esto, se podría montar el mismo Carrier en dos cajas.** El handler ya
generaliza (`CreateEquipmentRelationHandler.php:51-54` usa `$relation->type()`), pero el mensaje de
error es genérico: bajarlo a lenguaje de operador
("El equipo de frío CARRIER-LZV0A24 ya está montado en la caja JAP4D98").

### 5.3 Carga de la composición (D4) — el requisito nuevo

Es lo que el usuario va a usar. Tres tiempos:

1. **Buscar y elegir varias cajas de una vez** desde la ficha del camión: buscador por patente,
   código o tipo, con selección múltiple. Reemplaza el `<select>` de 100 ítems.
2. **Una sola fecha y un solo comentario** para todo el armado.
3. **Transacción única**: si una de las cajas falla (ya enganchada a otro camión, dada de baja,
   fuera de la sucursal del usuario), **no se crea ninguna** y se informa cuál fue.

```text
Aplicación:  CreateEquipmentRelationsHandler   (N comandos en una transacción)
Dominio:     EquipmentRelation::start  (sin cambios de firma)
Puerto:      EquipmentRelationRepository::addMany
Presentac.:  OperationsPayload + EquipmentDetailPage (Vue)
```

Además, un **atajo para el bitrén**: elegir un camión y pegar las patentes de sus cajas separadas por
coma o espacio. Son 27 placas; escribirlas es más rápido que seleccionarlas una por una.

En la ficha del camión, el conjunto armado se muestra **agrupado**, no como filas sueltas: hoy la
página lista las relaciones en una tabla plana (`frontend/src/pages/operations/EquipmentDetailPage.vue:286-288`)
y con 2-3 cajas hay que leer la tabla para entender qué va enganchado.

### 5.4 Póliza (D5)

- `tipos_vencimiento` = `POLIZA SEGURO`, `aplica_a = EQUIPO`, `requiere_documento = 1`,
  `dias_aviso_previo` a definir con el cliente (30 o 60).
- 27 filas: 14 con `22/08/2027` / póliza 2657159, 13 con `22/04/2027` / póliza `02852.2026.0043.0644.0000357`.
- La clave única es `(empresa_id, equipo_id, tipo_vencimiento_id, fecha_vencimiento)`
  (`2026-09-08-181500:59`): 27 filas con 2 números de póliza **no colisionan**.
- **Consecuencia a verificar:** el ciclo de notificaciones generará avisos con 2 fechas
  concentradas. Hay que comprobar que el agrupamiento existente los muestre como bloques y no como
  correos sueltos.

### 5.5 Medidas y número de serie (D6)

**Migración:** `2026-10-XX-XXXXXX_AddTrailerAttributesToEquipment.php`

```text
equipos.medidas       VARCHAR(100) NULL   -- "15.40 x 2.60 x 2.78"
equipos.numero_serie  VARCHAR(100) NULL   -- Nº de serie de la caja o del equipo de frío
```

`down()` las elimina. Sin cambios en casos de uso: son campos de ficha, se muestran y se importan.

El campo `motor` que ya existe (`Equipment.php:32`) es el **motor del camión**. Para el equipo de
frío, el motor térmico no va ahí (el `motor` del semi es la caja): su identificación va en
`numero_serie`.

---

## 6. Plan de implementación por PR

| PR | Alcance | Migración | Depende de |
|---|---|---|---|
| **1** | Catálogos: tipo `Acoplado / Semirremolque` (`controla_km = 1`, `controla_horas = 0`), tipo `Unidad frigorífica` (`controla_horas = 1`), marcas Vulcano / Randon / Facchini / Carrier / Thermoking, modelo `SR FG FR 03E`, tipo de vencimiento `POLIZA SEGURO` | No | — |
| **2** | Columnas `medidas`, `numero_serie` en `equipos`, con lectura y edición en la ficha y en el importador | Sí | — |
| **3** | Columnas `km_enganche` / `km_desenganche` en `equipo_relaciones`, capturadas en el formulario de enganche y desenganche | Sí | — |
| **4** | `UsoEquipo` + `EquipmentUsageResolver` + `ResolveEquipmentUsage`, y los lugares que arman `UsoActual` pasan a usarlo. **Sin tocar `EvaluadorVencimiento`** | No | 3 |
| **5** | **Carga masiva de composición**: buscador con selección múltiple, pegado de patentes, `CreateEquipmentRelationsHandler` transaccional, conjunto armado agrupado en la ficha | No | 3 |
| **6** | Equipo de frío: tipo de relación `UNIDAD_FRIGORIFICA`, exclusividad en dominio, filtro por tipo en el buscador, ficha del equipo de frío montado | No | 1 |
| **7** | **Biblioteca de mantenimiento**: tipos de servicio, tareas y plantillas del semirremolque y del equipo de frío; planes asignados a las 27 + 7 unidades | No | 1, 6 |
| **8** | **Carga de datos**: 27 equipos (14 TSA Argentina / 13 TSA Brasil), 7 equipos de frío, 27 vencimientos de póliza, y los planes | No | 1-7 |

### Pruebas por PR (definición de terminado)

- [ ] PR 4: `UsoEquipo` y la política de origen, en dominio puro.
- [ ] PR 4: caso de uso con dobles — caja enganchada toma km del tractor; caja suelta, `null`; camión,
      km propio; equipo de frío, horas propias y km `null`.
- [ ] PR 4: **regresión** — un plan por km de un camión evalúa igual que antes.
- [ ] PR 5: un camión puede tener **2 cajas activas** (bitrén) — **agregar este test antes de tocar el
      código**, es la invariante que no se debe romper.
- [ ] PR 5: una caja **no** puede estar con dos camiones a la vez.
- [ ] PR 5: carga masiva es todo-o-nada; si una caja falla, no se crea ninguna.
- [ ] PR 5: la búsqueda respeta empresa y sucursal del usuario.
- [ ] PR 6: un equipo de frío no puede estar montado en dos cajas a la vez.
- [ ] PR 2 y 3: `up()` / `down()` probados sobre MariaDB real.
- [ ] Suite PHPUnit verde y verificación HTTP real en `fasa_189` antes de cualquier despliegue.

---

## 7. Fuera de alcance

- **Neumáticos y gomería.** No están implementados en este sistema. `docs/ddl_backup_neumaticos.sql`
  y `ANALISIS_BACKUP_NEUMATICOS_Y_MIGRACION.md` son el **origen legacy** y su plan de migración. El
  padrón de neumáticos de los semirremolques (que tienen 2-3 ejes) es un trabajo aparte y no se
  mezcla con este plan.
- **Viajes, combustible y costos operativos.** No implementados, y además el odómetro de la caja es
  el del camión: los viajes se cargarían sobre el equipo que se conduce.
- **Facturación electrónica.** No implementada. `comprobantes_venta_detalle.equipo_id` está en la spec
  pero no existe en el código.

---

## 8. Riesgos y pendientes

| # | Riesgo | Impacto | Quién |
|---|---|---|---|
| R1 | **Acceso a las dos sucursales**: si ningún usuario tiene TSA Argentina **y** TSA Brasil, 13 equipos quedan invisibles | Alto | Verificar antes de cargar |
| R2 | **Marca de los 14 argentinos** inferida del WMI del chasis | Ficha con marca errada, sin aviso | Cliente |
| R3 | Chasis de `AE611RN` con espacios | Se importa mal si no se normaliza | Cliente |
| R4 | Sin nº de serie ni horas del equipo de frío | Los planes por horómetro nacen en `SIN_DATOS` | Esperando carga |
| R5 | 27 avisos de póliza concentrados en 2 fechas | Ruido de notificaciones | PR 8 / verificación |
| R6 | Fecha de alta: se propone el **año de modelo** como base inicial de los planes | Los planes arrancan con una base aproximada | Cliente |
| R7 | `tipos_equipo.nombre` es UNIQUE global | El nombre elegido es definitivo para todo el sistema | Definir con cuidado |

---

## 9. Trazabilidad

- Datos: `docs/SEMI.docx` → texto plano en `docs/_semi_texto_extraido.txt`.
- Origen legacy del patrón de composición y de km:
  `docs/ddl_backup_neumaticos.sql`, tablas `armado_unidades` y `camiones_remolques`.
- La spec v1.2 (§1 y §5.2) ya menciona "acoplados y semirremolques" y "relación temporal entre
  tractores y acoplados", pero **no modela** el equipo de frío, la composición múltiple ni el km de
  enganche. Debería actualizarse cuando se apruebe este plan.
- `AGENTS.md` §"Estado base": **parcialmente desactualizado, en dirección contraria a lo que dije en
  la v1**. Es cierto que las etapas grandes están implementadas (equipos, lecturas, órdenes,
  solicitudes, planes, importaciones, vencimientos, empleados, proveedores, WhatsApp, chatbot,
  reportes), pero es **falso** que neumáticos, viajes y facturación estén implementados: solo están
  especificados. La línea correcta para `AGENTS.md` sería: "Etapas 1 a 3 implementadas; dominios de
  la spec v1.2 (neumáticos, viajes, facturación) pendientes".

---

## 10. Anexo: inventario del documento

- 27 unidades: 14 semirremolques (TSA Argentina) + 13 carretas (TSA Brasil).
- 7 de las 13 son frigoríficas con equipo de frío: 2 Carrier, 5 Thermoking.
- 2 pólizas para 27 unidades.
- 6 con medidas declaradas.
- 1 chasis con formato anómalo (`AE611RN`).
- 0 series de equipos de frío (solo marca: "MARCA MOTOR CARRIER / THERMOKING").