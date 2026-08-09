# Sistema de Gestión de Mantenimiento de Equipos

Aplicación web para administrar el mantenimiento preventivo y correctivo de una
flota heterogénea: camiones, tractores, acoplados, máquinas, vehículos livianos
y otros tipos de equipo que puedan incorporarse a futuro.

El documento rector del proyecto es la especificación funcional y técnica
versión **1.1** que se encuentra en
[`docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md`](docs/ESPECIFICACION_SISTEMA_MANTENIMIENTO.md).
El historial de cambios del documento está en
[`CHANGELOG.md`](CHANGELOG.md). Antes de tomar decisiones de diseño o
implementación, leer la spec completa.

El primer circuito vertical está diseñado en
[`docs/FASE_1A_DISENO_CIRCUITO_VERTICAL.md`](docs/FASE_1A_DISENO_CIRCUITO_VERTICAL.md):
equipo, lectura, plan preventivo, aviso de vencimiento, orden y recálculo tras
el cierre.

La implementación local navegable de ese circuito está documentada en
[`docs/FASE_2_CIRCUITO_VERTICAL_PREVENTIVO.md`](docs/FASE_2_CIRCUITO_VERTICAL_PREVENTIVO.md).

La consolidación de ficha, edición, traslado, baja lógica e historial/corrección
de lecturas está en
[`docs/FASE_2B_EQUIPOS_Y_LECTURAS.md`](docs/FASE_2B_EQUIPOS_Y_LECTURAS.md).

El modelo de Superadministrador, usuario de una empresa y alcance automático del
Administrador está documentado en
[`docs/FASE_1B_ACCESO_MULTIEMPRESA.md`](docs/FASE_1B_ACCESO_MULTIEMPRESA.md).

La administración tenant de sucursales, usuarios, roles y alcance está en
[`docs/FASE_1C_ADMINISTRACION_EMPRESA.md`](docs/FASE_1C_ADMINISTRACION_EMPRESA.md).

---

## 0. Estado del proyecto

- [x] Etapa 0 — bootstrap del framework y guía de despliegue (rama `etapa-0-bootstrap`).
- [x] Etapa 1 — organización, acceso, administración tenant y catálogos mínimos del circuito.
- [ ] Etapa 2 — en progreso: CRUD esencial de equipos, traslados, baja lógica y lecturas auditadas listos; adjuntos, relaciones, QR e importaciones pendientes.
- [ ] Etapa 3 — en progreso: motor y plan preventivo mínimo listos; plantillas pendientes.
- [ ] Etapa 4 — en progreso: avisos y OT preventiva mínima listos; solicitudes y correctivo pendientes.
- [ ] Etapa 5 — alertas, reportes, auditoría, pruebas, piloto y cierre.

El bootstrap actual instala CodeIgniter 4 v4.7.4 con autodeteccion de
`baseURL` y un `.htaccess` listo para Ferozo. La URL canonica del
deploy es `https://vogelconsultoria.com.ar/mantenimiento/`. Ver
[`docs/DEPLOY_FEROZO.md`](docs/DEPLOY_FEROZO.md) para el paso a paso.

---

## 1. Resumen del alcance

- Mantenimiento preventivo y correctivo.
- Circuito de **solicitudes** separado del circuito de **órdenes de trabajo**:
  fallas, inspecciones o avisos preventivos se registran como solicitudes y un
  responsable las revisa antes de generar una orden.
- Múltiples empresas y sucursales.
- Aproximadamente cinco o seis usuarios en la primera etapa, sin tope artificial.
- Equipos con historial, lecturas de kilómetros y horómetro, planes preventivos,
  órdenes de trabajo, repuestos, garantías, alertas por correo, reportes y
  auditoría.
- Interfaz web responsive, usable desde el teléfono, con **QR imprimible por
  equipo** y bandeja personal `Mi trabajo` para los técnicos.
- Piloto controlado con 5 a 10 equipos representativos antes del despliegue
  general.

Fuera de alcance de la primera versión (ver spec, sección 2.2): integración con
Gestya por API, inventario completo, compras y cuentas corrientes, gestión de
neumáticos por posición, GPS/telemetría, WhatsApp, **trabajo sin conexión u
operación offline**, portal de proveedores, presupuestos avanzados, funciones
contables. La versión 1.1 sustituye la app móvil nativa por la aplicación web
responsive, por lo que ya no se considera excluida.

---

## 2. Stack técnico

| Capa | Elección | Comentario |
|---|---|---|
| Lenguaje | PHP 8.2+ (objetivo PHP 8.4) | Ferozo dispone de PHP 8.4 FPM. |
| Framework | CodeIgniter 4 v4.7.4 | Aplicación monolítica con vistas en servidor. |
| Base de datos | MySQL o MariaDB | Importes con `DECIMAL`, nunca `FLOAT`/`DOUBLE`. |
| UI | Bootstrap 5 | Diseño responsive, sin frontend separado. |
| Interactividad | JavaScript liviano o Alpine.js | Solo para interacciones puntuales. |
| Dependencias | Composer | `vendor/` commiteado en esta etapa (4 MB). |
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

### 2.2 Decisión de stack (PHP sobre Python/Laravel)

Este proyecto se mantiene en **PHP + CodeIgniter 4** y no en Laravel ni en
Python por estas razones:

- **Ferozo como destino de deploy.** El hosting compartido ya está pago y
  resuelve TLS, backups, cron y SMTP. CodeIgniter 4 se instala con un
  `git pull` y `php spark migrate`; Laravel requiere scheduler, queue
  workers, `storage:link`, `key:generate` y varias caches que se rompen en
  cada deploy, lo que ya dio problemas en este hosting.
- **CRUD administrativo maduro.** El sistema es formularios, listados,
  reportes y un par de automatizaciones. CI4 cubre el caso con menos
  ceremonia que Laravel y sin la curva de Filament.
- **Web responsive + QR + bandeja personal.** La spec no pide frontend
  separado. CI4 lo entrega con vistas server-rendered y un poco de JS.
- **Costo de operación bajo.** Sin VPS, sin Docker, sin mantener gunicorn
  ni systemd. El programador puede hacer `git push` y listo.

Si en algún momento se necesita lógica avanzada (motor de vencimientos con
muchas reglas, OCR de facturas, integraciones profundas con Gestya), se
puede sumar un microservicio Python chico que se consuma por HTTP sin
reescribir el monolito.

---

## 3. Estructura del repositorio

La aplicación es un monolito organizado por dominios funcionales. La versión
1.1 agrega explícitamente los dominios **Solicitudes** y **Avisos** y
extiende **Órdenes** con prioridad, criticidad, responsable, motivos de
espera y fechas de detención.

```text
.
├── app/                      # Código de la aplicación (CodeIgniter 4)
│   ├── Config/               # Configuraciones (App, Database, Routes, etc.)
│   ├── Controllers/
│   ├── Models/
│   ├── Views/
│   ├── Filters/
│   ├── Helpers/
│   └── ...
├── public/                   # Único directorio expuesto al web server
│   ├── index.php             # Front controller
│   ├── .htaccess             # Rewrite + HTTPS forzado, válido para Ferozo
│   └── assets/               # CSS, JS, imágenes
├── writable/                 # Logs, caché, sesión, uploads temporales
│   ├── cache/
│   ├── logs/
│   ├── session/
│   └── uploads/
├── tests/                    # PHPUnit
├── vendor/                   # Dependencias Composer (commiteado por ahora)
├── docs/
│   ├── ESPECIFICACION_SISTEMA_MANTENIMIENTO.md
│   └── DEPLOY_FEROZO.md
├── .env.example              # Plantilla de configuración
├── .gitignore
├── CHANGELOG.md
├── composer.json
├── composer.lock
├── spark                     # CLI de CodeIgniter
└── README.md                 # Este archivo
```

Las migraciones y los seeders iniciales (roles, permisos, estados, catálogos)
son parte de los entregables de la etapa 1.

---

## 4. Etapas de desarrollo

El plan sugerido por la spec (sección 15) es de **8 a 10 semanas calendario**
y se organiza en cinco etapas. La versión 1.1 modifica la etapa 4 para
incluir solicitudes, avisos, revisión y agrupación antes del flujo completo
de órdenes.

1. **Base del sistema** — Proyecto, configuración, autenticación, empresas,
   sucursales, roles, permisos, catálogos generales.
2. **Equipos y lecturas** — Equipos, adjuntos, relaciones, QR, lecturas
   manuales, importaciones básicas.
3. **Mantenimiento preventivo** — Servicios, tareas, plantillas, planes,
   motor de vencimientos, panel de próximos/vencidos.
4. **Solicitudes, avisos y órdenes de trabajo** — Solicitudes, revisión y
   agrupación de avisos preventivos o correctivos, flujo completo de
   órdenes, tareas, repuestos, costos, garantías, PDF e impresión.
5. **Alertas, reportes y cierre** — Correos y tarea programada, reportes y
   exportaciones, auditoría, pruebas, documentación, despliegue y
   acompañamiento del piloto.

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
- Las solicitudes duplicadas se vinculan o se agrupan; nunca se duplican
  silenciosamente.
- La prioridad del solicitante es orientativa; la prioridad final la define
  el responsable.
- Todo estado de espera exige motivo visible.
- La interfaz de campo debe minimizar campos y clics.
- Los indicadores deben mostrar `sin datos suficientes` cuando falten datos
  mínimos, en lugar de inventar un valor.

---

## 6. Pruebas mínimas obligatorias

La spec, sección 13, define **26 casos críticos** que el programador debe
cubrir como mínimo. La versión 1.1 agrega ocho (19 a 26) vinculados a
solicitudes, bandeja personal, motivo de espera y control de notificaciones.
Los principales:

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
- Creación rápida de solicitud desde el teléfono.
- Detección y agrupación de solicitudes duplicadas.
- Conversión trazable de solicitudes a una orden.
- Bandeja `Mi trabajo` por técnico.
- Cierre de correctiva con causa, acción y resultado.
- Motivo obligatorio al poner una orden en espera.
- Cálculo de detención solo con fechas válidas.
- Prevención de tormenta de notificaciones.

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
- Informe del piloto con equipos reales.

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

## 9. Cómo arrancar (entorno local)

```bash
# 1. Clonar el repositorio
git clone https://github.com/oscarvogel/mantenimiento.git
cd mantenimiento

# 2. Instalar dependencias (si vendor/ no esta commiteado)
composer install --no-dev --optimize-autoloader

# 3. Configurar el entorno
cp .env.example .env
php spark key:generate
# Editar .env con las credenciales reales

# 4. Crear la base de datos y correr migraciones
php spark migrate

# 5. Levantar el servidor de desarrollo en Windows/XAMPP
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-local-server.ps1
# Abre http://localhost:8080/ en el navegador
```

Las importaciones XLSX requieren `ext-zip` y `ext-gd`. El script anterior las
habilita solo para el proceso cuando las DLL de XAMPP están disponibles. CSV no
depende de esas dos extensiones.

Para instrucciones de deploy en Ferozo, ver
[`docs/DEPLOY_FEROZO.md`](docs/DEPLOY_FEROZO.md).

### 9.1 Prueba tecnica local (FASE 0A)

Antes de comenzar los modulos funcionales se puede validar localmente PHP,
extensiones, MariaDB/MySQL, logs, sesiones, SMTP, tareas programadas y el flujo
HTTP completo:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\run-phase0a.ps1
```

Tambien se puede ensayar una instalacion limpia, una actualizacion conservando
`.env` y `writable`, y un rollback:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts\rehearse-local-deploy.ps1
```

El procedimiento, los resultados obtenidos y el limite respecto de Ferozo estan
documentados en
[`docs/PRUEBA_TECNICA_LOCAL.md`](docs/PRUEBA_TECNICA_LOCAL.md).

---

## 10. Convención de commits y PRs

- Mensajes en español, siguiendo Conventional Commits.
  Ejemplos: `feat(ordenes): numeración transaccional OT-AAAA-000001`,
  `fix(planes): recálculo al finalizar orden`, `docs(spec): aclarar criterio
  de vencimiento combinado`.
- PRs chicos, con descripción del problema, la solución y cómo se probó.
- Antes de mergear, verificar que pasan los 26 casos de prueba críticos de
  la spec.

---

## 11. Licencia

Pendiente de definir por el cliente.
