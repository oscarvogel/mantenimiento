# Sistema de Gestión de Mantenimiento de Equipos

Aplicación web para administrar el mantenimiento preventivo y correctivo de una
flota heterogénea: camiones, tractores, acoplados, máquinas, vehículos livianos
y otros tipos de equipo que puedan incorporarse a futuro.

El documento rector del proyecto es la especificación funcional y técnica que
se encuentra en [`docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md`](docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md).
Antes de tomar decisiones de diseño o implementación, leerla completa.

---

## 1. Resumen del alcance

- Mantenimiento preventivo y correctivo.
- Múltiples empresas y sucursales.
- Aproximadamente cinco o seis usuarios en la primera etapa, sin tope artificial.
- Equipos con historial, lecturas de kilómetros y horómetro, planes preventivos,
  órdenes de trabajo, repuestos, garantías, alertas por correo, reportes y
  auditoría.

Fuera de alcance de la primera versión (ver spec, sección 2.2): integración con
Gestya por API, inventario completo, compras y cuentas corrientes, gestión de
neumáticos por posición, GPS/telemetría, WhatsApp, app móvil nativa, portal de
proveedores, presupuestos avanzados, funciones contables.

---

## 2. Stack técnico

| Capa | Elección | Comentario |
|---|---|---|
| Lenguaje | PHP 8.2 o superior | El hosting dispone de PHP 8.4 FPM. |
| Framework | CodeIgniter 4 | Aplicación monolítica con vistas en servidor. |
| Base de datos | MySQL o MariaDB | Importes con `DECIMAL`, nunca `FLOAT`/`DOUBLE`. |
| UI | Bootstrap 5 | Diseño responsive, sin frontend separado. |
| Interactividad | JavaScript liviano o Alpine.js | Solo para interacciones puntuales. |
| Dependencias | Composer | `vendor/` debe incluirse en el deploy si el server no puede correr Composer. |
| PDF | Dompdf o equivalente | Compatible con el hosting. |
| Hojas de cálculo | PhpSpreadsheet o equivalente | Para importaciones Excel/CSV. |
| Correo | SMTP autenticado | Con reintento y registro de errores. |
| Tareas programadas | Cron de Ferozo | Comando de CodeIgniter ejecutado diariamente a las 07:00. |

### 2.1 Restricciones del hosting Ferozo

- `public_html` como directorio público.
- `.htaccess` y URL rewriting.
- PHP 8.4 FPM, zona horaria `America/Argentina/Buenos_Aires`.
- Memoria PHP: 512 MB.
- Tiempo máximo de ejecución web: 60 segundos.
- Tamaño máximo de subida y POST: 128 MB.

Los archivos privados (adjuntos, manuales) deben quedar **fuera** de
`public_html` cuando el hosting lo permita. Si no es posible, se protegen con
controlador de descarga autorizado.

---

## 3. Estructura propuesta

La aplicación es un monolito organizado por dominios funcionales:

```text
app/
├── Dominios/
│   ├── Empresas        # Empresas, sucursales
│   ├── Usuarios        # Usuarios, roles, permisos, acceso por sucursal
│   ├── Equipos         # Tipos, marcas, modelos, equipos, adjuntos, relaciones
│   ├── Lecturas        # Lecturas de kilómetros y horómetro
│   ├── Planes          # Tipos de servicio, tareas, plantillas, planes
│   ├── Ordenes         # Órdenes de trabajo, tareas, repuestos, garantías
│   ├── Proveedores     # Talleres propios y proveedores externos
│   ├── Importaciones   # Carga y validación de archivos
│   ├── Alertas         # Configuración, ejecuciones y notificaciones
│   ├── Reportes        # Reportes y exportaciones
│   └── Auditoria       # Bitácora de operaciones sensibles
├── Common/             # Helpers, traits, middlewares, servicios comunes
├── Views/              # Layouts y vistas por dominio
└── Controllers/        # Controladores que orquestan los dominios
```

Las migraciones y los seeders iniciales (roles, permisos, estados, catálogos)
son parte de los entregables.

---

## 4. Etapas de desarrollo

El plan sugerido por la spec (sección 15) es de **8 a 10 semanas calendario**
y se organiza en cinco etapas:

1. **Base del sistema** — Proyecto, configuración, autenticación, empresas,
   sucursales, roles, permisos, catálogos generales.
2. **Equipos y lecturas** — Equipos, adjuntos, relaciones, lecturas manuales,
   importaciones básicas.
3. **Mantenimiento preventivo** — Servicios, tareas, plantillas, planes,
   motor de vencimientos, panel de próximos/vencidos.
4. **Órdenes de trabajo** — Flujo completo, tareas, repuestos, costos,
   garantías, PDF e impresión.
5. **Alertas, reportes y cierre** — Correos y tarea programada, reportes y
   exportaciones, auditoría, pruebas, documentación y despliegue.

---

## 5. Reglas de negocio que la implementación debe respetar

Resumen de la sección 9 de la spec:

- No se borra físicamente información histórica.
- Los catálogos se inactivan, no se eliminan.
- Las operaciones que actualizan orden, lectura y plan usan transacciones.
- Un equipo dado de baja conserva todo su historial.
- Acoplado y tractor tienen historiales independientes.
- Un plan vence por el **primer criterio** que se cumpla (fecha, kilómetros u
  horas). No se espera a que todos estén vencidos.
- Las correcciones sensibles requieren motivo y auditoría.
- Todos los listados se paginan y filtran del lado del servidor.
- Los reportes respetan permisos y sucursales autorizadas.

---

## 6. Pruebas mínimas obligatorias

La spec, sección 13, define 18 casos críticos que el programador debe cubrir
como mínimo. Los principales son:

- Vencimiento por fecha, por kilómetros, por horómetro, y combinado.
- Estado `SIN_DATOS` cuando falta lectura.
- Rechazo de lectura inferior sin autorización.
- Finalización de orden y recálculo del plan asociado.
- Imposibilidad de finalizar una orden incompleta.
- Numeración única de órdenes ante altas simultáneas.
- Prevención de duplicados en importación.
- Prevención de correos duplicados al correr el proceso de alertas dos veces.
- Restricción de datos por sucursal y de acciones por permiso.
- Descarga autorizada de adjuntos privados.

---

## 7. Entregables

Definidos en la spec, sección 12:

- Código fuente versionado en Git (este repositorio).
- `.env.example` sin credenciales reales.
- Migraciones de base de datos.
- Seeders para roles, permisos, estados y catálogos iniciales.
- Aplicación desplegable en el hosting Ferozo.
- Manual breve de instalación y actualización.
- Manual básico de usuario.
- Plantillas de importación (CSV/Excel).
- Plantilla PDF de orden de trabajo.
- Configuración documentada de la tarea programada.
- Pruebas automatizadas de las reglas críticas.
- Procedimiento de copia de seguridad y recuperación.

---

## 8. Puntos a confirmar antes de programar

La spec, sección 16, lista 12 puntos que no bloquean el inicio del proyecto
pero deben resolverse en el relevamiento inicial. Los más relevantes:

- Nombre de la empresa y listado inicial de sucursales.
- Cantidad aproximada de equipos a importar.
- Formato real del archivo disponible desde Gestya u otro sistema.
- Formato definitivo de numeración de órdenes (`OT-AAAA-000001` sugerido).
- Logo y datos que deben aparecer en el PDF.
- Destinatarios y anticipaciones iniciales de los correos.
- Plazo para considerar una lectura desactualizada.
- Plazo para considerar una orden demorada.
- Moneda de los costos (inicialmente se asume ARS).
- Si los costos son obligatorios u opcionales al finalizar.
- Política de conservación y tamaño máximo de adjuntos.
- Credenciales SMTP y dominio/subdominio de despliegue.

Toda ampliación que modifique de forma material el alcance debe documentarse
y aprobarse antes de desarrollarse.

---

## 9. Cómo arrancar

1. Clonar el repositorio.
2. Copiar `.env.example` a `.env` y completar las credenciales.
3. Instalar dependencias: `composer install`.
4. Crear la base de datos y aplicar migraciones: `php spark migrate`.
5. Cargar los seeders iniciales: `php spark db:seed InitialSeeder`.
6. Levantar el servidor de desarrollo: `php spark serve`.

El deploy a Ferozo y la configuración del cron diario se documentarán en
`docs/DEPLOY.md` cuando el primer entorno esté en pie.

---

## 10. Convención de commits y PRs

- Mensajes en español, siguiendo Conventional Commits.
  Ejemplos: `feat(ordenes): numeración transaccional OT-AAAA-000001`,
  `fix(planes): recálculo al finalizar orden`, `docs(spec): aclarar criterio
  de vencimiento combinado`.
- PRs chicos, con descripción del problema, la solución y cómo se probó.
- Antes de mergear, verificar que pasan los 18 casos de prueba críticos de
  la spec.

---

## 11. Licencia

Pendiente de definir por el cliente.