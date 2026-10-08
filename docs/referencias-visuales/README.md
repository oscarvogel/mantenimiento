# Referencias visuales — Centro de Mandos Vogel

**Fecha:** 2026-10-08  
**Estado:** propuesta visual, pendiente de validación de diseño. No hay implementación aprobada.
**Relacionado con:** [Arquitectura de Plataforma de Gestión Operativa](../ARQUITECTURA_PLATAFORMA_GESTION_OPERATIVA.md).

## Las tres propuestas visuales (archivos originales externos a esta rama)

1. **Portal de módulos, escritorio** (`portal-desktop.png`): fondo azul noche, logo Vogel Consultoría, cabecera con imagen temática de transporte, tarjetas de seis módulos, KPIs y centro de alertas. Es la **referencia principal del futuro inicio / centro de mandos**.
2. **Mantenimiento, escritorio** (`mantenimiento-desktop.png`): **REFERENCIA DESCARTADA PARA IMPLEMENTACIÓN**. Fue una exploración visual generada que NO coincide con el sistema vigente: cambia tema, layout, gráficos, menú e indicadores. No debe usarse como objetivo de UI ni para sustituir el dashboard actual. Se conserva exclusivamente como antecedente histórico del proceso de diseño.
3. **Portal móvil** (`portal-mobile.png`): inicio responsive con tarjetas de módulos, indicadores y alertas, navegación inferior compacta. Es la **referencia para celulares**.

> **Estado de los binarios:** los PNG originales se generaron en la conversación de diseño y se entregan aparte para incorporación al repositorio. Este documento no pretende que las imágenes ya estén versionadas en GitHub. No se deben inventar enlaces relativos que aún no existen.

## Corrección prioritaria — 2026-10-08

**Fuente de verdad para Mantenimiento: la pantalla real de la aplicación vigente, no la propuesta generada.** El usuario comparó la imagen conceptual clara con una captura real del dashboard oscuro que ya incluye tarjetas de Equipos activos, Cumplimiento, Preventivos vencidos, OT abiertas y Lecturas pendientes; Resumen financiero del mes, evolución de costos, Top 5 equipos por costo y Salud del mantenimiento preventivo, además de menú lateral simplificado y chatbot flotante.

**No rediseñar ni reemplazar el dashboard, navegación interna, indicadores, resumen financiero, gráfico de gastos, alertas, estilo oscuro o funciones de Mantenimiento para implementar el portal.** La primera fase agrega únicamente un nivel superior `/inicio` y un acceso para volver al portal/cambiar de módulo, insertado de modo discreto y sin degradar el diseño existente. El portal de módulos puede tomar inspiración de `portal-desktop.png` y `portal-mobile.png`, pero debe adaptar sus colores/componentes al lenguaje visual real, no imponer otra UI.

**Validación obligatoria:** comparar contra la pantalla actual obtenida de la aplicación desplegada y contrastar flujo y enlaces con el repositorio, antes de aceptar diseño. Las cifras del mockup NO son datos reales.

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

1. Comparar el **nuevo portal** lado a lado con el concepto aprobado (escritorio y móvil); comprobar **Mantenimiento** contra la captura/implementación real, jamás contra el mockup descartado.
2. Mantener jerarquía, distribución, espaciado, colores de marca, accesibilidad y navegación general.
3. Corregir incongruencias de textos/monedas/fotografías que las imágenes conceptuales puedan contener.
4. Confirmar que las tareas actuales del usuario siguen siendo rápidas y el nuevo portal no agrega pasos obligatorios innecesarios.
5. Verificar permisos, enlaces profundos, QR, cron y compatibilidad de rutas; la fidelidad visual **no sustituye** las pruebas funcionales.
6. No desplegar en Ferozo antes de validar en Coolify.

## Próxima acción documental

Agregar los originales a `docs/referencias-visuales/` conservando los tres nombres, e insertar las vistas previas aquí. Esta incorporación **no está realizada** hasta verificar los binarios en GitHub.
