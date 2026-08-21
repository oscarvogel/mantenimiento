# Diseño: Chatbot IA — Núcleo Conversacional, Proveedor y Arquitectura de Tools

**Issue:** #9  
**Fecha:** 2026-08-20  
**Estado:** Aprobado  
**Enfoque:** Tool = Use Case Adapter (Clean Architecture + Hexagonal)

---

## 1. Resumen

Agregar un chatbot integrado al sistema de mantenimiento que utilice un proveedor de IA configurable (MiniMax inicialmente) y pueda consultar o ejecutar funcionalidades del sistema mediante tools controladas. El chatbot es una capa conversacional que **nunca accede directamente a la base de datos** — cada tool delega a un caso de uso existente.

### Decisiones clave

| Decisión | Valor |
|----------|-------|
| Proveedor IA | MiniMax (adaptador flexible para cambio futuro) |
| UI | Widget flotante en todas las pantallas |
| Voz | Web Speech API (navegador) |
| Streaming | SSE (Server-Sent Events) |
| Persistencia | DB (conversaciones + mensajes) |
| Alcance #9 | Core + 1 tool de ejemplo (`buscar_equipo`) |

### Invariante de seguridad

**El chatbot nunca consulta la base de datos en forma directa, solo mediante puertos y casos de uso existentes.** Un usuario no puede consultar ni modificar datos de otra empresa a través del chatbot. Todas las operaciones están scopeadas por `empresa_id`.

---

## 2. Domain Model

Bounded context: `Chatbot`

```
Domain/Chatbot/
├── Conversation.php          # Entidad: id, usuario_id, empresa_id, titulo, created_at, updated_at
├── Message.php               # Entidad: id, conversation_id, role, content, tool_calls, tool_call_id, created_at
├── ToolDefinition.php        # Value Object: name, description, parameters, permission, isWrite, confirmationRequired, handlerClass
├── ToolCallRequest.php       # Value Object: id, toolName, arguments
├── ToolCallResult.php        # Value Object: toolCallId, name, result, success, errorMessage
└── ChatError.php             # Errores: ToolNotFound, PermissionDenied, ProviderError, RateLimited
```

### Roles de mensaje

- `user` — mensaje del usuario
- `assistant` — respuesta del asistente IA
- `system` — mensajes de sistema (contexto inicial, límites)
- `tool` — resultado de ejecución de una tool

### Reglas de dominio

1. Un mensaje `tool_call` solo se ejecuta si el usuario tiene el permiso declarado en la tool
2. Las tools de escritura (`isWrite=true`) requieren confirmación explícita antes de ejecutarse
3. El historial de mensajes es append-only
4. Cada conversación pertenece a un usuario y una empresa
5. La ventana de contexto al proveedor IA es configurable (últimos N mensajes)

---

## 3. Puertos (Application Layer)

```
Application/Chatbot/
├── Port/
│   ├── ConversationRepository.php    # save(Conversation), find(id), findByUser(userId)
│   ├── MessageRepository.php         # append(Message), findForConversation(conversationId, limit, offset)
│   ├── AIProvider.php                # sendMessage(messages, tools, options): AIResponse
│   ├── ToolRegistry.php              # all(): ToolDefinition[], find(name): ?ToolDefinition
│   ├── ToolExecutor.php              # execute(name, args, actorContext): ToolCallResult
│   └── ChatClock.php                 # now(): DateTimeImmutable
├── Command/
│   ├── SendMessageCommand.php        # conversationId, content, toolCallsConfirmados?
│   └── StartConversationCommand.php  # (vacío o con contexto inicial)
├── Handler/
│   ├── StartConversationHandler.php
│   └── ProcessMessageHandler.php
└── Result/
    ├── ConversationStartedResult.php
    └── MessageProcessedResult.php
```

### Flujo de ProcessMessageHandler

1. Guarda el mensaje del usuario en la conversación
2. Recupera historial de mensajes (ventana de contexto)
3. Obtiene tools disponibles (filtradas por permisos del usuario)
4. Envía al proveedor IA (con tools en formato function calling)
5. Si la IA responde con `tool_calls`:
   - Si son READ → ejecuta directamente
   - Si son WRITE → retorna `toolCallsPendientes` para confirmación
6. Si el usuario confirmó writes → ejecuta
7. Guarda respuesta del asistente
8. Retorna mensajes nuevos + metadata

### Streaming

El handler expone un método que retorna un `Generator` o callback para que el controller emita SSE. El proveedor IA se conecta con streaming y el handler va emitiendo chunks.

---

## 4. Infrastructure (Adapters)

```
Infrastructure/Chatbot/
├── AI/
│   ├── MiniMaxProvider.php           # Implementa AIProvider
│   ├── AIProviderConfig.php          # Lee config de .env
│   └── DTOs/
│       ├── MiniMaxRequest.php
│       └── MiniMaxResponse.php
├── Persistence/
│   ├── CodeIgniterConversationRepository.php
│   ├── CodeIgniterMessageRepository.php
│   └── Migrations/
│       └── CreateChatbotTables.php
├── Tools/
│   ├── SearchEquipmentTool.php       # buscar_equipo → EquipmentReadModel
│   └── ToolSchemaBuilder.php         # Convierte ToolDefinition a formato function calling
└── SSE/
    └── StreamingResponse.php         # Helper para SSE: headers + chunk encoding
```

### Migración

```sql
conversaciones
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  usuario_id      INT UNSIGNED NOT NULL
  empresa_id      INT UNSIGNED NOT NULL
  titulo          VARCHAR(255) NULL
  created_at      DATETIME NOT NULL
  updated_at      DATETIME NOT NULL
  INDEX idx_empresa_usuario (empresa_id, usuario_id)

mensajes
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  conversacion_id INT UNSIGNED NOT NULL
  role            ENUM('user','assistant','system','tool') NOT NULL
  content         TEXT NOT NULL
  tool_calls      JSON NULL
  tool_call_id    VARCHAR(255) NULL
  tokens_used     INT UNSIGNED NULL
  created_at      DATETIME NOT NULL
  INDEX idx_conversacion (conversacion_id, created_at)
  FOREIGN KEY (conversacion_id) REFERENCES conversaciones(id)
```

---

## 5. Presentation

### Controller (`app/Controllers/Chatbot.php`)

```
GET  /mantenimiento/chatbot                 → index (renderiza widget)
POST /mantenimiento/chatbot/conversaciones  → startConversation
POST /mantenimiento/chatbot/mensajes        → sendMessage
POST /mantenimiento/chatbot/mensajes/stream → sendMessageStream (SSE)
POST /mantenimiento/chatbot/confirmar       → confirmToolExecution
GET  /mantenimiento/chatbot/historial       → history (paginado)
```

Todos los endpoints usan `actor()` para auth, validan `empresa_id`, y retornan JSON con CSRF refresh.

### Rutas

```php
$routes->group('mantenimiento/chatbot', ['filter' => ['auth']], function ($routes) {
    $routes->get('/',               'Chatbot::index');
    $routes->post('conversaciones', 'Chatbot::startConversation', ['filter' => 'permission:chatbot.usar']);
    $routes->post('mensajes',       'Chatbot::sendMessage',       ['filter' => 'permission:chatbot.usar']);
    $routes->post('mensajes/stream','Chatbot::sendMessageStream', ['filter' => 'permission:chatbot.usar']);
    $routes->post('confirmar',      'Chatbot::confirmTool',       ['filter' => 'permission:chatbot.usar']);
    $routes->get('historial',       'Chatbot::history',           ['filter' => 'permission:chatbot.usar']);
});
```

Permiso nuevo: `chatbot.usar` — asignado a Solicitante, Técnico, Administrador.

### Frontend

```
frontend/src/pages/operations/components/
├── ChatWidget.vue          # Widget flotante: botón toggle + panel
├── ChatMessage.vue         # Burbuja de mensaje
├── ChatToolConfirm.vue     # Confirmación para tools de escritura
└── ChatVoiceButton.vue     # Micrófono con Web Speech API
```

`ChatWidget.vue` se monta en `ApplicationShell.vue` (siempre visible).

### Configuración (.env)

```
ai.enabled = false
ai.provider = minimax
ai.apiKey =
ai.model =
ai.timeoutSeconds = 30
ai.contextWindowMessages = 20
ai.rateLimitPerMinute = 60
```

---

## 6. Seguridad y Auditoría

### Reglas

| Regla | Implementación |
|-------|----------------|
| API key nunca al navegador | Solo en `.env`, leído por `AIProviderConfig` |
| Scope por empresa | `conversaciones.empresa_id` obligatorio en cada query |
| Permisos en server | `ToolRegistry` declara permiso. `ToolExecutor` valida |
| Sin SQL arbitrario | Solo tools registradas que delegan a use cases |
| Sin ejecución de código | No existen tools `exec`, `eval`, `system` |
| Confirmación en writes | `isWrite=true` requiere `confirmTool` |
| Rate limiting | Max 60 msg/min por usuario (configurable) |

### Auditoría

Cada invocación de tool se registra en `mensajes` con metadata: tool, userId, empresaId, success, duration_ms. Errores del proveedor se loguean sin exponer API key.

---

## 7. Tool de Ejemplo: `buscar_equipo`

### Definición

```php
ToolDefinition::read(
    name: 'buscar_equipo',
    description: 'Busca un equipo por código, patente o nombre. Devuelve ficha resumida.',
    parameters: [
        'query' => ['type' => 'string', 'description' => 'Código, patente o nombre'],
    ],
    permission: 'equipos.ver',
    handlerClass: SearchEquipmentTool::class,
)
```

### Handler

Delega a `EquipmentReadModel::search()` (puerto existente). No toca la DB.

### Ejemplo de interacción

```
Usuario: "¿Cuál es el camión CAM-014?"
→ AI → tool_call: buscar_equipo(query: "CAM-014")
→ ToolExecutor → EquipmentReadModel::search()
→ Resultado: [{codigo: "CAM-014", patente: "AB123CD", ...}]
→ AI: "El CAM-014 es un camión Volvo FH16, patente AB123CD..."
```

---

## 8. Pruebas

### Dominio (sin DB)
- `ToolDefinition` — construcción, permisos, serialización
- `ToolCallRequest` / `ToolCallResult` — value objects
- `Message` — creación, roles

### Application (puertos falsos)
- `ProcessMessageHandler` — sin tool calls, READ directo, WRITE con confirmación, sin permiso, proveedor falla
- `StartConversationHandler` — crea conversación con empresa_id

### Infrastructure (DB real)
- `SearchEquipmentTool` — scope por empresa
- `MiniMaxProvider` — contrato con respuesta mock
- Migración — tablas se crean correctamente

### HTTP/Feature
- Endpoints autenticados, CSRF, empresa scope, permiso `chatbot.usar`

---

## 9. Checklist de aceptación del issue #9

- [ ] Existe interfaz de chat integrada y responsive (widget flotante)
- [ ] El proveedor/modelo y API key se configuran fuera del código (.env)
- [ ] La API key nunca llega al frontend
- [ ] Existe un registro central de tools (ToolRegistry)
- [ ] Las tools delegan en casos de uso existentes
- [ ] Se respetan permisos, empresa y sucursal
- [ ] Se distinguen tools de lectura y escritura
- [ ] Las escrituras pueden solicitar confirmación
- [ ] Existe auditoría de invocaciones y resultados
- [ ] Fallos del proveedor IA no afectan la integridad del sistema
- [ ] La arquitectura admite agregar nuevas funcionalidades sin rehacer el chatbot
- [ ] Streaming SSE funciona correctamente
- [ ] La voz (Web Speech API) transcribe correctamente
- [ ] El historial se persiste en DB y se recupera al recargar
- [ ] Pruebas de dominio, application, infrastructure y HTTP pasan
