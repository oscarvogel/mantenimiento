# Robot por piezas — compacto y cuerpo completo

Ambas variantes (`fab` y `full`) usan SVG inline en
`frontend/src/pages/operations/components/ChatRobotVector.vue`. Comparten cabeza,
expresiones y paleta. Las referencias son los PNG del 12/09 a las 22:34:57
(compacto) y 22:13:46 (completo): carcasa blanca, franjas azules, visor navy,
ojos celestes y antenas naranjas. Es un redibujo simplificado por piezas;
los PNG y SVG originales se conservan como referencia.

`full` agrega torso, cuello, hombros mecánicos, brazos, manos, pulgar levantado,
emblema con llave y portapapeles. `ChatRobot.vue` selecciona la variante; las dos
quedan integradas en sus ubicaciones existentes del chat. Cambiar de variante
desmonta el SVG anterior y limpia sus animaciones.

## Piezas y estados

Los grupos `data-part` identifican cabeza, carcasa, visor, ojos, boca, antenas,
orejas y símbolo de estado. En `full`, el grupo `clipboard-assembly` mantiene
portapapeles y mano unidos durante el movimiento. Los pivotes de brazos están
en los hombros y el de la mano izquierda en la muñeca. Los IDs de degradados
son únicos por instancia.

| Estado | Expresión y movimiento |
| --- | --- |
| idle | Sonrisa y parpadeo espaciado |
| thinking | Mirada lateral y antenas articuladas |
| loading | Ojos entrecerrados y tres puntos en secuencia |
| success | Sonrisa, asentimiento y marca de confirmación |
| error | Expresión preocupada, negación breve y advertencia |
| offline | Ojos atenuados y símbolo de desconexión, sin movimiento |

Gestos adicionales del completo: respiración leve del torso en reposo,
inclinación del portapapeles al pensar, pulso suave de luces durante la carga,
gesto del pulgar al completar y movimiento breve del portapapeles ante error.
En desconexión queda quieto, con luces atenuadas. Se retiraron las animaciones
CSS que movían toda la imagen completa.

`ChatWidget` sigue siendo quien decide el estado. GSAP solo representa el valor
recibido: no interpreta respuestas ni modifica llamadas al backend.

`useChatRobotMotion.js` carga la animación de forma diferida. Cada cambio de estado
revierte el contexto anterior; al desmontar elimina observadores y listeners.
`chatRobotMotion.js` mantiene selectores dentro del SVG de cada instancia.
El movimiento se suspende fuera de pantalla, en pestañas ocultas y cuando
`prefers-reduced-motion: reduce` está activo, también si cambia en vivo.
La expresión y el símbolo permanecen visibles si no carga el módulo de animación.
GSAP se entrega en el chunk `robot-motion`, separado del vendor común.

## Revisión visual

`frontend/dev/robot.html` es una vista aislada de desarrollo, excluida de las
entradas del build de producción. Permite comparar el original y el SVG,
alternar variante y tema, seleccionar los seis estados y simular un ciclo de respuesta.
Acceso con el servidor Vite autorizado: `/dev/robot.html`.

Verificar tamaño real del FAB (56 px) y bienvenida (128 px), tema claro/oscuro, móvil, foco, cambios
rápidos de estado, múltiples instancias y movimiento reducido. Esta página no
prueba endpoints, conexión ni conversaciones reales. Las pruebas del stack
mantienen el destino establecido en AGENTS.md salvo autorización explícita.

Referencias oficiales: [context y limpieza](https://gsap.com/docs/v3/GSAP/gsap.context()/),
[timelines](https://gsap.com/docs/v3/GSAP/Timeline/).

## Evidencia de esta etapa (16/09/2026)

El usuario autorizó expresamente Vitest, build y vista aislada en su PC, sin
backend ni base. La primera etapa compacta pasó 267 tests en 47 archivos.
`npm run build` pasó y entregó GSAP separado (27,81 kB gzip).
`npm run qa:robot`, con Vite activo, comprobó en Chromium: movimiento independiente,
cambios rápidos de estado, expresiones con movimiento reducido, IDs únicos,
foco del botón y ausencia de overflow horizontal a 375 px. Las capturas
de escritorio/móvil en ambos temas se guardan en `writable/chatbot-gsap-qa/`
(ignoradas por Git). Se inspeccionaron las capturas del componente.

El usuario aceptó el compacto. La ampliación al completo pasó 273 tests en 47
archivos, el build y `npm run qa:robot`. Incluye revisión en
Chromium de ambas variantes, con capturas `fab-*` y `full-*`, movimiento de brazo
y portapapeles, convivencia del FAB con el completo y movimiento reducido en vivo.
Pendiente de aceptación del usuario: fidelidad y sensación de movimiento del
cuerpo completo. Esta evidencia corresponde a la vista aislada, no a una sesión
real del chatbot ni al stack remoto.
