# Alta de viajes con inicio directo - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Al guardar la ficha de un viaje aprobado, el sistema lo persiste directamente como `EN_CURSO` con fecha y odómetro reales de salida, sin una etapa operativa de planificación.

**Architecture:** Presentation recoge salida real, regreso estimado y kilometraje obligatorio. El caso de uso crea el agregado y lo inicia dentro de la transacción de alta antes de persistirlo; la transición histórica `PLANIFICADO -> EN_CURSO` sigue disponible para registros ya existentes. La UI confirma el inicio y dirige al detalle del viaje abierto.

**Tech Stack:** CodeIgniter 4, PHP 8.2+, dominio `App\Domain\Trips`, frontend Vue 3, PHPUnit y Vitest.

**Spec:** `docs/ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md`; decisión operativa explícita de Mariela comunicada por el usuario el 2026-10-29.

## Global Constraints

- Mantener `Presentation / Infrastructure -> Application -> Domain`.
- Mantener el inicio dentro de la misma transacción que reserva numeración y crea viaje/asignaciones/paradas.
- No modificar el odómetro del equipo al iniciar; el cierre sigue generando la lectura de equipo con origen `VIAJE`.
- Conservar los viajes históricos que ya estén `PLANIFICADO`, sin migrarlos ni alterar su trazabilidad.
- Validar empresa, sucursal, equipo, choferes y conflictos existentes antes de persistir.

## Review Focus

- Odómetro vacío o negativo: alta rechazada con mensaje útil; no se crea viaje parcial.
- Fecha real de salida posterior al regreso estimado: rechazar el formulario.
- Conflicto de equipo o chofer: no reservar numeración ni persistir asignaciones/paradas.
- Falla de persistencia: la transacción revierte todo el alta.
- Ficha válida: `estado=EN_CURSO`, fecha real y km de salida presentes; la lectura del equipo no cambia.

### Task 1: Crear viajes aprobados ya iniciados

**Files:**
- Modify: `app/Application/Trips/CreateTripCommand.php`
- Modify: `app/Application/Trips/CreateTrip.php`
- Modify: `app/Controllers/OperationalTrips.php`
- Modify: `frontend/src/pages/operations/TripOperationsCreatePage.vue`
- Test: `tests/unit/Application/Trips/CreateTripTest.php`
- Test: `frontend/tests/operations/TripOperationsCreatePage.test.js`
- Update: `docs/ESPECIFICACION_VIAJES_CIRCUITO_LIQUIDACION.md`

- [x] Agregar pruebas que demuestren que la alta persiste `EN_CURSO` con salida real y km, y que el formulario presenta una acción de inicio directo y exige el odómetro.
- [x] Ejecutar ambas pruebas y confirmar el fallo esperado.
- [x] Cambiar el alta transaccional para iniciar el agregado antes de persistirlo; mapear campos UI a salida real y regreso estimado.
- [x] Actualizar interfaz y mensajes para eliminar la acción de negocio “Planificar viaje”; mantener la acción separada de inicio solo para viajes `PLANIFICADO` preexistentes.
- [x] Actualizar la especificación operativa y regenerar el manual para reflejar el flujo implementado.
- [x] Ejecutar las pruebas dirigidas y las verificaciones de integración disponibles; inspeccionar la UI y el PDF regenerado.
- [ ] Ejecutar las pruebas dirigidas y las verificaciones de integración disponibles; inspeccionar la UI y el PDF regenerado.
