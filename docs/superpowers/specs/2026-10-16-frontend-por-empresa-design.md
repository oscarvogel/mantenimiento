# Borrador de especificación: fronts independientes por empresa

- **Estado:** borrador para revisión del usuario. No autoriza implementación.
- **Fecha:** 2026-10-16
- **Alcance:** asignar a cada empresa una experiencia de frontend propia dentro del monolito existente, conservando el backend, los casos de uso y las reglas de seguridad compartidos.

## 1. Propósito

Permitir que el sistema se adapte visual y operativamente a cada cliente sin que un cambio de presentación para una empresa altere accidentalmente la experiencia de las demás. La arquitectura debe servir para el cliente actual y permitir incorporar otra empresa con un perfil de frontend distinto sin copiar ni bifurcar el backend.

La primera adaptación será el dashboard del nuevo cliente. El diseño, sin embargo, debe permitir diferenciar progresivamente la experiencia de todas las pantallas autenticadas: shell, navegación, dashboard y páginas de módulos.

## 2. Decisiones de producto y alcance

- Cada empresa tendrá asignado un perfil de frontend identificado por una clave controlada por el sistema, por ejemplo `base`, `vogel` o `cliente_a`.
- `base` puede compartirse como fallback; un perfil personalizado pertenece a una sola empresa por defecto. No se asigna el mismo perfil personalizado a dos empresas salvo que se apruebe explícitamente que ambas compartirán futuras modificaciones de esa presentación.
- Cada perfil puede definir su propio shell, estilos, navegación visual, dashboard y vistas de páginas. Un perfil podrá reutilizar componentes comunes o heredar páginas de `base` cuando no necesite una presentación propia.
- Las adaptaciones específicas de un cliente viven en su perfil. No se modifica un componente compartido para resolver una necesidad que solo corresponde a una empresa.
- Los componentes compartidos se reservan para patrones que el producto desea mantener iguales para todos. Cambiarlos puede afectar deliberadamente a todos los perfiles y debe tratarse como un cambio global.
- El código de cada perfil es código versionado y compilado. No se guarda ni ejecuta JavaScript, Vue, CSS o plantillas proporcionadas por un usuario desde la base de datos.
- Se conserva un perfil predeterminado equivalente a la experiencia actual. Las empresas existentes sin configuración explícita continúan usando ese perfil.
- La primera entrega de cliente puede personalizar solamente dashboard y shell; los demás módulos usan la presentación predeterminada hasta que se aprueben adaptaciones concretas.
- Las pantallas de login y recuperación anteriores a la autenticación quedan fuera del perfil inicial. Todas las empresas usan el login compartido. Una personalización previa al login requiere una futura resolución de empresa por dominio, código u otro mecanismo validado.
- No se crean ramas, repositorios, despliegues ni copias de backend independientes para clientes.

## 3. Estado actual y motivación del cambio

El dashboard actual ya separa parcialmente presentación y aplicación: CI4 prepara un payload, Vue lo normaliza y los casos de uso consultan puertos implementados por adaptadores. No obstante, la vista se selecciona actualmente por rol, la marca de Vogel está fija en componentes del frontend y el bundle de Vue se carga desde la aplicación CI4 común.

La tabla `empresas` no contiene una asignación de perfil visual. El contexto del shell conoce la empresa autenticada y sus sucursales, por lo que existe un punto de partida seguro para resolver el perfil por empresa.

El criterio de aceptación es **aislamiento de presentación con backend compartido**, no despliegue independiente de frontend y backend. Por lo tanto, la compilación y publicación de assets continúa formando parte del release de la aplicación CI4.

## 4. Enfoque seleccionado

Se utilizará un **registro de perfiles de presentación en el frontend**, con una clave asignada a cada empresa y seleccionada por el servidor después de autenticar al usuario.

El bundle mantendrá un punto de entrada común que:

1. recibe el payload seguro generado por CI4;
2. lee la clave del perfil asignado por el servidor;
3. busca esa clave en un registro estático de perfiles compilados;
4. carga el shell, los componentes de página y los estilos de ese perfil;
5. usa el perfil predeterminado cuando la clave falta o no está disponible, y registra el error para operación.

Los perfiles pueden compilarse como chunks separados con carga diferida. El nombre de perfil nunca se interpreta como una ruta arbitraria ni se usa para descargar código desde una dirección indicada por el cliente.

La organización conceptual del frontend será similar a:

```text
frontend/src/
  profiles/
    base/
      profile.js
      shell/
      pages/
      styles.css
    vogel/
      profile.js
      shell/
      pages/
      styles.css
    cliente_a/
      profile.js
      shell/
      pages/
      styles.css
  shared/
    components/
    contracts/
```

La estructura final debe respetar convenciones existentes de Vue/Vite y puede mantener páginas y componentes compartidos donde hoy ya viven. Este árbol es una guía de ownership, no una instrucción para mover toda la aplicación en una sola migración.

## 5. Límites y responsabilidades

### 5.1 Identidad, empresa y configuración

- El perfil efectivo se resuelve a partir del `empresa_id` del usuario autenticado y activo; no se toma de query string, formulario, `localStorage`, cabecera controlada por el usuario ni estado libre del navegador.
- La empresa se valida en servidor conforme al contexto de Identidad y Acceso. Si no existe o no está activa, se aplica el manejo de acceso vigente y no se permite elegir otra empresa para obtener su perfil.
- El perfil se configura por empresa. La asignación de un perfil existente y compilado queda reservada al Superadministrador global o al proceso administrativo equivalente autorizado por servidor.
- El Administrador de empresa no puede instalar código ni cambiar a un perfil de otra empresa. Si posteriormente se autoriza que cambie opciones visuales limitadas, esas opciones deben validarse como datos y no como código ejecutable.
- La configuración visual se mantiene separada de razón social, CUIT y demás datos de identidad legal de la empresa.

### 5.2 Aplicación y dominio

- Casos de uso, entidades, reglas de negocio y transacciones no dependen de perfiles, componentes Vue, CSS ni nombres de clientes.
- Las decisiones de autenticación, permisos y alcance por empresa/sucursal permanecen en servidor.
- El perfil solo decide cómo presentar una operación autorizada; no puede habilitar una acción ni ampliar la información entregada al usuario.
- Si un perfil necesita indicadores que no existen en el contrato de dashboard, se agrega una consulta/caso de uso de lectura con scope explícito. No se consulta otra empresa desde el perfil ni se calcula un indicador de negocio en el componente visual.

### 5.3 Presentación

- Cada perfil declara su shell y una tabla de componentes de página por tipo de página.
- Una página específica puede ser reemplazada por perfil sin alterar el componente usado por otro.
- Si no hay override, se usa la página de `base`; esa herencia se considera intencionalmente compartida y sus cambios pueden verse en todos los perfiles que la hereden.
- El shell puede cambiar disposición, navegación visual, marca y accesos destacados. Recibe los destinos y elementos autorizados desde el payload de servidor.
- Las páginas que envían formularios conservan los métodos, tokens CSRF y contratos HTTP existentes, salvo que un cambio funcional aprobado los modifique.
- Los estilos específicos se encapsulan o cargan por perfil para evitar fugas de CSS hacia otros perfiles. Los tokens semánticos compartidos son opt-in desde cada perfil.

## 6. Configuración persistida

La implementación agregará una configuración de presentación asociada uno a uno con empresa. Se recomienda una tabla separada, por ejemplo `empresa_configuracion_presentacion`, para no mezclar preferencias de interfaz con datos legales de `empresas`.

Campos mínimos previstos:

- `empresa_id`, único y con clave foránea;
- `frontend_profile`, clave de un perfil incluido en el registro compilado;
- la clave de un perfil personalizado se registra para una única empresa; las claves compartidas quedan reservadas para perfiles de producto como `base`;
- marcas de creación/actualización y autoría según las convenciones existentes.

Campos opcionales que pueden incorporarse si el primer cliente los necesita:

- nombre de marca mostrado en la interfaz;
- referencia a logo validado y almacenado en el mecanismo de assets permitido;
- paleta de colores en valores validados;
- configuración limitada de orden o visibilidad de secciones aprobadas por el producto.

No se agrega un constructor visual genérico ni persistencia de layout arbitrario en esta iniciativa. La configuración faltante, inválida o asociada a una empresa inexistente resuelve al perfil `base` y deja una señal operativa sin exponer trazas al usuario.

La migración tendrá `down()` seguro cuando sea viable, índices y unicidad adecuados. Las empresas existentes quedan en `base` por omisión, sin migrar datos de negocio.

## 7. Contrato entre backend y frontend

El servidor agrega al payload común del shell una sección tipada equivalente a:

```json
{
  "presentation": {
    "profile": "cliente_a",
    "brand": {
      "displayName": "Nombre validado",
      "logoUrl": "/assets/empresas/logo-validado.svg"
    },
    "theme": {
      "primary": "#145a72",
      "accent": "#e2a43a"
    }
  }
}
```

El ejemplo ilustra el contrato y no fija nombres finales de propiedades. La respuesta real deberá:

- provenir del contexto de Aplicación mediante un puerto de lectura de configuración de presentación;
- ser transformada a DTO de presentación por Presentation;
- validar perfil y valores visuales en servidor;
- omitir propiedades opcionales que no estén configuradas;
- no incluir secretos, rutas privadas ni código;
- mantener un perfil de fallback explícito y compatible con clientes actuales.

El payload de negocio del dashboard y las páginas existentes seguirá conteniendo únicamente los datos necesarios para la pantalla, con permisos y scope aplicados. No se crea una API pública/REST independiente como requisito de esta iniciativa.

## 8. Aislamiento y seguridad

- Un usuario común solo obtiene el perfil de su única empresa autenticada.
- El Superadministrador global opera con el perfil de plataforma/global existente; no recibe implícitamente el perfil de una empresa seleccionada para autorizar datos.
- En vistas globales de empresas, cualquier previsualización como cliente debe ser explícita, de solo lectura y separada de la autorización normal; queda fuera de la primera entrega salvo aprobación adicional.
- Cambiar manualmente el JSON del navegador no permite cambiar identidad, permisos, scope, enlaces protegidos ni perfil con acceso a datos de otro tenant.
- Ningún `empresa_id` del cliente reemplaza la validación del ActorContext ni el scoping en lecturas y comandos.
- La configuración de marca no admite HTML, scripts, URLs ejecutables, CSS arbitrario ni SVG no saneado. Los assets usan validación de tipo real, tamaño, almacenamiento y autorización definidos por Plataforma.
- Los assets estáticos compilados pueden usar caché por nombre de contenido. El HTML/payload de usuario autenticado conserva política privada/no-store actual o equivalente. No se cachea una respuesta de un tenant para otro.
- Errores al cargar un chunk de perfil muestran una interfaz de recuperación comprensible y registran el perfil y release; no exponen stack traces. Debe existir fallback seguro si está disponible en el bundle.

## 9. Navegación, rutas y permisos

- Las rutas HTTP, filtros de autenticación, filtros de permiso y controladores permanecen compartidos.
- Los elementos de navegación disponibles siguen siendo calculados en servidor. El perfil puede ordenarlos, agruparlos o presentarlos de otra manera, sin crear permisos nuevos en cliente.
- `/inicio` sigue siendo el punto de entrada autenticado y origen de navegación de módulos, de acuerdo con la regla vigente del producto. Cada perfil puede cambiar su presentación preservando ese rol.
- La navegación interna de cada módulo permanece dentro de ese módulo; una variación de shell no debe cambiar semántica ni destino de rutas.
- Los estados vacíos, errores, confirmaciones y respuestas de formularios deben conservar lenguaje humano, feedback accionable y continuidad del contexto.

## 10. Compatibilidad y despliegue

- El perfil actual se registra como `base` o como el perfil de marca que se acuerde durante implementación; no se renombra silenciosamente la identidad visual de Vogel.
- El selector por rol `managerial/operational` deja de ser la autoridad para escoger la identidad visual. Las diferencias de capacidad continúan viniendo de permisos/roles; la selección de presentación viene del perfil de empresa.
- Los perfiles se publican en el mismo release de CI4 y sus assets Vite. No se promete despliegue independiente de front y back.
- Agregar un perfil requiere código, revisión, build, pruebas y despliegue normal. Asignarlo a una empresa existente es una configuración de datos autorizada.
- Las sesiones autenticadas no deben conservar un perfil obsoleto después de reasignar la empresa. El perfil se resuelve en cada nueva respuesta de página desde la configuración actual, o se invalida mediante un mecanismo explícito si se introduce caché.
- Los bundles llevan nombres versionados por contenido y el HTML referencia el manifest del release desplegado para evitar mezclar chunks incompatibles.
- Antes de desplegar a producción se cumple el protocolo de destino de `AGENTS.md`: verificar `fasa_189` para pruebas de staging; producción Ferozo sigue su circuito documentado y no se usa `fasa_195`.

## 11. Estrategia de adopción

1. Registrar y asignar el perfil actual como fallback para todas las empresas; no cambiar comportamiento visible.
2. Introducir resolución de perfil desde el contexto de empresa y emitirlo en el payload de shell.
3. Cambiar el punto de composición Vue para seleccionar perfil desde el registro estático.
4. Migrar el shell y dashboard actuales al perfil vigente conservando contratos, rutas y permisos.
5. Construir el perfil del nuevo cliente en un namespace propio, inicialmente para shell/dashboard.
6. Extender el override de páginas a módulos solo cuando el cliente haya validado necesidades concretas.
7. Asignar el perfil a la empresa objetivo con auditoría y comprobar que otro tenant mantiene su perfil y experiencia.

Cada etapa debe ser desplegable y mantener fallback funcional. No se hará una migración masiva de carpetas ni se copiarán todos los módulos antes de que haya una necesidad real de presentación distinta.

## 12. No objetivos

- Separar el backend por cliente, desplegar una instancia distinta o convertir el monolito en microservicios.
- Crear una API REST pública exclusivamente para permitir esta separación.
- Permitir a clientes subir o ejecutar componentes, scripts, plantillas o estilos arbitrarios.
- Implementar un editor no-code de dashboards o constructor libre de páginas.
- Cambiar reglas de negocio, permisos, roles, aislamiento por sucursal, migraciones funcionales de otros contextos o contratos de módulos que no hagan falta para la presentación.
- Forzar que todos los clientes tengan front completamente diferente: el perfil base es una opción válida y reutilizar páginas comunes es intencional.
- Rehacer el login preautenticado para cada empresa en la primera fase.

## 13. Pruebas y evidencia requerida

La implementación debe cubrir como mínimo:

- resolución del perfil correcto para usuario activo de una empresa;
- fallback `base` cuando no existe configuración o el perfil no es válido;
- imposibilidad de elegir el perfil de otro tenant manipulando parámetros o payload;
- asignación de perfil restringida al rol/proceso administrativo aprobado y con auditoría;
- acceso normal de usuarios sin perfil explícito antes y después de la migración;
- shell/dashboard de cada perfil y fallback de una página no sobrescrita;
- confirmación de que un override visual de perfil A no cambia el componente de perfil B;
- navegación visible según permisos de servidor y rechazo HTTP de acciones no autorizadas aunque el front las invoque;
- configuración visual inválida, asset inválido y chunk ausente con fallback y error accionable;
- CSRF, rutas, scope por empresa/sucursal y regresión de los flujos compartidos.

Evidencia de UI incluye escritorio y móvil para cada perfil soportado. Capturas o aceptación visual deben distinguirse de las pruebas automatizadas y del piloto con usuarios reales.

## 14. Criterios de aceptación

- Cada empresa tiene exactamente un perfil efectivo, obtenido del contexto autenticado y con un fallback predecible.
- Se puede cambiar el dashboard del cliente objetivo sin editar el dashboard propio de otra empresa.
- Se puede incorporar otro perfil compilado y asignarlo a otra empresa sin copiar backend ni abrir acceso a datos cruzados.
- Un perfil personalizado no se asigna accidentalmente a otra empresa; compartirlo requiere una decisión explícita porque sus siguientes cambios afectarían ambas experiencias.
- El shell, dashboard y páginas pueden ser propios del perfil; páginas sin override usan fallback explícito.
- El código específico de perfil está aislado del código compartido y sus estilos no filtran a otros perfiles.
- Un cambio en un componente compartido se reconoce como cambio global en revisión y cuenta con pruebas de regresión para los perfiles soportados.
- Navegación, rutas, permisos, CSRF y scope siguen validados por el servidor, independientemente del perfil activo.
- Las empresas existentes conservan una experiencia funcional equivalente mediante fallback.
- El Superadministrador mantiene la vista global y no puede usar la selección visual para elevar permisos o scope.
- El dashboard puede presentar indicadores diferentes si el backend ofrece un contrato de lectura autorizado; un perfil no inventa ni infiere datos ausentes.
- La publicación mantiene un único release coordinado de aplicación y assets, con fallback para errores de carga.

## 15. Decisiones a confirmar durante revisión

1. Nombre y alcance inicial del perfil que conservará la experiencia actual: `base`, `vogel` o ambos (con `base` neutral y `vogel` asignado a la empresa actual).
2. Si el primer perfil del cliente altera solo shell/dashboard o también páginas específicas de módulos en la primera entrega.
3. Si el cliente necesita logo, paleta, nombre comercial o solo una composición distinta del dashboard en la primera entrega.
4. Si el Superadministrador asignará perfiles desde una pantalla administrativa o mediante una operación interna auditada en la primera versión.

Las respuestas no bloquean la arquitectura descrita; sí determinan el alcance de implementación inicial y la configuración que habrá que construir.

Antes de programar, revisar este borrador, cerrar las decisiones de §15 y generar un plan de implementación por etapas.
