# FASE 2 - Circuito vertical preventivo

## Objetivo

Implementar el primer recorrido funcional completo acordado:

```text
Equipo -> Lectura -> Plan -> Vencimiento -> Aviso -> OT -> Cierre -> Recalculo
```

La entrega mantiene el monolito modular CodeIgniter 4. Los contextos de
Activos, Medición, Preventivo y Órdenes tienen dominio, casos de uso, puertos y
adaptadores separados. Los dos cruces que requieren consistencia inmediata
(generación de OT y cierre) se coordinan en una transacción común; no se usan
eventos asíncronos.

## Alcance implementado

- alta de un equipo dentro de una empresa y sucursal autorizada;
- carga histórica de kilometraje u horómetro y actualización atómica del
  valor actual del equipo;
- plan preventivo por kilómetros, horas, fecha o una combinación;
- cálculo puro de `SIN_DATOS`, `AL_DIA`, `PROXIMO` y `VENCIDO`;
- aviso idempotente únicamente para un ciclo `VENCIDO`;
- OT preventiva numerada `OT-AAAA-NNNNNN`, con snapshot de tareas;
- transición `EMITIDA -> EN_PROCESO -> FINALIZADA`;
- cierre con trabajo realizado, lectura de salida y recálculo del plan dentro
  de una sola transacción;
- pantalla Bootstrap responsive renderizada por el servidor;
- permisos y scope de empresa/sucursal aplicados en rutas, casos de uso y
  consultas de persistencia.

Quedan fuera de esta entrega el correctivo, solicitudes, espera/reanudación en
la interfaz, repuestos, costos, adjuntos, QR, importaciones, PDF y alertas por
correo. Algunas reglas de esos estados ya existen en el dominio de Órdenes,
pero todavía no se publican como flujo visual.

## Esquema incremental

Las migraciones nuevas son:

- `110012`: permisos `lecturas.ver` y `lecturas.corregir`;
- `110020-110022`: `tipos_equipo`, `equipos`, `lecturas_equipo`;
- `110030-110034`: catálogos preventivos, planes y avisos;
- `110040-110043`: numeradores, órdenes, tareas e historial de estados.

Las claves compuestas `(empresa_id, id)` permiten que las FKs tenant impidan
vincular datos de empresas diferentes. La unicidad del aviso por ciclo y de
`ordenes_trabajo.aviso_plan_id` evita duplicados concurrentes.

## Probar localmente

Con MariaDB y el servidor local activos:

```powershell
php spark migrate --all
php spark db:seed VerticalCircuitSeeder
php -S 127.0.0.1:8080 index.php
```

Abrir `http://127.0.0.1:8080/login` e ingresar como administrador local:

```text
admin@mantenimiento.local
Admin1234
```

Desde el dashboard, seleccionar **Abrir circuito**. Para provocar un
vencimiento por kilómetros de forma simple:

1. Crear un Camión.
2. Registrar `10000` km.
3. Asignar Cambio de aceite cada `1000` km, con anticipación `100` km.
4. Registrar una nueva lectura de `11000` km.
5. Ejecutar **Detectar vencidos**.
6. Generar la OT y luego iniciarla.
7. Cerrarla con `11010` km.
8. Confirmar que queda finalizada y que el próximo objetivo pasa a `12010` km.

El seeder es idempotente y solo agrega los catálogos técnicos mínimos para
esta prueba. No contiene credenciales de producción.

## Evidencia local del 2026-08-08

- PHPUnit: 85 pruebas y 203 aserciones, sin fallos;
- lint PHP: 221 archivos sin errores;
- rutas CI4: ocho rutas del circuito publicadas con auth, CSRF y permisos;
- HTTP real: `/mantenimiento` respondió 200;
- E2E real sobre MariaDB: `OT-2026-000001` quedó `FINALIZADA`, el equipo quedó
  en `11010` km, la base del plan en `11010`, el próximo servicio en `12010`,
  el aviso en `CONVERTIDO` y se conservaron tres lecturas;
- una segunda conversión del mismo aviso fue rechazada y siguió existiendo una
  sola OT; el Superadministrador recibió HTTP 403 en el circuito tenant;
- la conexión automatizada al navegador no estuvo disponible, por lo que la
  aceptación visual de escritorio y móvil queda pendiente de revisión manual.

No se ejecutó despliegue, push ni cambio alguno en Ferozo.
