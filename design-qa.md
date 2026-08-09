# Design QA — interfaz de mantenimiento

## Artefactos

- Fuente visual: imagen esquemática adjunta por el usuario en la conversación del 2026-08-08. Muestra sidebar y navbar azul oscuro, tres métricas principales y un listado de mantenimientos. El adjunto no está disponible como archivo local para generar una composición lado a lado.
- Implementación: `http://127.0.0.1:8080`, autenticada con cuentas locales de administrador de empresa y Superadministrador.
- Evidencia renderizada: `docs/screenshots/ui/` y resultados automatizados en `docs/screenshots/ui/visual-qa-results.json`.
- Viewports CSS: escritorio `1440 x 900`, móvil `390 x 844`; `deviceScaleFactor: 1`.
- Capturas: ancho exacto de `1440 px` o `390 px`; alturas completas entre `900 px` y `3951 px` según el contenido.
- Estado: datos locales reales de demostración; un equipo, un plan activo, una OT finalizada, una sucursal y un usuario empresarial. La lista de importaciones está vacía.

## Comparación de vista completa

La implementación conserva la jerarquía definida por la referencia: navegación persistente azul `brand-950`, cabecera clara, contenido sobre `surface-subtle`, métricas de primer nivel y estados de mantenimiento codificados en verde, ámbar, rojo y azul. En móvil el sidebar pasa a un panel modal y las métricas/tablas se apilan sin overflow del documento.

No pudo producirse la entrada visual combinada exigida entre el adjunto original y la captura renderizada porque el adjunto de conversación no tiene una ruta reutilizable en el workspace. La comparación formal de fidelidad queda bloqueada por esa limitación, aunque la revisión independiente de las capturas no detectó defectos P0, P1 o P2.

## Evidencia focalizada

- Dashboard: `docs/screenshots/ui/dashboard-desktop.png` y `dashboard-mobile.png`.
- Reportes: `docs/screenshots/ui/reportes-desktop.png` y `reportes-mobile.png`.
- Flujo operativo: `docs/screenshots/ui/servicios-desktop.png` y `ficha-equipo-desktop.png`.
- Administración: `docs/screenshots/ui/usuarios-mobile.png` y `superadmin-desktop.png`.

Estas regiones permiten revisar tipografía, tarjetas, formularios, tablas, navegación activa, badges, estados vacíos y densidad responsive. No se requieren recortes adicionales para los controles principales porque son legibles en las capturas completas.

## Superficies de fidelidad

- Tipografía: jerarquía consistente, pesos legibles, truncado en cabecera y wrapping correcto en móvil. Se usa la pila sans del sistema; la fuente no estaba prescrita en la referencia.
- Espaciado y layout: ritmo uniforme, un único `<main>`, sidebar de proporción estable y tarjetas sin colisiones. Las 18 capturas verificaron `scrollWidth === clientWidth`.
- Colores y tokens: se aplican los tokens exactos de brand, primary, accent, surface, ink, border, feedback y maintenance entregados por el usuario.
- Imágenes y assets: la fuente no contiene fotografía o ilustración. Los iconos pertenecen a Heroicons; no se usaron emojis, SVG artesanales ni placeholders de imagen.
- Copy: lenguaje operativo en español, etiquetas coherentes y estados explícitos de “Sin datos suficientes” en métricas sin muestra válida.
- Interacciones: menú móvil abre, bloquea scroll, cierra con Escape y recupera el documento. No hubo errores de consola ni solicitudes fallidas.

## Hallazgos

- [P3] La barra de depuración de CodeIgniter aparece como una llama en el borde derecho de las capturas locales.
  - Ubicación: todas las pantallas en entorno de desarrollo.
  - Evidencia: overlay pequeño ajeno a la interfaz; no forma parte del bundle Vue.
  - Impacto: sólo visual durante desarrollo local; no afecta el layout ni producción.
  - Tratamiento: mantenerla para diagnóstico local y desactivarla en producción mediante la configuración de entorno.

## Historial de iteraciones

1. La primera revisión quedó bloqueada porque no había navegador automatizado ni capturas renderizadas.
2. Se incorporó Playwright usando Edge headless autorizado por el usuario. Se capturaron nueve rutas en escritorio y nueve en móvil.
3. El primer runner encontró una ambigüedad en el selector del cierre del menú móvil; se corrigió el runner y se repitió todo el recorrido.
4. La segunda corrida pasó: 18 rutas/capturas, cero overflow del documento, un único `<main>` por pantalla, cero errores de consola y cero requests fallidos.

## Checklist de implementación

- [x] Dashboard responsive con shell compartido.
- [x] Equipos, ficha, servicios, importaciones, sucursales, usuarios y Superadministración migrados a Vue/Tailwind.
- [x] Reportes con métricas reales, filtros, estados sin muestra y exportación CSV.
- [x] Navegación, permisos, CSRF y formularios POST conservados.
- [x] Capturas de escritorio y móvil con Edge headless.
- [ ] Repetir la comparación combinada si el usuario vuelve a adjuntar la referencia como archivo accesible.

## QA visual del login

### Fuente y artefactos

- Referencia de composicion: captura de login adjunta por el usuario en la conversacion. La referencia define una tarjeta central dividida, formulario claro a la izquierda y panel fotografico con mensaje de producto a la derecha.
- Fotografia fuente: `ChatGPT Image 8 ago 2026, 17_07_41.png`, inspeccionada a resolucion original `1680 x 943`.
- Asset optimizado: `assets/login/maintenance-workshop.webp`, `100314` bytes frente a `1920566` bytes del PNG original. Se eligio WebP porque la fuente es una imagen estatica; WebM se reserva para video.
- Implementacion: `http://127.0.0.1:8080/login`.
- Evidencia: `docs/screenshots/login/login-desktop.png`, `docs/screenshots/login/login-mobile.png` y `docs/screenshots/login/login-visual-qa-results.json`.
- Viewports CSS: escritorio `1906 x 943`, movil `390 x 844`; `deviceScaleFactor: 1`.

### Comparacion y fidelidad

- La captura de escritorio reproduce la jerarquia principal: fondo fotografico a pantalla completa, tarjeta clara centrada, panel de formulario, panel editorial fotografico, titular de gran escala, bloque descriptivo y etiquetas breves.
- La fotografia mantiene el camion como foco en el panel derecho y usa una superposicion `brand-950` para asegurar contraste del texto blanco.
- En movil se conserva el formulario como tarea primaria; el panel editorial se oculta para evitar desplazamiento innecesario y la fotografia permanece como fondo ambiental.
- Se aplican los tokens exactos de brand, primary, accent, surface, ink y border del sistema. Los iconos son de Heroicons.
- El flujo conserva POST nativo, CSRF, autocompletado, visualizacion de contrasena, errores por campo y alerta accesible.

La referencia de composicion solo esta disponible como adjunto de conversacion, sin ruta de archivo reutilizable. Por esa razon no puede generarse una entrada visual lado a lado automatizada entre la referencia y el render. La revision formal combinada queda bloqueada por el artefacto faltante; la inspeccion independiente y la automatizacion del render no detectaron defectos P0, P1 o P2.

### Resultado automatizado

- [x] Escritorio y movil sin overflow horizontal.
- [x] Un unico `<main>` y un unico formulario de ingreso.
- [x] Token CSRF presente.
- [x] Fotografia WebP cargada correctamente.
- [x] Mostrar y ocultar contrasena funciona.
- [x] Cero errores de consola y cero solicitudes fallidas.
- [x] Login real valido redirige al dashboard; validacion y credenciales invalidas permanecen en login.
- [ ] Comparacion visual combinada con la referencia, pendiente de contar con el archivo fuente de esa captura.

### Hallazgo del entorno

- [P3] La llama de la barra de depuracion de CodeIgniter aparece en la esquina inferior derecha de las capturas locales. Es una herramienta del entorno de desarrollo y no pertenece al bundle Vue ni al entorno de produccion.

final result: blocked
