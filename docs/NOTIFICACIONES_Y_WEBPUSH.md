# Notificaciones, email y Web Push

## Estado de la decisión

A partir del 11 de agosto de 2026, el sistema incorpora explícitamente dentro del alcance funcional un **motor central de notificaciones multicanal**.

Los canales iniciales son:

- notificación interna dentro de la aplicación;
- correo electrónico;
- Web Push desde navegadores compatibles en escritorio y móvil.

Esta decisión complementa la especificación funcional versión 1.1 y debe considerarse parte del alcance del proyecto.

Issues de implementación:

- #7 — Motor central de notificaciones: centro interno, preferencias y alertas por email.
- #8 — Web Push/PWA para notificaciones del sistema en navegador y móvil.

---

## 1. Principio de diseño

La aplicación debe tener una única fuente de verdad para las notificaciones.

```text
Evento del sistema
      ↓
Motor central de notificaciones
      ↓
Resolver destinatarios y preferencias
      ↓
┌─────────────────┬─────────────────┬─────────────────┐
│ Centro interno  │ Email           │ Web Push        │
└─────────────────┴─────────────────┴─────────────────┘
```

Ningún módulo funcional debe enviar correos o push directamente.

Los módulos de mantenimiento preventivo, órdenes, solicitudes, equipos, garantías y otros dominios generan eventos o solicitudes de notificación. El motor central determina destinatarios, canales, preferencias e idempotencia.

La notificación interna persistente es la referencia principal. Un fallo de email o Web Push nunca debe provocar la pérdida del aviso dentro del sistema.

---

## 2. Centro de notificaciones interno

La interfaz autenticada debe incluir una campana de notificaciones con contador de pendientes/no leídas.

Cada notificación debe poder incluir:

- usuario destinatario;
- empresa y sucursal aplicables;
- tipo de evento;
- severidad;
- título;
- resumen;
- entidad origen;
- identificador de la entidad;
- enlace directo a la pantalla correspondiente;
- fecha de creación;
- fecha de lectura;
- estado.

Acciones mínimas:

- abrir la notificación;
- marcar como leída;
- marcar todas como leídas;
- acceder al historial reciente.

---

## 3. Eventos iniciales

Como mínimo deben considerarse:

### Preventivo

- mantenimiento próximo;
- mantenimiento vencido;
- aviso preventivo generado;
- preventivo severamente vencido cuando la configuración lo determine.

### Equipos y lecturas

- equipo sin lectura reciente;
- equipo detenido o situación crítica relevante.

### Órdenes de trabajo

- OT asignada;
- OT reasignada;
- OT próxima a fecha objetivo;
- OT demorada;
- OT en espera por repuesto, proveedor, autorización o disponibilidad;
- cambios críticos que requieran acción del responsable.

### Solicitudes

- nueva solicitud pendiente de revisión;
- solicitud crítica;
- solicitud asignada o reasignada;
- solicitud postergada que vuelve a requerir revisión.

### Garantías

- garantía próxima a vencer;
- condición de garantía que requiere revisión.

No deben generarse notificaciones externas por comentarios internos o modificaciones menores salvo suscripción explícita.

---

## 4. Preferencias

Cada usuario debe poder configurar qué tipos de eventos recibe y por qué canal.

Los roles pueden definir valores predeterminados.

Ejemplo:

| Evento | Interna | Web Push | Email |
|---|---|---|---|
| Preventivo vencido | Sí | Sí | Sí |
| Preventivo próximo | Sí | Opcional | Resumen |
| OT asignada | Sí | Sí | Opcional |
| Solicitud crítica | Sí | Sí | Sí |
| Equipo sin lectura | Sí | Opcional | Resumen |
| Garantía próxima | Sí | Opcional | Resumen |

La configuración debe permitir distinguir entre:

- aviso inmediato;
- resumen diario;
- solo eventos críticos;
- canal desactivado.

---

## 5. Email

El correo ya forma parte de la especificación 1.1 y se mantiene como canal oficial.

El proceso automático diario se ejecutará inicialmente a las 07:00 mediante tarea programada de Ferozo.

Debe incluir, según destinatario y permisos:

- mantenimientos próximos;
- mantenimientos vencidos;
- equipos sin lectura reciente;
- garantías próximas a vencer;
- órdenes demoradas;
- solicitudes relevantes pendientes.

Se prioriza un resumen por sucursal/destinatario en lugar de un correo por cada elemento.

Las alertas críticas pueden enviarse inmediatamente si así se configura.

El proceso debe ser:

- idempotente;
- registrable;
- reintentable;
- bloqueado contra ejecuciones simultáneas;
- auditable en caso de error.

---

## 6. Web Push

Web Push pasa a formar parte explícita de la primera versión operativa del sistema como canal de notificación.

La implementación debe utilizar estándares Web Push y Service Worker, sin requerir una aplicación móvil nativa.

### Requisitos

- HTTPS en producción;
- Service Worker registrado;
- manifest web;
- claves VAPID;
- permiso solicitado de forma explícita al usuario;
- almacenamiento de una o varias suscripciones por usuario;
- entrega desde backend;
- apertura mediante deep link al registro relacionado;
- baja automática de endpoints expirados o inválidos.

### Múltiples dispositivos

Un mismo usuario puede registrar más de un navegador/dispositivo.

Ejemplos:

- PC del taller;
- notebook;
- teléfono Android;
- iPhone/iPad cuando la plataforma permita Web Push para la web instalada.

No debe existir una relación uno-a-uno entre usuario y suscripción.

### Preferencia de UX

En `Mi perfil → Notificaciones` debe existir una opción explícita:

`Activar notificaciones en este dispositivo`

No se debe disparar automáticamente la solicitud de permisos al primer ingreso sin contexto.

---

## 7. Modelo de datos complementario

Además de las estructuras ya previstas por la especificación (`configuracion_alertas`, `ejecuciones_programadas`, `notificaciones`), incorporar una estructura equivalente a:

### `webpush_subscriptions`

- id;
- usuario_id;
- endpoint;
- p256dh;
- auth;
- user_agent;
- nombre_dispositivo opcional;
- fecha_alta;
- ultimo_uso;
- activo;
- fecha_baja;
- ultimo_error.

Las claves y endpoints deben tratarse como datos sensibles.

---

## 8. Idempotencia

Todo evento notificable debe tener una clave lógica que permita evitar duplicados.

Ejemplos:

```text
preventivo_vencido:plan:123:ciclo:190000
orden_asignada:ot:456:usuario:20
solicitud_critica:solicitud:88
```

Ejecutar dos veces el mismo proceso no debe crear dos notificaciones ni dos emails/push equivalentes para el mismo evento lógico.

---

## 9. Relación con PWA

La primera versión continúa siendo una aplicación web responsive.

Se incorporan los elementos mínimos de PWA necesarios para Web Push:

- manifest;
- iconos;
- Service Worker;
- posibilidad de instalación en pantalla de inicio cuando corresponda.

Continúan fuera de alcance:

- modo offline;
- sincronización offline;
- aplicación móvil nativa;
- WhatsApp como canal del sistema.

---

## 10. Prioridad de implementación

Orden recomendado:

1. motor central y modelo de notificaciones;
2. centro interno/campana;
3. preferencias de usuario/rol;
4. proceso diario y email;
5. Web Push y suscripciones;
6. pruebas integrales de idempotencia, permisos y fallos de canales.

El issue #8 debe construirse sobre el #7 y no desarrollar un sistema paralelo.