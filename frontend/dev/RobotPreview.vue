<script setup>
import { onBeforeUnmount, ref } from 'vue'
import ChatRobot from '../src/pages/operations/components/ChatRobot.vue'
import { applyTheme, getTheme } from '../src/ui/theme.js'
import originalFab from '../../assets/brand/chatbot/robot-fab.svg?url'
import originalFull from '../../assets/brand/chatbot/robot-full.svg?url'

const state = ref('idle')
const variant = ref('fab')
const theme = ref(getTheme())
const states = { idle: 'En reposo', thinking: 'Pensando', loading: 'Cargando', success: 'Completado', error: 'Error', offline: 'Sin conexión' }
let timers = []
function select(next) { timers.forEach(clearTimeout); timers = []; state.value = next }
function simulate() {
  select('loading')
  timers = [setTimeout(() => { state.value = 'thinking' }, 900), setTimeout(() => { state.value = 'success' }, 3200), setTimeout(() => { state.value = 'idle' }, 5000)]
}
onBeforeUnmount(() => timers.forEach(clearTimeout))
function toggleTheme() { theme.value = applyTheme(theme.value === 'light' ? 'dark' : 'light') }
</script>

<template>
  <main class="robot-preview">
    <header><div><p class="eyebrow">ASISTENTE DE MANTENIMIENTO</p><h1>El robot, pieza por pieza</h1><p>Prueba visual de ambas variantes. Las acciones de esta página son simuladas.</p></div><button @click="toggleTheme">Tema {{ theme === 'dark' ? 'claro' : 'oscuro' }}</button></header>
    <div class="robot-preview__variants" role="group" aria-label="Variante del robot">
      <button :aria-pressed="variant === 'fab'" @click="variant = 'fab'">Compacto</button>
      <button :aria-pressed="variant === 'full'" @click="variant = 'full'">Cuerpo completo</button>
    </div>
    <section class="robot-preview__comparison" aria-label="Comparación de diseño">
      <article><p>Referencia original</p><img :src="variant === 'full' ? originalFull : originalFab" width="240" :height="variant === 'full' ? 300 : 200" style="object-fit: contain" alt="Robot original: carcasa blanca, franjas azules y antenas naranjas"></article>
      <article><p>SVG por piezas · {{ states[state] }}</p><ChatRobot :variant="variant" :state="state" class="robot-preview__large" :class="{ 'robot-preview__large--full': variant === 'full' }" /></article>
      <article><template v-if="variant === 'full'"><p>Bienvenida real · 128 px</p><ChatRobot variant="full" :state="state" /></template><p>Botón real · 56 px</p><button class="robot-preview__fab" aria-label="Abrir asistente IA" @click="simulate"><ChatRobot :state="state" /></button><small>Hacé clic para simular una respuesta.</small></article>
    </section>
    <section class="robot-preview__controls" aria-label="Estados del robot">
      <button v-for="(label, key) in states" :key="key" :aria-pressed="state === key" @click="select(key)">{{ label }}</button>
      <button @click="simulate">Simular conversación</button>
    </section>
    <p role="status">Estado: {{ states[state] }}</p>
    <p class="robot-preview__note">Ojos, antenas, cabeza, brazos y portapapeles tienen movimientos independientes. La preferencia de movimiento reducido del sistema deja las expresiones estáticas.</p>
    <section class="robot-preview__gallery" aria-label="Todos los estados">
      <article v-for="(label, key) in states" :key="key"><ChatRobot :variant="variant" :state="key" /><span>{{ label }}</span></article>
    </section>
  </main>
</template>

<style scoped>
.robot-preview { min-height: 100vh; padding: clamp(20px, 5vw, 72px); color: rgb(var(--ink)); background: rgb(var(--surface-subtle)); font-family: system-ui, sans-serif; }
header { display: flex; align-items: start; justify-content: space-between; gap: 24px; }
h1 { font-size: clamp(24px, 3vw, 38px); font-weight: 750; margin: 6px 0 12px; }
.eyebrow { color: rgb(var(--primary)); font-size: 11px; font-weight: 700; letter-spacing: .12em; }
button { border: 1px solid rgb(var(--border)); background: rgb(var(--surface-raised)); border-radius: 10px; padding: 10px 14px; min-height: 44px; }
button:focus-visible { outline: 3px solid #2196ff; outline-offset: 3px; }
button[aria-pressed="true"] { color: white; background: #075dca; }
.robot-preview__comparison { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin: 36px 0 24px; }
article { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; padding: 24px; border: 1px solid rgb(var(--border)); border-radius: 16px; background: rgb(var(--surface-raised)); }
.robot-preview__comparison article { min-height: 310px; }
.robot-preview__large { width: min(240px, 100%); height: 200px; padding: 0; background: transparent; box-shadow: none; }
.robot-preview__large--full { width: min(300px, 100%); height: 300px; }
.robot-preview__variants { display: flex; gap: 8px; margin-top: 24px; }
.robot-preview__fab { width: 56px; height: 56px; padding: 6px; border-radius: 50%; background: #075dca; display: grid; place-items: center; }
.robot-preview__controls { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.robot-preview__gallery { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; margin-top: 28px; }
.robot-preview__gallery article { padding: 20px 8px; font-size: 13px; }
.robot-preview__note, small { color: rgb(var(--ink-muted)); font-size: 13px; margin-top: 8px; }
@media (max-width: 760px) { header { flex-direction: column; } .robot-preview__comparison { grid-template-columns: 1fr; } .robot-preview__gallery { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
