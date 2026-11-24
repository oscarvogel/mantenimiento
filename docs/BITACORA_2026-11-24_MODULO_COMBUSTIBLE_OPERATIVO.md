# Módulo de Combustible operativo — 24/11/2026

**Pedido de Carlos (24/11/2026), sobre la pantalla en producción:** *"la verdad no
se que queres mostrar aca, pero esto deberia reflejar el combustible cargado en los
viajes, con el consumo promedio, filtrar por fecha y camion y poder editar si no
tiene cargado el monto"*.

Continuación de `docs/BITACORA_2026-11-24_MODULO_COMBUSTIBLE.md` y de
`docs/BITACORA_2026-11-24_VERIFICACION_MODULO_COMBUSTIBLE_PRODUCCION.md`.

## 1. Qué estaba mal

La pantalla mostraba **conciliación**, no combustible:

- El bloque principal era "Conciliación declarada por viaje" con el motivo de cada
  fila, que es información de la conciliación, no del combustible cargado.
- Los KPI eran de conciliación y no había **consumo**.
- No había forma de filtrar: sólo un selector de "últimos N días".
- La única forma de corregir un dato era salir del módulo, ir a la ficha del viaje o
  a la bandeja «Combustible sin precio».

El módulo ya tenía la lectura correcta (la tabla de cargas del viaje), pero la
pantalla no la mostraba como la tiene que ver quien carga el combustible todos los
días.

## 2. Decisiones del cliente

Respondidas antes de implementar:

| Pregunta | Respuesta |
|---|---|
| ¿Qué se puede editar desde el módulo? | **Las dos cosas**: cargar una carga nueva en los viajes que no tienen, y completar el importe de las cargas que lo esperan. |
| ¿Dónde queda la conciliación con el sensor? | En su **propia pestaña**, separada de la vista operativa. |
| ¿Con qué datos se calcula el consumo promedio? | **Sólo con los viajes que tienen odómetro** (declarado), no con el del sensor. |

## 3. Qué se implementó

### 3.1 La pantalla

```
Combustible
├─ Filtros: Desde [fecha] Hasta [fecha] Camión [combo con búsqueda]  [Aplicar] [Limpiar]
├─ KPIs: Litros cargados · Importe cargado · Consumo promedio · Viajes sin combustible
└─ Tabla: Fecha │ Camión │ Viaje │ Litros │ Importe │ Km │ Consumo │ Pendiente │ Acciones
```

- **Consumo promedio**: el de la flota, con la misma regla que el tablero de viajes
  operativos (`TripConsumption::litersPer100Km`: litros sobre kilómetros de los viajes
  medidos) y **la base a la vista**: "39,5 L/100 km · 1 viaje medido · 7.127 km". Sin
  odómetro de salida y de regreso no hay consumo: dice "Sin datos", nunca cero.
- **Filtros**: por rango de fechas y por camión. El camión se elige con el combo de
  búsqueda que ya usa el tablero (`AutocompleteSelect`), no con valores libres.
- **Acciones por fila** (con `viajes.operar`):
  - **Cargar**: abre el formulario de carga (litros, importe, moneda, cotización,
    odómetro, estación, comprobante, observaciones) contra el viaje.
  - **Completar importe**: abre las cargas de ese viaje que esperan su precio, con
    precio por litro e importe total que se calculan uno del otro.
- Un viaje **finalizado** no ofrece "Cargar": de él sólo se completa el precio.

### 3.2 El período

Se mantiene la regla de superposición que ya usaba el módulo y que usa la
conciliación: entra un viaje que **empezó antes del final del período y terminó
después del inicio**, o que sigue en curso. Un viaje abierto de hace 40 días sigue
consumiendo hoy y tiene que estar a la vista.

El rango máximo es de un año; las fechas inválidas se rechazan con un mensaje
corrigible, no con un "últimos 30 días" en silencio.

### 3.3 La conciliación, aparte

La conciliación con el sensor del tanque pasó a su propia pestaña, visible sólo con
`gerencial.flota.ver`. Es un dato gerencial: sin ese permiso, el módulo no la muestra
y no inventa una comparación.

### 3.4 Backend

| Pieza | Archivo | Qué hace |
|---|---|---|
| Filtros | `app/Application/Trips/FuelPanelFilters.php` | Período (desde/hasta), camión y sucursal, con validación |
| Puerto | `app/Application/Trips/Port/FuelPanelReader.php` | Lectura con filtros + cargas pendientes + camiones para el filtro |
| Caso de uso | `app/Application/Trips/GetPanelCombustible.php` | Totales, consumo por viaje y de flota, pendientes, motivos |
| Adaptador | `app/Infrastructure/Trips/CodeIgniterFuelPanelReader.php` | Filtra por camión (tractor asignado) y lista las cargas sin precio |
| Monedas | `app/Application/Trips/ListTripCurrencies.php` | Monedas y cotizaciones de la empresa para cargar en la moneda real |
| Controller | `app/Controllers/FuelModule.php` | Pantalla + `POST viajes/combustible/(:num)/carga` + `POST viajes/combustible/precios` |
| Parser de precios | `app/Application/Trips/FuelPriceEntry.php` | Lee `importe[id]`, `moneda[id]`, `cotizacion[id]` (compartido con la bandeja global) |
| Helpers de entrada | `app/Controllers/Traits/InterpretsTripCostInput.php` | Lectura de los POST de los movimientos del viaje, compartida con la ficha del viaje |

**El módulo no tiene tabla propia ni modelo propio**: la carga se registra con
`RegisterTripFuel` y el precio se completa con `CompleteFuelPrices`, los mismos casos
de uso que usa la ficha del viaje. La tabla de combustible del sistema sigue siendo
`viaje_operativo_combustible`, y el historial importado del ERP sigue separado.

## 4. Lo que NO se hizo

- **No se creó ninguna tabla** ni se cambió el esquema.
- **No se imputó ninguna carga a un tramo** desde el módulo: es decisión operativa y
  se sigue haciendo desde la ficha del viaje.
- **No se tocó el consumo de la conciliación**: la pestaña gerencial sigue midiendo
  contra el sensor.
- **No se cargó ni se completó combustible**: el módulo deja capturar; cargar las
  37 cargas que el sensor vio y nadie declaró sigue siendo tarea de la operación.

## 5. Evidencia

- PHPUnit: **2197 pruebas OK** (51 skipped, 8364 aserciones). Incluye los casos
  nuevos de consumo promedio, de pendientes, de filtros y del parser de precios.
- Vitest: **555 pruebas OK** (92 archivos), con 11 casos de la pantalla nueva.
- Build del frontend y `verify:build-sync` en verde.

## 6. Pendientes

1. **Cargar el combustible que falta** en los viajes en curso. Con la pantalla
   operativa y el botón a mano, la brecha de 37 cargas (9.926,6 L) deja de ser un
   trabajo de planillas.
2. **Completar los 5 precios del viaje 21** (1.431 L): ahora se puede desde la fila
   del viaje, no sólo desde la bandeja global.
3. **Cerrar los 33 EN_CURSO con 3 días o más** y cargar sus odómetros: hasta que
   existan, el consumo promedio se apoya en un solo viaje.
4. **Revisión visual de Carlos** en `https://vogelconsultoria.com.ar/mantenimiento/viajes/combustible`:
   desde la máquina de desarrollo no hay sesión de usuario de la app.
5. **Repetir el smoke de staging en `fasa_189`** cuando la VPN vuelva: el host sigue
   inalcanzable desde esta máquina.
