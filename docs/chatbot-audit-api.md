# API de auditoría de conversaciones del chatbot

## Permisos y alcance

- `chatbot.auditoria.global`: permiso reservado para auditoría global. El alcance global efectivo exige además identidad `Superadmin` (`ActorContext::isSuperAdmin()`).
- `chatbot.auditoria.empresa`: habilita auditoría dentro de la empresa del actor. La migración lo asigna al rol `Administrador`.
- Un usuario sin ninguno de los alcances anteriores recibe `403`.
- Un Administrador de empresa nunca puede ampliar su alcance enviando `companyId`: el backend impone siempre `ActorContext::companyId()`.
- El detalle devuelve `404` cuando la conversación no existe o pertenece a otra empresa, evitando filtrar su existencia.

## Listado

`GET /mantenimiento/chatbot/auditoria`

Filtros opcionales:

- `companyId`: solo tiene efecto para Superadmin global.
- `userId`: usuario propietario de la conversación.
- `dateFrom`: `YYYY-MM-DD`, aplicado sobre última actividad (`updated_at`).
- `dateTo`: `YYYY-MM-DD`, aplicado sobre última actividad (`updated_at`).
- `q`: texto en título/contenido o ID exacto de conversación si es numérico.
- `page`: página, mínimo `1`.
- `perPage`: tamaño de página, `1..100`, por defecto `25`.

Orden: `updated_at DESC, id DESC`.

Respuesta:

```json
{
  "data": [
    {
      "id": 123,
      "companyId": 4,
      "companyName": "Empresa Demo",
      "userId": 10,
      "userName": "Administrador",
      "userEmail": "admin@example.com",
      "title": "Consulta mantenimiento",
      "messageCount": 6,
      "createdAt": "2026-08-23T18:00:00-03:00",
      "updatedAt": "2026-08-23T18:05:00-03:00"
    }
  ],
  "pagination": {"page": 1, "perPage": 25, "total": 1, "pages": 1},
  "scope": {"type": "company", "companyId": 4}
}
```

## Detalle

`GET /mantenimiento/chatbot/auditoria/{conversationId}`

Devuelve cabecera y todos los mensajes ordenados cronológicamente. Cada mensaje incluye:

- `id`
- `role`
- `content`
- `toolCalls`
- `toolCallId`
- `tokensUsed`
- `createdAt`

Los `toolCalls` se decodifican y sanitizan recursivamente. También se sanitiza el JSON almacenado en `content` cuando el rol es `tool`. Claves sensibles como `authorization`, `api_key`, `access_token`, `password`, `secret`, `credential`, `cookie` y `headers` se reemplazan por `[REDACTED]`.

## Solo lectura

La API no expone `POST`, `PUT`, `PATCH` ni `DELETE` para conversaciones o mensajes. Los endpoints administrativos son exclusivamente de consulta.
