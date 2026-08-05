# Changelog

Todos los cambios relevantes del proyecto se documentan en este archivo.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.1.0/) y
el proyecto adhiere a [Semantic Versioning](https://semver.org/lang/es/) para
la especificación funcional.

## [1.1] - 2026-08-05

### Agregado
- **Solicitudes de mantenimiento separadas de las órdenes de trabajo.**
  Circuitos independientes: una falla, inspección o necesidad se registra como
  `solicitud_mantenimiento` y un responsable la revisa antes de generar una
  orden. Tablas nuevas: `solicitudes_mantenimiento`, `solicitud_adjuntos`,
  `solicitud_comentarios`, `avisos_plan`, `orden_solicitudes`.
- Nuevo rol **Solicitante** y reorganización del rol técnico a
  **Técnico u operador**.
- Pantalla **Bandeja personal (`Mi trabajo`)** con vista por técnico de sus
  órdenes asignadas, vencidas, de hoy, próximas y en espera.
- Carga rápida de fallas desde el teléfono: equipo por búsqueda o **QR**,
  descripción breve, condición operativa y fotografía opcional, con aviso
  de posibles duplicados abiertos antes de confirmar.
- Diagrama ER de relaciones centrales del mantenimiento.
- Flujos visuales (`mermaid`) para circuito funcional completo, carga de
  lectura, decisión del estado de un plan, revisión de solicitudes, origen
  y autorización de la orden, cierre consistente, importación y proceso
  diario de alertas.
- Código **QR imprimible por equipo** para abrir la ficha o informar una
  falla rápidamente.
- Inicio adaptado al rol (el técnico no debe atravesar paneles
  administrativos para llegar a su trabajo).
- Estados de espera con **motivo obligatorio**: repuesto, proveedor,
  autorización, disponibilidad del equipo u otro.
- Cierre de orden correctiva con **causa, acción realizada y resultado**;
  se rechazan cierres con texto genérico como "listo" o "reparado".
- Indicadores de panel para solicitudes sin revisar, órdenes bloqueadas y
  cierres con datos de baja calidad.
- Reportes 9 a 12: solicitudes por estado/edad/equipo, tiempo de
  respuesta y aprobación a cierre, motivos de espera, calidad de datos.
- Definiciones explícitas de `MTTR`, cumplimiento preventivo y tiempo de
  respuesta, con regla de mostrar `sin datos suficientes` cuando falten
  datos mínimos.
- Pruebas críticas 19 a 26 (solicitudes, QR, Mi trabajo, motivo de
  espera, tormenta de notificaciones, etc.).
- Sección **Estrategia de adopción y puesta en marcha**: piloto
  controlado con 5 a 10 equipos representativos antes del despliegue
  general.

### Cambiado
- "Aplicación móvil nativa" deja de figurar como fuera de alcance y se
  reemplaza por **Trabajo sin conexión u operación offline**. La primera
  versión se entrega como aplicación web responsive accesible desde el
  teléfono, no como app nativa.
- El campo `ordenes_trabajo` incorpora `prioridad`, `criticidad`,
  `responsable_usuario_id`, `fecha_objetivo`, `causa_codigo`,
  `resultado_codigo`, `motivo_espera`, `equipo_fuera_servicio`,
  `inicio_detencion` y `fin_detencion`.
- Etapa 4 de desarrollo se renombra y suma explícitamente solicitudes,
  avisos, revisión y agrupación.
- Numeración de pantallas reordenada: nueva sección 6.2 (Bandeja
  personal y carga rápida) y 6.6 (Solicitudes y avisos); las demás
  pantallas se renumeran en consecuencia.
- Alertas por correo: se permite preferencias por destinatario o rol y se
  separan los resúmenes diarios de las alertas críticas. Los cambios
  menores no generan correo salvo suscripción explícita.
- Reglas generales de negocio 13 a 17: duplicados se agrupan, prioridad
  del solicitante es orientativa, motivo visible en espera, interfaz de
  campo mínima, indicadores honestos cuando faltan datos.
- Criterios de aceptación: se exige un **piloto con equipos, planes y
  órdenes reales** completado por usuarios finales antes del despliegue
  general.

## [1.0] - 2026-08-04

### Agregado
- Primera versión pública de la especificación funcional y técnica del
  sistema de gestión de mantenimiento de equipos.
- Stack inicial: PHP 8.2+ / CodeIgniter 4 / MySQL o MariaDB / Bootstrap 5.
- Modelo de datos con 30+ tablas organizadas por dominios funcionales.
- Pantallas, reglas de negocio, pruebas mínimas obligatorias y
  entregables del proyecto.