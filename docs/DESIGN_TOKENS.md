# Tokens de color — paleta Vogel

Fecha: 2026-11-25. Fuente: `frontend/src/styles.css`. Prueba: `frontend/tests/tokens.contrast.test.js`.

## Roles

| Escala | Color | Para qué |
|---|---|---|
| `primary` | Azul intenso | Acciones, enlaces, foco |
| `secondary` | Azul marino | Navegación, títulos, superficies de marca |
| `accent` | Amarillo del logo | Detalles y rellenos. **Nunca como texto sobre blanco** |
| `neutral` | Grises con tinte marino | Fondos, bordes, texto secundario |

Cada escala tiene los pasos 100 a 900 y **significan lo mismo en los dos temas**. No hay 50 ni 950.
Lo que cambia entre claro y oscuro es el token semántico (`--primary`, `--surface`, `--ink`…),
que apunta a otro paso de la misma escala.

## Mapeo por tema

| Token | Claro | Oscuro |
|---|---|---|
| `primary` (botón, enlace) | primary-600 | primary-300 |
| `primary-foreground` | blanco | secondary-900 |
| `accent` | accent-500 | accent-400 |
| `surface-subtle` (fondo de página) | neutral-100 | neutral-900 |
| `surface` / `surface-raised` | blanco | neutral-800 |
| `surface-inverse` (barra lateral oscura) | secondary-900 | secondary-600 |
| `frame` (marco del shell) | secondary-900 | secondary-600 (sigue a `surface-inverse`) |
| `ink` | secondary-900 | neutral-200 |
| `ink-muted` / `ink-subtle` | neutral-600 / 500 | neutral-300 / 400 |
| `border` | neutral-300 | neutral-600 |

## Radios

| Clase | Valor | Para qué |
|---|---|---|
| `rounded-pill` | 9999px | Botones y accesos en píldora, búsqueda, estados |
| `rounded-card` | 24px | Tarjetas de indicador |
| `rounded-panel` | 32px | Lienzo del shell y paneles grandes |
| `rounded-frame` | 40px | Marco marino del shell |

La sombra de tarjeta es `shadow-card`. El marco no tiene sombra.

## Reglas

- Los componentes usan tokens semánticos (`bg-primary`, `text-ink-muted`). Los pasos de escala
  (`bg-secondary-900`) quedan para superficies de marca como el login o la barra del portal.
- `warning` («por vencer») es **naranja**, distinto del acento amarillo: una alerta no debe leerse
  como un detalle de marca.
- `warning-foreground` es el texto de advertencia sobre fondo claro o `warning-subtle`. Sobre el
  naranja sólido (`bg-warning`) el texto es `secondary-900`.
- En oscuro, `secondary-900` es tan oscuro como el fondo. Una superficie que deba distinguirse del
  fondo usa `surface-inverse`. La prueba exige 1,3:1 entre barra lateral y fondo, además de 4,5:1
  para texto y 3:1 para el foco.

## Tipografía

- Fuente: Inter variable, incluida en el bundle (`@fontsource-variable/inter`, importada en
  `frontend/src/main.js`). No depende de Google Fonts. El navegador descarga solo el archivo latino
  (unos 48 KB).
- Escala moderada (`frontend/tailwind.config.js`): `text-xs` 13 px, `text-sm` 15 px, `text-base`
  16 px, `text-xl` 22 px. No se usan tamaños arbitrarios por debajo de `text-xs`.
- Campos de formulario a 16 px (`styles.css`), para que iOS no haga zoom al enfocarlos.
- Las tablas usan dígitos de ancho fijo (`tabular-nums`) para alinear importes.

## Formato de números, plata y fechas

Todo pasa por `frontend/src/ui/format.js`. No se escribe `toLocaleString` ni `Intl` en una pantalla.

| Dato | Función | Ejemplo |
|---|---|---|
| Plata en tableros y listados | `formatMoney(v)` | `$ 1.234.568` |
| Plata en facturas, liquidaciones y cuentas corrientes | `formatMoney(v, { cents: true })` | `$ 1.234,50` |
| Otra moneda | `formatMoney(v, { currency: 'USD', cents: true })` | `US$ 1.500,00`, `BRL 625` |
| Números | `formatNumber(v, decimales)` | `11.010`, `1.234,6` |
| Fecha | `formatDate(v)` | `08/08/2026` |
| Fecha y hora (solo si el dato trae hora) | `formatDateTime(v)` | `08/08/2026 12:00` |
| Distancia en avisos y vencimientos | `formatRelativeDays(v)` | `hoy`, `hace 3 días`, `dentro de 5 días` |

Si falta el dato se muestra `Sin datos` o `Sin fecha`, nunca un cero inventado. Las fechas
`YYYY-MM-DD` se leen como día local: `new Date('2026-08-08')` las corre un día en Argentina.

## Navegación

Hay una sola navegación: la barra lateral, marino en los dos temas.

- En escritorio, el menú inicia como barra de íconos. Al expandirlo aparecen los nombres y secciones, superpuestos al contenido; por eso cada página arranca contraída y no se recuerda la elección.
- La barra contraída muestra siempre los mismos íconos, en el mismo orden (`AppSidebar.vue`): Resumen, Órdenes, Solicitudes, Preventivos, Equipos y Km/horas. Quien no ve planes (perfil de campo, mismo criterio que el portal) ve Resumen, Mi trabajo, Informar falla, Km/horas, Órdenes y Equipos. Viajes sigue el orden de su menú: Resumen, Viajes operativos, Combustible sin precio, Cierre comercial, Cuenta corriente y Rentabilidad; Historial queda en el desplegado. La página actual no se suma: correría los íconos. El nombre de cada ícono aparece al instante con el mouse o el teclado.
- Arriba: "Volver al portal" (`/inicio`) y el nombre del módulo, con el selector para cambiar de módulo (`ModuleSwitcher.vue`). El encabezado muestra el módulo actual, la empresa, los avisos y "Mi cuenta" (tema y cerrar sesión; la barra no repite "Cerrar sesión").
- El menú de Mantenimiento no lista módulos que ya tienen su lugar en el portal (Reportes, Gerencial, Automatizaciones). Reportes y Automatizaciones muestran su propio menú.
- Los ítems se agrupan por tarea del día en `frontend/src/ui/navigationGroups.js`: Operación, Flota, Planificación, Comercial y Análisis siempre visibles; Configuración y Administración global plegadas, salvo que la página actual esté adentro. Un ítem que no figura en ningún grupo cae en "Más": nada se pierde. Los permisos los decide el servidor.
- El portal y Gerencial mantienen una lista simple.
- En el celular, la barra inferior (`MobileTabBar.vue`) muestra solo los accesos permitidos, más Menú. Oficina: Resumen, Órdenes, Solicitudes y Km/horas. Campo: Resumen, Km/horas, Falla y Mi trabajo. Viajes: Resumen y Viajes. Dentro de un módulo, el encabezado del celular muestra solo la píldora del módulo (sin avatar ni saludo). El aviso flotante de scroll queda por encima de la barra.
- El asistente de los módulos está en el encabezado. Su botón queda fuera del área que ocupan formularios, tablas y la barra inferior.
- La barra y el contenido se expanden juntos en escritorio. En móvil se desplaza el menú y cambia la opacidad del fondo. Con `prefers-reduced-motion: reduce` los cambios son inmediatos.
- La barra usa pasos fijos (`secondary-900` en claro, `surface-inverse` en oscuro). El ítem activo usa un degradado `primary-600` → `secondary-700`, nunca `--primary`, que en oscuro es un azul claro. Los íconos propios usan siempre la variante para fondo oscuro. La prueba de contraste cubre estos pares.

### Accesos de campo (spec §6.1 y §6.2)

`frontend/src/ui/fieldShortcuts.js` agrega dos ítems a partir de lo que el servidor ya envió. No inventa permisos: sin el ítem base, el acceso no aparece.

- **Mi trabajo**: requiere el ítem de órdenes y `user.id` en el shell. Lleva a `mantenimiento/ordenes?responsable_id={id}&vista=mi-trabajo`, donde el servidor filtra y la página agrupa en "En curso", "Para empezar", "En espera de repuestos" y "Terminadas", sin filtros ni totales.
- **Informar falla**: requiere los ítems de solicitudes y de "Registrar km/horas". Abre esa página con `?accion=falla`, que muestra el buscador de equipos como tarjetas. Cada tarjeta lleva a `mantenimiento/equipos/{id}/operar#reportar-problema`, donde ya estaba el formulario de incidencia.
- En el celular, la barra inferior muestra "Falla" (en acento amarillo) y "Mi trabajo" en lugar de todas las órdenes.
- La entrada después del portal no cambió: el técnico sigue entrando al Resumen. Cambiar el destino por rol requiere tocar `GetPlatformModuleCatalog` y quedó fuera por decisión.

## Lenguaje

La pantalla no muestra códigos internos ni datos crudos.

- Códigos (`EN_PROCESO`, `CRITICA`, `WARNING`): `humanizeCode` de `frontend/src/ui/labels.js` los convierte en "En proceso", "Crítica", "Advertencia". El texto que ya es legible y las siglas (BCRA, PDF) pasan sin cambios, así que se puede usar en campos mixtos. Las palabras con acento o con otra forma van en su diccionario.
- Si el texto de respaldo también es un código, va adentro: `humanizeCode(task.status || 'PENDIENTE')`.
- Tipo de archivo: `fileTypeLabel('application/pdf')` → "PDF".
- Origen de una lectura: `formatReadingOrigin` (helpers.js).
- Fechas en plantillas: `format.formatDateTime(valor, 'respaldo')`. Lo que no es una fecha se muestra tal cual y nunca se esconde. Excepción: los diagnósticos técnicos que comparan marcas de tiempo al segundo, como la telemetría, muestran el valor crudo.
- No se escribe "hace {{ días }} días": se usa `formatRelativeDays`, que dice "hoy" o "ayer".
- Sin palabras en inglés en la interfaz. "Dashboard" pasó a "Resumen".

## Componentes base

| Pieza | Dónde | Uso |
|---|---|---|
| Botones | `frontend/src/ui/buttons.js` | `primaryButton`, `secondaryButton`, `dangerButton`, `ghostButton`, sus versiones `…Sm` e `iconButton`. Son clases: sirven en `<button>`, `<a>` y submit. Las clases de ubicación (`w-full`, `mt-4`) se agregan aparte: `:class="[primaryButton, 'w-full']"`. |
| Campo | `fieldClass` (mismo archivo) | Se pinta en rojo con `aria-invalid="true"`. |
| Etiqueta de campo | `pages/operations/components/FormField.vue` | Props `hint` y `error`. Conecta el campo con `v-slot="field"` y `v-bind="field.control"`. |
| Estado | `pages/operations/components/StatusBadge.vue` | Muestra texto legible ("En proceso") e ícono por tono. No depende solo del color. |
| Modal | `frontend/src/ui/BaseDialog.vue` | Sobre `<dialog>` nativo, que maneja el foco, Escape y queda encima de todo. Props: `title`, `description`, `eyebrow`, `open`, `persistent` (mientras guarda), `size` (`sm`…`xl`), `titleId`, `closeLabel`. Al abrir enfoca el primer campo. Cerrado, no queda en el DOM. |

Reglas:

- Un solo botón `primary` por pantalla o por sección. El resto es `secondary` o `ghost`.
- El tamaño va dentro de la constante: no apilar `px-3` sobre `primaryButton`, porque gana el orden del CSS y no el de la clase. Para un botón chico, usar `primaryButtonSm`.
- Un modal nuevo usa `BaseDialog`. No crear otro `fixed inset-0`.

## Cambio de nombres

`brand-*` pasó a `secondary-*`: 50 → 100, 950 → 900, el resto conserva el número.
