<script setup>
import { computed, ref, useId } from 'vue'
import { useChatRobotMotion } from './useChatRobotMotion.js'

const props = defineProps({
  state: { type: String, default: 'idle' },
  variant: { type: String, default: 'fab' },
})
const svg = ref(null)
// Unique paint servers: the FAB and panel header can coexist on the same page.
const id = useId().replace(/[^a-zA-Z0-9_-]/g, '')
const paint = (name) => `url(#${id}-${name})`
const eye = computed(() => ({
  idle: 'M-13 3 Q-13-12 0-12 Q13-12 13 3',
  thinking: 'M-11-2 Q0-12 11-2',
  loading: 'M-10 0 H10',
  success: 'M-13 2 Q0-18 13 2',
  error: 'M-12-8 L12 3',
  offline: 'M-11 2 H11',
}[props.state] ?? 'M-13 3 Q-13-12 0-12 Q13-12 13 3'))
const mouth = computed(() => ({
  idle: 'M101 153 Q120 157 139 153 Q135 168 120 168 Q105 168 101 153Z',
  thinking: 'M113 158 Q120 154 128 157',
  loading: 'M111 158 H129',
  success: 'M100 152 Q120 157 140 152 Q135 170 120 170 Q105 170 100 152Z',
  error: 'M107 163 Q120 151 133 163',
  offline: 'M110 161 H130',
}[props.state]))
useChatRobotMotion(svg, () => props.state)
</script>

<template>
  <!-- Hand-built geometry based on the original 12/09 robot; no raster or traced fragments. -->
  <svg ref="svg" class="robot-vector" :viewBox="variant === 'full' ? '-88 0 416 430' : '0 0 240 200'"
    :width="variant === 'full' ? 128 : 48" :height="variant === 'full' ? 128 : 48"
    xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" :data-state="state" :data-variant="variant">
    <defs>
      <linearGradient :id="`${id}-shell`" x1="0" y1="0" x2="1" y2="1">
        <stop stop-color="#fff" /><stop offset=".65" stop-color="#f4faff" /><stop offset="1" stop-color="#d8eaff" />
      </linearGradient>
      <linearGradient :id="`${id}-blue`" x1="0" y1="0" x2="1" y2="1">
        <stop stop-color="#25b5ff" /><stop offset=".5" stop-color="#008afa" /><stop offset="1" stop-color="#0051bd" />
      </linearGradient>
      <linearGradient :id="`${id}-orange`" x1="0" y1="0" x2="1" y2="1">
        <stop stop-color="#ffc028" /><stop offset=".55" stop-color="#ffa500" /><stop offset="1" stop-color="#ef8500" />
      </linearGradient>
      <linearGradient :id="`${id}-visor`" x1="0" y1="0" x2="0" y2="1">
        <stop stop-color="#091f46" /><stop offset="1" stop-color="#03132e" />
      </linearGradient>
      <linearGradient :id="`${id}-metal`" x1="0" y1="0" x2="1" y2="1">
        <stop stop-color="#47688d" /><stop offset="1" stop-color="#112744" />
      </linearGradient>
    </defs>
    <g data-part="robot" stroke="#041638" stroke-linejoin="round" stroke-linecap="round">
      <g v-if="variant === 'full'" data-part="body" stroke-width="6">
        <g data-part="lower-body">
          <path d="M50 304Q120 285 190 304L184 382Q175 414 120 418Q65 414 56 382Z" :fill="paint('blue')" />
          <path d="M78 347L83 401M162 347L157 401" fill="none" stroke="#034077" stroke-width="5" />
          <path d="M58 308Q120 333 182 308" fill="none" stroke="#43bfff" stroke-width="5" />
        </g>
        <g data-part="arm-left">
          <g data-part="shoulder-left">
            <path d="M30 215Q-1 192-26 220Q-43 242-24 265L9 280L38 246Z" :fill="paint('blue')" />
            <ellipse cx="-18" cy="238" rx="19" ry="25" transform="rotate(26 -18 238)" :fill="paint('shell')" />
            <g fill="#071b38" stroke="none">
              <circle cx="-18" cy="226" r="3" /><circle cx="-28" cy="235" r="3" />
              <circle cx="-15" cy="238" r="4" /><circle cx="-7" cy="248" r="3" />
              <circle cx="-23" cy="250" r="3" /><circle cx="-8" cy="227" r="3" />
            </g>
          </g>
          <g data-part="forearm-left">
            <path d="M-26 259L-56 300Q-66 323-48 337L-9 327L13 282Z" :fill="paint('blue')" />
            <path d="M-30 267L-48 297" fill="none" stroke="#e5f5ff" stroke-width="7" />
            <path d="M-54 303Q-32 302-14 323" fill="none" stroke="#053b71" stroke-width="5" />
            <g data-part="hand-left">
              <path d="M-38 313Q-72 317-67 344Q-62 370-27 368L-8 361L-7 327Z" :fill="paint('shell')" />
              <path d="M-60 350Q-48 362-20 356" fill="none" stroke="#d3e5f4" stroke-width="5" />
              <ellipse cx="-10" cy="339" rx="13" ry="26" :fill="paint('blue')" />
              <path d="M-4 317L4 294Q6 285 13 290Q22 295 17 317" :fill="paint('shell')" />
              <g :fill="paint('blue')" stroke-width="5">
                <rect x="0" y="313" width="34" height="17" rx="8" />
                <rect x="0" y="331" width="37" height="17" rx="8" />
                <rect x="0" y="349" width="32" height="17" rx="8" />
              </g>
              <path d="M4 318V324M4 336V342M4 354V360" stroke="#3d638b" stroke-width="4" />
            </g>
          </g>
        </g>
        <g data-part="arm-right">
          <g data-part="shoulder-right">
            <path d="M210 215Q243 195 268 225Q283 248 263 266L230 280L201 246Z" :fill="paint('blue')" />
            <ellipse cx="261" cy="239" rx="19" ry="25" transform="rotate(-26 261 239)" :fill="paint('shell')" />
            <g fill="#071b38" stroke="none">
              <circle cx="258" cy="227" r="3" /><circle cx="270" cy="238" r="3" />
              <circle cx="258" cy="240" r="4" /><circle cx="250" cy="249" r="3" />
              <circle cx="265" cy="252" r="3" /><circle cx="248" cy="229" r="3" />
            </g>
          </g>
          <g data-part="forearm-right">
            <path d="M259 262L283 299Q299 322 289 346L253 359L231 292Z" :fill="paint('blue')" />
            <path d="M277 308Q306 316 302 347Q299 371 270 379L251 361Z" :fill="paint('shell')" />
            <g data-part="clipboard-assembly">
              <g data-part="clipboard">
                <path d="M224 278L292 283Q301 284 297 294L247 396Q245 402 236 400L176 394Q169 393 173 384L214 286Q217 278 224 278Z" :fill="paint('metal')" />
                <path d="M221 288L183 382" fill="none" stroke="#52769b" stroke-width="4" />
                <path d="M245 272L274 274Q279 274 277 280L270 293L238 290Q234 290 236 285L240 275Q241 271 245 272Z" :fill="paint('orange')" stroke-width="5" />
                <path d="M246 276L271 278" fill="none" stroke="#ffcf59" stroke-width="3" />
              </g>
              <g data-part="hand-right" :fill="paint('blue')" stroke-width="5">
                <path d="M270 331Q290 338 278 361Q273 375 256 378L246 364Z" />
                <path d="M244 323L260 328Q269 333 264 341Q262 346 254 343L238 337Q230 333 235 326Q238 322 244 323Z" />
                <path d="M236 341L253 347Q261 351 256 358Q253 363 246 360L230 355Q221 351 227 344Q230 340 236 341Z" />
                <path d="M232 358L246 364Q255 368 249 375Q246 380 240 377L226 371Q220 368 224 362Q226 358 232 358Z" />
                <path d="M258 332L255 338M249 350L246 356M242 367L239 373" stroke="#3d638b" stroke-width="4" />
              </g>
            </g>
          </g>
        </g>
        <g data-part="chest">
          <path d="M63 203Q46 205 31 214Q22 239 30 274Q36 298 60 311L78 330Q120 342 162 330L180 311Q204 298 210 274Q218 239 209 214Q194 205 177 203L160 226H80Z" :fill="paint('shell')" />
          <path d="M64 204L80 225H160L176 204Q120 194 64 204Z" :fill="paint('blue')" />
          <path d="M36 278Q48 293 69 302L83 320Q120 330 157 320L171 302Q192 293 204 278" fill="none" stroke="#b4ddf7" stroke-width="4" />
          <g data-part="chest-lights" :fill="paint('orange')" stroke-width="5">
            <path d="M43 244L68 249L73 270L48 264Z" />
            <path d="M197 244L172 249L167 270L192 264Z" />
            <path d="M54 252L63 254L65 260L56 258ZM186 252L177 254L175 260L184 258Z" fill="white" stroke="none" />
          </g>
          <path d="M92 255Q90 249 98 249H142Q150 249 148 255L143 309Q142 317 135 317H105Q98 317 97 309Z" :fill="paint('visor')" stroke-width="4" />
          <path d="M95 261H145M97 279H143M99 297H141" stroke="#0474ca" stroke-width="8" />
          <path d="M76 274L83 313M164 274L157 313" stroke-width="4" />
        </g>
        <g data-part="maintenance-badge">
          <path d="M60 350Q120 358 180 350L177 375Q120 385 63 375Z" :fill="paint('blue')" />
          <circle cx="120" cy="366" r="29" :fill="paint('orange')" />
          <path d="M103 386L122 365Q136 370 139 357L130 362L122 356L123 346Q110 350 115 362L96 382Z" fill="#052147" stroke="none" />
          <path d="M101 355Q106 344 117 342" fill="none" stroke="#ffdb78" stroke-width="3" />
        </g>
        <g data-part="neck" :fill="paint('metal')" stroke-width="5">
          <path d="M91 184H149V208Q120 220 91 208Z" />
          <path d="M94 194Q120 205 146 194" fill="none" stroke="#061a37" stroke-width="4" />
        </g>
      </g>
      <g data-part="head">
        <g data-part="antenna-left">
          <path d="M28 76V34" fill="none" stroke-width="10" />
          <circle cx="28" cy="25" r="14" :fill="paint('orange')" stroke-width="5" />
          <circle data-part="antenna-light" cx="24" cy="21" r="3" fill="white" stroke="none" />
        </g>
        <g data-part="antenna-right">
          <path d="M212 76V34" fill="none" stroke-width="10" />
          <circle cx="212" cy="25" r="14" :fill="paint('orange')" stroke-width="5" />
          <circle data-part="antenna-light" cx="208" cy="21" r="3" fill="white" stroke="none" />
        </g>
        <g data-part="ear-left">
          <ellipse cx="29" cy="110" rx="20" ry="33" :fill="paint('blue')" stroke-width="6" />
          <path d="M17 86Q7 111 17 134Q25 112 17 86" :fill="paint('orange')" stroke="none" />
        </g>
        <g data-part="ear-right">
          <ellipse cx="211" cy="110" rx="20" ry="33" :fill="paint('blue')" stroke-width="6" />
          <path d="M223 86Q233 111 223 134Q215 112 223 86" :fill="paint('orange')" stroke="none" />
        </g>
        <g data-part="shell">
          <path d="M120 22C70 22 38 49 36 96C32 154 43 183 120 187C197 183 208 154 204 96C202 49 170 22 120 22Z"
            :fill="paint('shell')" stroke-width="6" />
          <path d="M68 38Q89 76 69 130Q68 157 85 181M172 38Q151 76 171 130Q172 157 155 181"
            fill="none" stroke="#0088ed" stroke-width="8" />
          <path d="M120 22C70 22 38 49 36 96C32 154 43 183 120 187C197 183 208 154 204 96C202 49 170 22 120 22Z"
            fill="none" stroke-width="6" />
          <path d="M97 23Q120 18 143 23Q151 25 148 35L143 53Q141 59 132 60H108Q99 59 97 53L92 35Q89 25 97 23Z"
            :fill="paint('blue')" stroke-width="6" />
          <path d="M98 29Q120 24 141 29" fill="none" stroke="#4cc5ff" stroke-width="3" />
        </g>
        <g data-part="visor">
          <path d="M68 78Q120 71 172 78Q190 80 190 103V119Q190 135 174 137Q120 143 66 137Q50 135 50 119V103Q50 80 68 78Z"
            :fill="paint('visor')" stroke-width="3" />
          <g data-part="eyes" fill="none" stroke="#31b9f4" stroke-width="8">
            <g transform="translate(86 117)"><g data-part="eye-left"><path :d="eye" /></g></g>
            <g transform="translate(154 117)"><g data-part="eye-right"><path :d="eye" :transform="state === 'error' ? 'scale(-1 1)' : undefined" /></g></g>
          </g>
        </g>
        <g data-part="mouth">
          <path :d="mouth" :fill="['idle', 'success'].includes(state) ? '#06a5f7' : 'none'" stroke-width="5" />
        </g>
      </g>
      <g :transform="variant === 'full' ? 'translate(67 36)' : undefined">
      <g v-if="state !== 'idle'" data-part="status-symbol">
        <circle cx="207" cy="163" r="21" class="robot-vector__status" stroke-width="3" />
        <g v-if="state === 'thinking' || state === 'loading'" fill="#30b8f3" stroke="none">
          <circle data-part="status-dot" cx="198" cy="163" r="2.8" />
          <circle data-part="status-dot" cx="207" cy="163" r="2.8" />
          <circle data-part="status-dot" cx="216" cy="163" r="2.8" />
        </g>
        <path v-else-if="state === 'success'" d="M197 163L204 170L217 156" fill="none" stroke="#30b8f3" stroke-width="5" />
        <g v-else-if="state === 'error'" stroke="#ffaf24" stroke-width="4">
          <path d="M207 153V164" /><circle cx="207" cy="172" r=".8" />
        </g>
        <g v-else-if="state === 'offline'" fill="none" stroke="#91a8c3" stroke-width="3.5">
          <path d="M197 158Q207 150 217 158M201 164Q207 159 213 164M196 152L218 174" />
          <circle cx="207" cy="170" r="1" />
        </g>
      </g>
      </g>
    </g>
  </svg>
</template>

<style scoped>
.robot-vector { display: block; width: 100%; height: 100%; overflow: visible; }
.robot-vector__status { fill: #071b38; stroke: #d8eaff; }
</style>
