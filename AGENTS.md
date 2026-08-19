# AGENTS.md

Estas instrucciones aplican a todo el repositorio.

## Entorno de prueba obligatorio

Cuando una tarea diga probar, levantar, reconstruir o deployar en prueba, el destino obligatorio es el Docker remoto de `fasa_195`. No usar PHP/MariaDB local de Windows, `php -S`, ni otro fallback local salvo autorización explícita del usuario.

Antes de modificar o probar el entorno:

1. Verificar por SSH que el host sea `fasa_195`.
2. Inspeccionar allí `docker ps` y el `docker compose` del proyecto.
3. Confirmar el nombre del stack, puerto, checkout/commit y que no sea producción.
4. Ejecutar únicamente sobre el stack de prueba identificado, preservando sus volúmenes.

Si `fasa_195` no es accesible o el stack no puede identificarse con seguridad, informar el bloqueo y detenerse. La ausencia de Docker en Windows nunca habilita un entorno local alternativo.

## Criterio de producto obligatorio

El sistema debe diseñarse y validarse como una herramienta para una persona que lo usa todos los días, no como una interfaz que simplemente responde al backend. Cada flujo debe priorizar lenguaje humano, valores aplicables, prevención de errores, continuidad de contexto y feedback accionable.

## Protocolo obligatorio de inicio

1. Antes de analizar, planificar o modificar código, localizar y leer completa la skill `clean-ddd-hexagonal` y las referencias que correspondan a la tarea.
2. El uso de `clean-ddd-hexagonal` es obligatorio en todas las tareas de este proyecto, incluso si el cambio parece pequeño. Debe usarse con criterio: DDD para lenguaje y límites, Hexagonal para puertos y adaptadores, y Clean Architecture para la dirección de dependencias.
3. Si la skill no está instalada o no puede leerse, detener los cambios de código, instalarla mediante la skill/herramienta `skill-installer` desde una fuente aprobada y comprobar que su `SKILL.md` sea accesible. No continuar la implementación hasta que esté disponible.
4. Leer completa `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md`. Para tareas de despliegue, leer además `docs/DEPLOY_FEROZO.md` y `docs/SUBDOMINIO_DESCARTADO.md`. Para cambios de empresa, sucursal, usuario, rol o permisos, leer también `docs/PENDING_REFACTOR_MULTITENANCY.md`.
5. Inspeccionar `git status`, las rutas, migraciones, modelos, tests y configuración real antes de aceptar una hipótesis. Preservar siempre los cambios locales del usuario.
6. No asumir que documentación y checkout coinciden. Cuando haya contradicción, mostrar evidencia y tratar el código ejecutable como estado actual, sin alterar decisiones funcionales explícitas de la especificación.

## Arquitectura acordada

El producto es un monolito modular CodeIgniter 4 con vistas renderizadas en servidor. Los bounded contexts son límites semánticos y de ownership dentro del monolito; no implican microservicios ni bases separadas.

La dirección de dependencias es:

```text
Presentation / Infrastructure -> Application -> Domain
```

- `Domain`: reglas e invariantes puras; no importa CodeIgniter, HTTP, Query Builder, SMTP ni filesystem.
- `Application`: casos de uso, transacciones y coordinación mediante puertos; depende del dominio, no de implementaciones de infraestructura.
- `Infrastructure`: modelos CI4, persistencia, correo, archivos, PDF, importadores y otros adaptadores.
- `Presentation`: controladores, filtros, comandos y vistas; traduce entradas/salidas y llama casos de uso.
- El composition root vive en configuración/bootstrap y conecta puertos con adaptadores.

El código actual todavía sigue una estructura CI4 convencional (`Controllers`, `Models`, `Views`). No hacer una migración masiva de carpetas. Introducir límites gradualmente cuando una funcionalidad lo justifique y mantener cada PR pequeño y ejecutable.

No agregar CQRS, Event Sourcing, brokers ni eventos de dominio por defecto. Empezar con casos de uso y transacciones simples; sumar complejidad solo ante una necesidad demostrada.

## Fuentes de verdad y prioridades

1. `docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md`: alcance, lenguaje ubicuo, reglas y 26 pruebas críticas.
2. Decisiones explícitas vigentes del usuario/cliente.
3. Migraciones y código ejecutable: estado realmente implementado.
4. `docs/PENDING_REFACTOR_MULTITENANCY.md`: propuesta pendiente, no autorización para implementarla.
5. `README.md` y documentación de deploy: guía operativa que debe contrastarse con el árbol real.

El Superadministrador global, la pertenencia de cada usuario común a una sola empresa y el alcance del Administrador de empresa están confirmados. El aislamiento por `empresa_id`, sucursal y permiso es una invariante de seguridad: ningún nuevo acceso puede quedar sin scope explícito.

## Agentes especializados por bounded context

Cada tarea debe tener un agente propietario. Si cruza contextos, dividir el trabajo entre especialistas con entregables acotados y designar un integrador. Ningún agente modifica tablas o reglas de otro contexto sin coordinar el contrato.

### 1. Arquitectura e integración

- Custodia el mapa de contextos, contratos, dependency rule, composition root y decisiones arquitectónicas.
- Revisa cambios transversales y evita un `Shared Kernel` creciente.
- No implementa reglas funcionales que pertenecen a otro contexto.

### 2. Organización, identidad y acceso

- Lenguaje: Empresa, Sucursal, Usuario, Rol, Permiso, sesión y autorización.
- Código actual: `Login`, `AuthFilter`, `UsuarioModel`, migraciones 110000-110007 y `InitialSeeder`.
- Invariantes: aislamiento por empresa/sucursal, autorización en servidor, usuario activo y trazabilidad de asignaciones.
- No confundir administrador de empresa con superadministrador global.

### 3. Registro de activos

- Lenguaje: Equipo, Tipo de equipo, Marca, Modelo, estado, relación tractor-acoplado, ubicación, adjunto y QR.
- Es dueño de ficha e historial del equipo, relaciones temporales y cambios de sucursal.
- Un equipo dado de baja conserva el historial; tractor y acoplado son mantenibles independientes.

### 4. Medición de uso

- Lenguaje: Lectura, Kilometraje, Horómetro, Origen, Corrección y última lectura válida.
- Reglas: al menos un valor, no negativos, no retrocesos sin permiso/motivo, actualización transaccional del equipo.
- Publica datos válidos a Mantenimiento Preventivo; no calcula vencimientos dentro del adaptador de persistencia.

### 5. Mantenimiento preventivo

- Lenguaje: Tipo de servicio, Tarea, Plantilla, Plan, Anticipación, Próximo, Vencido y `SIN_DATOS`.
- Es el núcleo de dominio: un plan vence por el primer criterio alcanzado entre fecha, kilómetros u horas.
- El motor debe ser puro y probarse sin base, framework ni reloj global no controlado.
- La finalización de una OT preventiva actualiza bases y recalcula el plan dentro de la transacción del caso de uso.

### 6. Recepción y triage de trabajo

- Lenguaje: Solicitud, Aviso, Duplicado, Agrupación, Revisión, Aprobación, Postergación y Rechazo.
- Mantiene separada la solicitud de la orden de trabajo.
- Rechazar, postergar o agrupar requiere motivo; nunca se pierde autoría ni trazabilidad.
- La prioridad solicitada es orientativa; la clasificación final pertenece al responsable.

### 7. Ejecución de mantenimiento

- Lenguaje: Orden de trabajo, Asignación, Tarea, Estado, Espera, Cierre, Detención, Costo y `Mi trabajo`.
- Es dueño de numeración transaccional, transiciones de estado, tareas, repuestos usados, costos y cierre.
- Una OT no finaliza sin los datos requeridos. Una correctiva exige causa, acción y resultado.
- El cierre coordina OT, lectura, equipo, plan e historial en una sola transacción de aplicación.

### 8. Proveedores, talleres, repuestos y garantías

- Lenguaje: Proveedor, Taller propio, Repuesto colocado, Lote/serie, Comprobante y Garantía.
- No introduce inventario, compras, pagos ni contabilidad: están fuera del alcance v1.
- Una garantía termina al alcanzar el primero de sus límites aplicables.

### 9. Importaciones

- Lenguaje: Archivo, Vista previa, Fila válida, Error, Confirmación, Duplicado y Resultado.
- Actúa como Anti-Corruption Layer para CSV/Excel y futuros formatos de Gestya.
- Validar antes de persistir; mostrar errores por fila; confirmar explícitamente; registrar origen y resultado.
- No integrar Gestya por API en v1.

### 10. Notificaciones y procesos programados

- Lenguaje: Alerta, Resumen, Evento notificable, Ejecución, Reintento, Bloqueo e Idempotencia.
- Es dueño del comando diario, SMTP y registro de envíos.
- Dos ejecuciones no pueden duplicar el mismo evento; evitar correos por cambios menores.

### 11. Reportes y auditoría

- Lenguaje: Indicador, Período, Tiempo de respuesta, Detención, MTTR, Calidad de datos y Auditoría.
- Los read models respetan empresa, sucursal y permisos.
- Si faltan datos mínimos, informar `sin datos suficientes`; no inventar métricas.
- Auditoría es append-only para operaciones sensibles y conserva antes/después cuando corresponda.

### 12. Plataforma y entrega

- Es dueño de CI4, configuración, MariaDB/MySQL, filesystem privado, PDF, Bootstrap, Composer, Ferozo y observabilidad.
- Respeta el layout plano de producción, donde la raíz es pública. `app/`, `tests/`, `writable/`, `.env` y `vendor/` deben permanecer protegidos por servidor.
- La URL canónica es `https://vogelconsultoria.com.ar/mantenimiento/`; el subdominio fue descartado.
- No expone credenciales, adjuntos ni trazas.

### 13. Calidad y pruebas

- Verifica comportamiento, no implementación interna.
- Mantiene pirámide: muchas pruebas puras de dominio, casos de uso con puertos falsos, integración con DB real y pocos flujos HTTP/E2E.
- Convierte las 26 pruebas críticas de la spec en cobertura automatizada trazable.
- Distingue evidencia automatizada de aceptación visual/móvil y piloto con usuarios reales.

## Contratos entre contextos

- Referenciar otros agregados por ID; no compartir objetos ORM ni builders.
- Un controlador no salta directamente a repositorios de múltiples contextos. Debe llamar un caso de uso coordinador.
- Un contexto no consulta tablas de otro desde Presentation. Usar un puerto de aplicación o un contrato de lectura explícito.
- Los DTO de entrada/salida no son entidades de dominio.
- Mantener el Shared Kernel mínimo: identificadores, dinero/moneda, reloj y errores base solo si hay reutilización real.
- Usar eventos únicamente cuando la consistencia eventual sea aceptable. Lectura + equipo + plan + cierre de OT requieren transacción, no eventos asíncronos.

## Persistencia y migraciones

- Toda modificación de esquema se entrega como migración reproducible con `down()` seguro cuando sea viable.
- No editar una migración ya aplicada en producción; agregar una nueva migración correctiva.
- Importes usan `DECIMAL`, nunca `FLOAT`/`DOUBLE`.
- La información histórica no se borra físicamente. Catálogos se inactivan y registros aplicables usan soft delete.
- Foreign keys e índices deben expresar las invariantes y accesos reales.
- No confiar solo en `empresa_id` guardado en sesión: validar pertenencia del recurso en cada consulta/comando sensible.
- Las operaciones multiagregado indicadas por la spec usan transacciones y rollback completo.

## Seguridad mínima

- Autenticación, autorización y scoping se validan en servidor.
- Regenerar la sesión al autenticar y destruirla al cerrar sesión.
- Login debe aplicar límite de intentos y bloqueo configurables antes de considerarse terminado.
- Mutaciones, incluido logout, usan métodos apropiados y protección CSRF.
- Escapar salidas, usar Query Builder/consultas parametrizadas y validar tipo real, extensión y tamaño de archivos.
- En producción, cookies de sesión seguras/HttpOnly/SameSite y errores sin trazas.
- Nunca versionar `.env`, credenciales FTP/SMTP/DB, adjuntos privados ni datos reales.

## Pruebas y Definition of Done

Para cada funcionalidad:

1. Probar invariantes del dominio sin DB ni CodeIgniter.
2. Probar casos de uso con dobles solo en los puertos.
3. Probar adaptadores con MariaDB/MySQL real cuando la semántica difiera de SQLite.
4. Probar rutas, filtros, CSRF, permisos, empresa y sucursal mediante tests HTTP/feature.
5. Incluir regresión para cualquier bug corregido.
6. Ejecutar lint, suite PHPUnit, migraciones desde cero y una verificación HTTP real.
7. Verificar escritorio y móvil cuando cambia UI; no afirmar aceptación visual sin evidencia.
8. Actualizar documentación si cambia configuración, operación o lenguaje del dominio.

Una tarea no está terminada si solo compila o si solo se ve bien. Debe cumplir reglas, permisos, migración, pruebas, manejo de errores, responsive y conservación histórica según la sección 18 de la spec.

## Operación local verificada

- Requisitos: PHP 8.2+, extensiones `intl`, `mbstring`, `mysqli`, `curl`, `dom`; Composer; MariaDB/MySQL.
- Instalar dependencias: `composer install`.
- Crear `.env` desde `.env.example` sin versionarlo.
- Servidor compatible con el layout plano: `php -S 127.0.0.1:8080 index.php`.
- El repositorio incluye un `spark` raíz adaptado al layout plano. Usar `php spark`; no reemplazarlo sin más por el archivo del framework, que presupone el layout estándar `public/`.
- Ejecutar la FASE 0A con `scripts/run-phase0a.ps1` y ensayar instalación, actualización y rollback con `scripts/rehearse-local-deploy.ps1`. Consultar `docs/PRUEBA_TECNICA_LOCAL.md`.
- En Windows/XAMPP, la suite de ejemplo necesita `sqlite3`; puede habilitarse solo para el proceso con `php -d extension=sqlite3 vendor\bin\phpunit`.

## Estado base conocido al 2026-08-07

- Implementado: bootstrap CI4 4.7.4, login limitado, sesión, autorización,
  Superadministrador global, gestión de empresas y asignación auditada de
  empresa/roles, CRUD tenant de sucursales y usuarios, restablecimiento de
  contraseña y asignación atómica de roles/sucursales.
- No implementado todavía: la mayoría de Etapas 2-5 y los 26 casos críticos de negocio.
- Las pruebas existentes son mayormente ejemplos del appstarter, no aceptación del dominio.
- La FASE 0A local (CLI, DB, logs, sesiones, SMTP, tarea programada y ensayo de despliegue) está automatizada y documentada en `docs/PRUEBA_TECNICA_LOCAL.md`.
- Antes de desarrollar nuevas funciones, resolver o registrar explícitamente los gaps funcionales, de pruebas de dominio y de multi-tenancy relacionados con la tarea.

## Convenciones de cambios

- Commits en español con Conventional Commits, acotados por contexto: `feat(planes): ...`, `fix(ordenes): ...`, `test(lecturas): ...`.
- PRs pequeños con problema, decisión, bounded context afectado, migraciones y evidencia de pruebas.
- No mezclar refactors arquitectónicos amplios con una feature funcional.
- Documentar decisiones importantes y supuestos; pedir confirmación para cambios materiales de alcance.
