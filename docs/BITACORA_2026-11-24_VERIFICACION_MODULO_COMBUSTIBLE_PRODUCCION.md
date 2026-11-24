# Verificación del módulo de Combustible en producción — 24/11/2026

Continuación de `docs/BITACORA_2026-11-24_MODULO_COMBUSTIBLE.md`. El módulo se
desplegó y quedó activo; esta bitácora registra **la verificación contra la base
real** y un hallazgo operativo que la conciliación dejó al descubierto.

## 1. Qué se verificó y cómo

El módulo ya estaba desplegado en Ferozo (`cc4b3083`). Lo que faltaba era
comprobar que **el camino real de producción** —el mismo composition root que usa
el controller— lee lo mismo que la tabla, contra MySQL y no solo contra el SQLite
de los tests.

Se subió un **script temporal de solo lectura** a la raíz del release, protegido con
el header `X-Migrate-Token`, que:

1. Resuelve los casos de uso por el composition root real:
   `service('panelCombustible')` y `service('conciliacionCombustible')` — los
   mismos que usa `app/Controllers/FuelModule.php`.
2. Construye un `ActorContext` sintético de la **empresa 4 (TSA)** con los tres
   permisos que el módulo usa: `viajes.ver`, `viajes.operar` y
   `gerencial.flota.ver`, y acceso a todas las sucursales.
3. Corre un `SELECT` directo sobre `viaje_operativo_combustible` con la misma
   ventana de período que aplica el caso de uso, y coteja los dos resultados.
4. Devuelve el informe en JSON.

No escribe nada. El script se borró del release al terminar y quedó verificado
como `404`.

## 2. Resultado: la tabla y el módulo coinciden

Motor: **MySQLi** (MySQL de producción, no SQLite).

| Medida | Tabla (`viaje_operativo_combustible`) | Módulo (panel) | ¿Coincide? |
|---|---|---|---|
| Cargas | 8 | 8 | Sí |
| Litros | 2.812,00 | 2.812 | Sí |
| Importe ARS | 3.110.061,00 | 3.110.061 | Sí |
| Cargas sin precio | 5 | 5 | Sí |
| Litros sin precio | 1.431,00 | 1.431 | Sí |

**Las cinco comparaciones dan verdadero** (`coincide_total: true`): lo que muestra
el módulo es exactamente lo que hay cargado en la tabla, y no hay cargas ocultas
al módulo. La regla del cliente —"por más que salgan de viajes deben quedar
registradas en la tabla de combustible y en el módulo de combustible debe
coincidir"— queda verificada contra la base de producción.

El panel, en la ventana de 30 días, muestra **40 viajes**: 1 con combustible (el
viaje 21, FINALIZADO, 7.127 km) y 39 sin cargas, cada uno con su motivo. Ningún
viaje de la ventana está sin fecha de salida real.

## 3. Hallazgo: cargas físicas que nadie declaró

La conciliación con el sensor del tanque devolvió **40 viajes, 0 con veredicto**
(39 `EN_CURSO` y 1 `SIN_DATOS_SUFICIENTES`), porque en un viaje que no terminó no
hay lectura del tanque al volver y no corresponde comparar.

Pero el detalle sí muestra algo accionable: **13 viajes tienen cargas detectadas
por el sensor y ninguna carga declarada**.

| Medida | Valor |
|---|---|
| Viajes con carga detectada y sin carga declarada | 13 |
| Litros detectados en esos viajes | **9.926,6 L** |
| Cargas detectadas en esos viajes | **37** |
| Litros declarados en esos viajes | 0 |
| Cargas declaradas en esos viajes | 0 |

Los mayores:

| Viaje | Patente | Litros detectados | Cargas | Cobertura del sensor |
|---|---|---|---|---|
| 5 | AB499OK | 1.940,5 L | 7 | 100% |
| 6 | AC532DD | 1.903,3 L | 6 | 98% |
| 7 | AE223WN | 1.257,9 L | 3 | 89% |
| 16 | HWW968 | 1.045,5 L | 4 | 81% |
| 29 | AC532DD | 870,4 L | 3 | 97% |
| 15 | HLD283 | 639,1 L | 5 | 100% |
| 2 | AG007VR | 560,4 L | 2 | 100% |
| 40 | AE223WN | 468,2 L | 1 | 99% |

**Cómo se lee esto:** el tanque se llenó y el sensor lo vio, pero esas cargas no
están en `viaje_operativo_combustible`. El módulo de Combustible es exactamente la
pantalla que lo muestra: declarado en un lado, medido en el otro, con el motivo de
por qué todavía no hay veredicto. La medición es **parcial** (el viaje no terminó,
no hay lectura de regreso), así que el sistema **no señala a nadie**: informa.

El caso contrario también se ve: en el viaje 21, el único con cargas declaradas
(2.812 L en 8 cargas), el sensor solo cubrió el 46% del viaje y detectó 911 L en 3
cargas. Con esa cobertura no hay comparación posible, y el módulo lo dice en vez
de calcular una diferencia falsa.

## 4. Limpieza de producción

- Script temporal borrado del release: `HTTP 404` en
  `verificar-modulo-combustible.php`.
- Raíz del release: **21 entradas**, sin archivos temporales. `preload.php` es el
  único archivo que no es de aplicación y ya estaba antes.
- No se hizo ninguna escritura en la base: el script era de solo lectura y la
  operación no usa el modo `apply`.

## 5. Pendientes

1. **Cargar las 37 cargas que el sensor vio y nadie declaró** (9.926,6 L en 13
   viajes). Es carga de la operación, pero hasta entonces el margen de esos viajes
   sigue inflado: el módulo lo muestra como "sin datos" con su motivo.
2. **Completar los 5 precios del viaje 21** (1.431 L) desde la bandeja
   «Combustible sin precio».
3. **Cerrar los 33 viajes EN_CURSO con 3 días o más** y cargar sus odómetros:
   sin odómetro de salida y de regreso no se puede calcular el consumo ni
   cerrar la conciliación.
4. **Revisión visual de Carlos** en
   `https://vogelconsultoria.com.ar/mantenimiento/viajes/combustible`: desde esta
   máquina no hay sesión de usuario, así que no se pudo comprobar el render
   autenticado de la pantalla.
5. **Repetir el smoke de staging en `fasa_189`** cuando la VPN vuelva: el host
   sigue inalcanzable desde esta máquina.
