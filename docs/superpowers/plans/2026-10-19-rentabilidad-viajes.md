# Plan: facturación histórica y rentabilidad de viajes

## Decisión del dueño

- **Ingreso:** facturación histórica importada (`a18`/`a19`), no el flete de tramo (`t06`).
- **Costos:** solo directos del viaje: combustible, gastos, viáticos/otros haberes y porcentaje del chofer.
- **Fuera de alcance:** mantenimiento, neumáticos, seguros, depreciación y costos fijos.

## Vínculo factura-viaje

El ERP legacy no trae una clave directa entre factura y viaje. El vínculo se resuelve
con la única evidencia disponible: unidad, fecha, origen/destino e importe.

- `ALTA`: unidad + fecha + ruta + importe compatible. Se vincula automáticamente.
- `MEDIA`: coincidencia razonable, pero requiere revisión humana.
- `BAJA`/sin candidato: no se vincula; queda para búsqueda manual.
- Una factura tiene como máximo un vínculo vigente. Se puede confirmar, cambiar o quitar.
- Solo `AUTO` y `CONFIRMADO` se usan para el análisis.

## Fórmula

```text
Ingreso        = Σ facturas vinculadas (total ARS con signo; notas de crédito restan)
Costo directo  = combustible + gastos + viáticos/otros haberes + chofer
Chofer         = flete de tramo legacy × porcentaje_chofer / 100
Margen         = Ingreso - Costo directo
Margen %       = Margen / Ingreso × 100
```

Si falta factura, costo o flete/porcentaje del chofer, el viaje queda como
`SIN_FACTURA`, `SIN_COSTOS` o `SIN_FLETE` y no se muestra un margen inventado.
La cobertura de factura se informa siempre.

## Paquete

`scripts/legacy-transporte-build.php` incluye `a18_comprobantes_de_venta` y
`a19_detalle_comprobantes_de_venta` dentro del alcance `viajes` (versión 1.1.0).
Los paquetes viejos siguen importando viajes, pero el análisis de rentabilidad queda
sin datos hasta cargar un paquete con facturación.

## Validación

- Pruebas del matcher: alta, media y sin candidato.
- Pruebas del caso de uso: margen completo, sin factura, sin costos.
- Pruebas del read model con SQLite.
- Pruebas de importación: facturas, líneas y vínculo automático.
- Pruebas frontend de la pantalla de rentabilidad.
- Migración de facturas probada en SQLite; validación final pendiente en `fasa_189`.
