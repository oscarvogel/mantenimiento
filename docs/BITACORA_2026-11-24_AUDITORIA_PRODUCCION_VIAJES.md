# Auditoría de producción de los viajes operativos — 24/11/2026

**Alcance:** empresa TSA (`empresa_id = 4`), los 42 viajes operativos cargados en
producción Ferozo. Lectura pura: no se escribió ni se modificó ningún dato.

**Cómo se hizo:** script `scripts/auditoria-produccion-viajes.php` (solo `SELECT`),
subido a la raíz del release por FTPS, ejecutado con el header `X-Migrate-Token` y
borrado del release al terminar. Runner: `scripts/run-auditoria-viajes.py`.
Informe completo (JSON) en `tmp/auditoria-produccion-viajes.json`.

## 1. Respuesta corta

**Sí hay combustible cargado, pero en un solo viaje.** De las 8 cargas de
combustible de toda la base, las 8 son del viaje 21 (FINALIZADO, equipo ONN137,
chofer Walter Rolón). Los otros 41 viajes —incluidos los 40 que están EN_CURSO—
tienen **cero cargas de combustible**.

**Los márgenes que muestra la app hoy están inflados.** El combustible es el
costo variable principal y no está cargado en ningún viaje en curso. El margen
que ves hoy en el panel es facturación menos comisión, sin el combustible real.

**Los viajes están íntegros en lo formal:** los 42 tienen el 14% de liquidación
resuelto (origen VIAJE) y el único viaje cerrado tiene su lectura de regreso.

## 2. Combustible (el detalle que se pidió)

8 cargas · 2.812 L · 3.110.061 ARS · precio medio 2.223,71 ARS/L
(mínimo 1.985,38 · máximo 2.348,94).

| Fecha | Litros | Importe | Moneda | ARS | Precio/L | Km | Tramo | Estación |
|---|---|---|---|---|---|---|---|---|
| 30/10 | 200 | — | ARS | 0 | — | — | — | YPF Ruta Corrientes |
| 01/11 | 219 | — | ARS | 0 | — | — | — | YPF Ruta Concordia |
| 01/11 | 409 | — | ARS | 0 | — | — | — | — |
| 02/11 | 512 | 1.196.442 | ARS | 1.196.442 | 2.336,80 | — | — | MERCOSUR |
| 10/11 | 518 | 1.216.749 | ARS | 1.216.749 | 2.348,94 | — | — | PEDRIEL |
| 13/11 | 193 | — | ARS | 0 | — | — | — | — |
| 13/11 | 410 | — | ARS | 0 | — | — | — | YPF P. de los Libres |
| 17/11 | 351 | 2.403 BRL | BRL | 696.870 | 1.985,38 | 727.587 | — | — |

Hallazgos:

1. **5 de las 8 cargas no tienen precio cargado** (1.431 L, la mitad de los
   litros). Se ven en pantalla como carga sin importe.
2. **7 de las 8 no tienen kilometraje.** La única con km es la de BRL.
3. **Ninguna de las 8 está imputada a un tramo** (`tramo_id` NULL): las 3.110.061
   ARS de combustible quedan fuera del margen por tramo. En el panel aparecen
   como "costos sin asignar".
4. La única carga en moneda extranjera está bien cargada: 2.403 BRL a
   cotización 290 → 696.870 ARS.
5. Consumo medido del viaje 21: **39,46 L/100 km** (2.812 L sobre 7.127 km de
   odómetro). Es un valor alto para un camión de carga: con los 1.431 L sin
   precio no se puede saber si es consumo real o error de carga.

## 3. Cómo están los viajes

| Estado | Viajes |
|---|---|
| EN_CURSO | 40 |
| FINALIZADO | 1 (el 21) |
| CANCELADO | 1 (el 30) |

- **Antigüedad:** 33 de los 40 EN_CURSO llevan **3 días o más abiertos**. Los
  más viejos: el 25 (salida 15/10, **40 días**), el 19 (16/10, 39 días), el 5
  (24/10, 31 días) y el 26 (23/10, 32 días).
- **Kilometraje:** ningún viaje EN_CURSO tiene km de salida y de regreso
  cargados. Sin eso no se puede calcular el consumo ni cerrar el viaje con
  lectura del equipo.
- **Cierre bloqueado en 22 viajes:** todos por tramos sin flete de liquidación
  (el 8, 9, 12, 13, 14, 15, 17, 20, 22, 26, 27, 29, 31, 32, 35, 36, 37, 38, 39,
  40, 41 y 42). Es el punto ya conocido: el flete de liquidación es el monto
  pactado con el chofer y lo carga la operación en la app.
- **Porcentaje de liquidación:** 42 de 42 resueltos al 14%, todos con origen
  VIAJE (copia en la asignación). El 14% por defecto de la empresa quedó
  cargado y vigente.
- **Lecturas de equipo:** el único FINALIZADO tiene su lectura de regreso
  (1.727.587 km, coincide con el odómetro). Ningún FINALIZADO quedó sin lectura.

## 4. Economía (mismo cálculo que la app)

| Concepto | ARS |
|---|---|
| Facturado (viajes con facturación completa) | 320.638.070 |
| Comisión (14%) | 22.775.200 |
| Combustible | 3.110.061 |
| Gastos | 360.900 |
| Otros haberes | 3.120.000 |
| **Margen** | **152.437.939** |

Estos totales **no son confiables todavía**: el combustible está cargado en 1 de
42 viajes y los gastos en 3 de 42. El margen de cada viaje en curso muestra
entre 78% y 89% porque falta el costo de combustible de esos viajes.

- **Gastos:** 13 gastos por 360.900 ARS, **los 13 sin imputar a tramo**.
  Concentrados en 3 viajes: el 21 (160.900 ARS, pagados de caja), el 16
  (119.000 ARS, pagados por el chofer) y el 4 (71.000 ARS, pagados de caja).
- **Caja:** 17 movimientos por 2.436.100 ARS — anticipos 1.836.100 y retiros
  600.000, en 7 viajes. Son movimientos de cuenta corriente del chofer: afectan
  la liquidación, no el margen.
- **Otros haberes:** 43 movimientos por 3.120.000 ARS (estadías de cámara de
  frío 1.520.000, estadía sider 750.000, cruce Chile 450.000, cruce Brasil
  400.000).
- El margen más bajo es el del viaje 21 (53,3%): es el único con combustible
  cargado.

## 5. Lo que NO se hizo

- **No se imputó ningún gasto ni carga de combustible a un tramo.** Imputar un
  costo a un tramo es decisión operativa, no un dato mecánico.
- **No se completó ningún flete de liquidación.** Es el monto pactado con el
  chofer; completarlo por convención calcularía comisión sobre base falsa.
- **No se cargó el precio de las 5 cargas de combustible sin precio.** El
  precio de un surtidor es un dato de la carga: se completa en la app.

## 6. Combustible: dónde vive el dato y si coincide con el módulo

El sistema tiene **dos cosas que se llaman "Combustible"**, y conviene no
confundirlas:

| Qué es | Dónde vive | Estado |
|---|---|---|
| **Módulo de plataforma "Combustible"** (card "Próximamente" del portal) | `GetPlatformModuleCatalog::FUTURE_MODULES['fuel']` — "Consumo, rendimientos y control de abastecimiento" | **No implementado.** No es un contexto de la especificación: la spec (§5.13, §6.12, Etapa 6) define `CargaCombustible` como **entidad hija del agregado `Viaje`**, y su tabla es `viaje_operativo_combustible`. |
| **Conciliación de combustible** (Gerencial → Tablero de flota → pestaña "Combustible", permiso `gerencial.flota.ver`) | Lee `viaje_operativo_combustible` por el puerto `ViajesDeclaradosReader` (`CodeIgniterViajesDeclaradosReader`) | **Implementada y viva.** Compara los litros declarados por el chofer contra el sensor del tanque. |
| **"Combustible sin precio"** (menú de Viajes, permiso `viajes.operar`) | Misma tabla | **Implementada.** Lista las cargas con litros pero sin precio. |

**Coherencia verificada en producción el 24/11/2026** con la misma consulta que
usa el módulo (`CodeIgniterViajesDeclaradosReader::viajesEntre`):

| Ventana | Cargas que ve el módulo | Litros | Cargas en la tabla | Litros en la tabla | Coincide |
|---|---|---|---|---|---|
| 30 días | 8 | 2.812 | 8 | 2.812 | **Sí** |
| 90 días | 8 | 2.812 | 8 | 2.812 | **Sí** |

Cero cargas ocultas al módulo en ninguna de las dos ventanas. **La tabla de
combustible y el módulo de combustible coinciden.**

Lo que el módulo **no** muestra no es un problema de coherencia, sino de carga:
solo 1 de 42 viajes tiene combustible declarado, así que para los otros 41 la
conciliación dice "sin datos suficientes" con su motivo.

### Origen de las 8 cargas

Las cargó **el propio usuario desde la app**: `carlos.tsa`
(gimenezcarlosgabriel@gmail.com), el 19/11/2026 entre las 18:42 y las 18:48, en
seis cargas seguidas. No vinieron de un importador ni de un script.

### La segunda tabla de combustible: el historial importado

`viaje_combustible` (historial del ERP de transporte, **solo lectura**) tiene
**10.001 cargas, 4.291.109,92 L y 2.319.854.872,53 ARS**, de las cuales 771
filas vienen sin litros.

Los dos conjuntos **no se mezclan**: el operativo se escribe y calcula, el
histórico importado se solo lee. El módulo de conciliación trabaja sobre el
operativo; la ficha del viaje del historial muestra el histórico.

## 7. Pendientes

1. **Cargar el combustible de los viajes en curso.** Sin esto el margen que
   muestra el sistema no sirve para decidir. Es la brecha más grande.
2. **Cerrar los 33 viajes EN_CURSO de más de 3 días.** El backlog crece y
   ninguno tiene odómetro de salida/regreso.
3. **Completar los 22 fletes de liquidación** para poder cerrar (carga de la
   operación).
4. **Revisar las 5 cargas de combustible sin precio** del viaje 21 y decidir si
   el consumo de 39,46 L/100 km es real o error de carga.
5. **Repetir el smoke de staging en `fasa_189`** cuando la VPN vuelva: el host
   sigue inalcanzable desde esta máquina.
