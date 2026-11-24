# Módulo de Combustible del portal — 24/11/2026

**Decisión del cliente (24/11/2026):** la card "Combustible — Próximamente" del
portal pasa a ser el acceso a la conciliación que ya existe, **sin tabla nueva**.
El historial importado del ERP (10.001 cargas) **sigue separado**, como está hoy.

## 1. Qué se encontrado antes de decidir

La auditoría de producción (`docs/BITACORA_2026-11-24_AUDITORIA_PRODUCCION_VIAJES.md`)
medió dos cosas que seemed contradictorias:

- La card del portal decía "Combustible — Próximamente", y el módulo de
  plataforma no existía.
- En la base había **8 cargas de combustible** (2.812 L, 3.110.061 ARS), todas del
  viaje 21, cargadas por `carlos.tsa` desde la app el 19/11/2026.

La explicación es que **"Combustible" son dos cosas distintas**:

| Qué es | Dónde vive | Estado antes de este cambio |
|---|---|---|
| Módulo de plataforma "Combustible" ("Próximamente") | `GetPlatformModuleCatalog::FUTURE_MODULES['fuel']` — "Consumo, rendimientos y control de abastecimiento" | No implementado |
| Cargas de combustible del viaje | `viaje_operativo_combustible` | Implementado, con datos |
| Conciliación declarado contra sensor | Gerencial → Tablero de flota → pestaña "Combustible" | Implementado y vivo |

La especificación (§5.13, §6.12, Etapa 6) define `CargaCombustible` como
**entidad hija del agregado `Viaje`**: la tabla de combustible del sistema **es**
`viaje_operativo_combustible`, y no hay una tabla de módulo aparte. Por eso el
módulo se implementó **sobre esa tabla**: una sola fuente de verdad.

La auditoría verificó la coherencia en producción antes de tocar nada: la
conciliación y la tabla dan **8 cargas y 2.812 L** en las ventanas de 30 y de 90
días, con **cero cargas ocultas** al módulo.

## 2. Qué se implementó

**Backend (contexto Viajes):**

| Pieza | Archivo | Qué hace |
|---|---|---|
| Puerto `FuelPanelReader` | `app/Application/Trips/Port/FuelPanelReader.php` | Lectura de las cargas del viaje |
| Caso de uso `GetPanelCombustible` | `app/Application/Trips/GetPanelCombustible.php` | Totales, cargas por viaje y **el motivo** cuando falta un dato |
| Adaptador `CodeIgniterFuelPanelReader` | `app/Infrastructure/Trips/CodeIgniterFuelPanelReader.php` | Lee `viaje_operativo_combustible` con `empresa_id` en todas las tablas |
| Controller `FuelModule` | `app/Controllers/FuelModule.php` | Pantalla de solo lectura: `GET viajes/combustible` (`permission:viajes.ver`) |

El caso de uso informa, para cada viaje, por qué no hay datos:

- "Todavía no se registraron cargas de combustible en este viaje."
- "Falta el precio de 5 de las 8 cargas: 1.431 L sin valorar."
- "El viaje no tiene odómetro de salida y de regreso: no se puede calcular el consumo."
- "El viaje no tiene fecha de salida real cargada." (se listan aparte)

Nunca un cero que parezca medido (regla 32 de la especificación).

**Plataforma y navegación:**

- La card "Combustible" del portal pasa a **Operativo** para las cuentas con
  `viajes.ver` y su entrada es `viajes/combustible`.
- El módulo tiene navegación propia ("Combustible" y "Combustible sin precio") y
  **retorno visible al portal**.
- La conciliación con el sensor se muestra solo con `gerencial.flota.ver`; sin ese
  permiso el módulo dice que no tiene ese dato, en lugar de inventar la comparación.

**Frontend:**

- `frontend/src/pages/operations/FuelModulePage.vue`: la pantalla del módulo.
- `frontend/src/components/FuelReconciliationTable.vue`: la tabla de conciliación
  **extraída** del tablero de flota, para que las dos pantallas muestren la misma
  comparación sin duplicar el componente.

## 3. Lo que NO se hizo

- **No se creó una tabla de combustible para el módulo.** La carga pertenece al
  viaje; el módulo la muestra. Dos tablas serían dos verdades.
- **No se mezcló el historial importado del ERP** (`viaje_combustible`, 10.001
  cargas, 4.291.109,92 L). Sigue siendo de solo lectura y no alimenta el módulo.
- **No se imputó ningún gasto ni carga a un tramo.** Es decisión operativa.
- **No se cargó ni se completó combustible.** El módulo muestra lo que hay.

## 4. Evidencia

- PHPUnit: casos de uso, adaptador (SQLite con los tipos reales de las
  migraciones), catálogo de plataforma y la ruta HTTP.
- Vitest: la pantalla del módulo y la extracción de la tabla de conciliación
  (los tests del tablero de flota siguen en verde).
- La tabla y el módulo coinciden: medido en producción el 24/11/2026, con el
  camino real del módulo (composition root) contra MySQL. Las cinco medidas
  comparadas dan idénticas: 8 cargas, 2.812 L, 3.110.061 ARS, 5 cargas y 1.431 L
  sin precio. Ver `docs/BITACORA_2026-11-24_VERIFICACION_MODULO_COMBUSTIBLE_PRODUCCION.md`.

## 5. Pendientes

1. **Cargar el combustible de los viajes en curso.** Con 41 de 42 viajes sin
   combustible, el módulo muestra "sin datos" y los márgenes están inflados. Es
   la brecha más grande y es carga de la operación. La conciliación medió que
   **13 de esos viajes tienen cargas vistas por el sensor y no declaradas**
   (9.926,6 L en 37 cargas): son la prueba de que el combustible se cargó y no se
   registró.
2. **Completar los 5 precios pendientes** del viaje 21 (1.431 L) desde la bandeja
   «Combustible sin precio».
3. **Repetir el smoke de staging en `fasa_189`** cuando la VPN vuelva: el host
   sigue inalcanzable desde esta máquina.
4. **Revisión visual de la pantalla autenticada** por parte del cliente: desde la
   máquina de desarrollo no hay sesión de usuario de la app.
