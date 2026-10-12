# Implementar costo teórico por km dentro de Gerencial

> **Para agentes que ejecuten este plan:** aplicar `superpowers:subagent-driven-development` con un responsable por bounded context y `superpowers:test-driven-development` en cada tarea. Usar `clean-ddd-hexagonal` según `AGENTS.md`.

**Goal:** entregar dentro de Gerencial una calculadora teórica de costo/km y tarifa sugerida en ARS/USD, con escenarios versionados, historial y exportación XLSX, accesible solo a personas autorizadas.

**Architecture:** bounded context `TheoreticalCost`. Dominio puro para cálculo de combustible, neumáticos, depreciación tractor/semi, costos mensuales, mantenimiento porcentual, recargo y simulación de viaje. Casos de uso en Application; puertos para escenarios, activos y exportador; adaptadores CI4/DB y PhpSpreadsheet en Infrastructure; rutas/controller y Vue bajo Gerencial en Presentation. Escenarios versionados e inmutables, importes DECIMAL, scope obligatorio por empresa y sucursales permitidas. Sin integración de gastos reales ni de tarifas automáticas de FADEEAC.

**Tech Stack:** CodeIgniter 4, PHP 8.2+, MariaDB/MySQL, Vue 3/Vite, PhpSpreadsheet (ya es dependencia Composer), PHPUnit y Vitest.

**Spec:** `docs/superpowers/specs/2026-10-12-costo-km-teorico-gerencial-design.md`.

**Global constraints:** preservar todos los cambios locales existentes. No crear tarjeta de plataforma ni alterar el contenido de costo real del dashboard. Validar autorización y tenant en servidor. No representar parámetros faltantes como cero. XLSX debe reflejar la versión guardada, no el borrador actual. No usar FLOAT/DOUBLE. No probar con PHP/DB local ni desplegar; pruebas integradas solo después de identificar stack de staging `fasa_189` según AGENTS.

## Review Focus

1. Cero/null en km mensuales, km facturables, vida útil o cotización: pruebas de dominio impiden división/conversión inválida y el endpoint no publica total completo.
2. Mantenimiento cercano o igual a 100 %, o reparaciones ya incluidas: pruebas verifican rango `[0, 100%)` y evitan doble cómputo.
3. Equipo de otra empresa/sucursal no autorizada: pruebas de caso de uso y HTTP demuestran rechazo aunque se manipulen IDs.
4. Guardar nueva cotización/versión no altera escenarios previos: pruebas de persistencia reabren la versión histórica.
5. Excel conserva cobertura parcial y datos guardados y neutraliza texto que pudiera abrirse como fórmula: pruebas del exportador.

## Tasks

1. **Dominio de costo teórico y casos de uso** (`app/Domain/TheoreticalCost/**`, `app/Application/TheoreticalCost/**`, `tests/unit/Domain/TheoreticalCost/**`, `tests/unit/Application/TheoreticalCost/**`): definir valores, unidades, invariantes, desglose y resultado parcial; crear tests primero para fórmulas, redondeo, inputs faltantes y edge cases; después implementar calculador puro y casos de uso con puertos.
2. **Persistencia, acceso, rutas y composición** (`app/Database/Migrations/**`, `app/Database/Seeds/InitialSeeder.php`, `app/Infrastructure/TheoreticalCost/**`, `app/Controllers/TheoreticalCosts.php`, `app/Config/Routes.php`, `app/Config/Services.php`, `app/Presentation/AppShellPayload.php`): migración reproducible de escenarios/versiones; permisos explícitos `gerencial.costo_km.ver` y `.editar`; acceso de Administrador por defecto y rol asignable “Gerencial”; filtros en rutas y validación de empresa/sucursal en cada caso de uso; endpoints para cargar, calcular, listar, abrir, guardar versión y descargar XLSX.
3. **Exportación XLSX** (`app/Infrastructure/TheoreticalCost/**`, `tests/unit/Infrastructure/TheoreticalCost/**`): hojas Resumen, Parámetros, Detalle de costos y Viaje; exportar solo una versión guardada; probar cobertura parcial y neutralización de texto/formulas.
4. **Pantalla Gerencial** (`frontend/src/pages/gerencia/**`, `frontend/src/pages/gerencia/index.js`, `frontend/src/main.js`, `frontend/tests/gerencia/**`, integración contextual en `ManagerialDashboard.vue` si corresponde): agregar entrada “Costo teórico por km” dentro de Gerencial existente, editor de supuestos, doble moneda, cobertura/faltantes, simulador, guardado e historial; usar URLs del payload y respetar permisos de lectura/configuración.
5. **Revisión integral y documentación**: contrastar criterios de aceptación de la spec con dominio, HTTP, aislamiento, UX responsive e historial; actualizar guía funcional/operativa sin tocar cambios preexistentes no relacionados; ejecutar solo las verificaciones autorizadas en el destino correcto y reportar evidencia.

## Execution and review

La implementación inicia por el dominio y los contratos HTTP; frontend y persistencia se coordinan sobre ese contrato. Cada bounded context tiene un responsable y el integrador revisa aislamiento, consistencia matemática y alcance. Tras integrar, revisar el diff completo y conservar intactos archivos preexistentes modificados/no relacionados. No afirmar tests, aceptación visual, staging ni deploy sin evidencia específica.
