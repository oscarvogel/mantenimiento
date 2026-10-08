# Referencias visuales — Centro de Mandos Vogel

**Fecha:** 2026-10-08  
**Estado:** propuesta visual, pendiente de validación de diseño. No hay implementación aprobada.
**Relacionado con:** [Arquitectura de Plataforma de Gestión Operativa](../ARQUITECTURA_PLATAFORMA_GESTION_OPERATIVA.md).

## Las tres propuestas visuales (archivos originales externos a esta rama)

1. **Portal de módulos, escritorio** (`portal-desktop.png`): fondo azul noche, logo Vogel Consultoría, cabecera con imagen temática de transporte, tarjetas de seis módulos, KPIs y centro de alertas. Es la **referencia principal del futuro inicio / centro de mandos**.
2. **Mantenimiento, escritorio** (`mantenimiento-desktop.png`): navegación superior por módulo, sidebar propio de Mantenimiento, área principal clara, métricas, órdenes, gráficos, alertas y acciones rápidas. Es la **referencia para entrar a Mantenimiento desde el portal**, manteniendo todas las funciones existentes.
3. **Portal móvil** (`portal-mobile.png`): inicio responsive con tarjetas de módulos, indicadores y alertas, navegación inferior compacta. Es la **referencia para celulares**.

> **Estado de los binarios:** los PNG originales se generaron en la conversación de diseño y se entregan aparte para incorporación al repositorio. Este documento no pretende que las imágenes ya estén versionadas en GitHub. No se deben inventar enlaces relativos que aún no existen.

## Requisitos visuales que se deben respetar

- Identidad basada en el **logo proporcionado de Vogel Consultoría**: azul marino profundo, azul intenso, blanco y acentos amarillos muy contenidos.
- Diseño corporativo y tecnológico, legible y sobrio; gradientes y fotografías discretos, nunca a costa de las tareas operativas.
- **Inicio independiente del módulo Mantenimiento**: el portal presenta módulos y estado global; dentro de Mantenimiento, conservar los flujos actuales.
- **Tarjetas de módulo**: Mantenimiento, Viajes, Combustible, Neumáticos, Facturación y Gerencial; mostrar solo lo autorizado y habilitado, no tarjetas funcionales falsas.
- Menú por módulo en escritorio y navegación compacta en móvil.
- KPIs **solo con datos existentes**, permiso apropiado y fuente confiable; no usar métricas ficticias en producción.
- Alertas accionables basadas en permisos y datos reales; no alertas decorativas.
- Soportar tema claro/oscuro y contraste accesible. La propuesta oscura es inspiración de marca, no obligación de mostrar todas las pantallas oscuras.
- Evitar traducciones erróneas: utilizar **facturación/comprobantes argentinos**, nunca CFDI u otras denominaciones ajenas a Argentina.
- Nombre de persona, fechas y cifras mostradas en los conceptos son **ilustrativos**, no datos reales.
- El logo del material generado debe contrastarse con el SVG/activo oficial existente; nunca reemplazar el logo oficial con una interpretación defectuosa de una imagen generada.

## Criterios de aceptación visual futura

1. Comparar lado a lado la pantalla implementada con el concepto visual aprobado (escritorio y móvil).
2. Mantener jerarquía, distribución, espaciado, colores de marca, accesibilidad y navegación general.
3. Corregir incongruencias de textos/monedas/fotografías que las imágenes conceptuales puedan contener.
4. Confirmar que las tareas actuales del usuario siguen siendo rápidas y el nuevo portal no agrega pasos obligatorios innecesarios.
5. Verificar permisos, enlaces profundos, QR, cron y compatibilidad de rutas; la fidelidad visual **no sustituye** las pruebas funcionales.
6. No desplegar en Ferozo antes de validar en Coolify.

## Próxima acción documental

Agregar los originales a `docs/referencias-visuales/` conservando los tres nombres, e insertar las vistas previas aquí. Esta incorporación **no está realizada** hasta verificar los binarios en GitHub.
