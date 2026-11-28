<script setup>
// Buscador del encabezado: patente o código y directo a la ficha del equipo.
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
  url: { type: String, required: true },
  // Inyectable para las pruebas: jsdom no permite espiar window.location.
  navigate: { type: Function, default: (href) => window.location.assign(href) },
})

const MIN_LENGTH = 2
const query = ref('')
const items = ref([])
const status = ref('idle') // idle | loading | done | error
const activeIndex = ref(-1)
const open = ref(false)
let timer = null
let request = 0

const trimmed = computed(() => query.value.trim())
const expanded = computed(() => open.value && trimmed.value.length >= MIN_LENGTH && status.value !== 'idle')
const activeId = computed(() => (expanded.value && activeIndex.value >= 0 ? `equipment-search-option-${activeIndex.value}` : undefined))

const search = async (value) => {
  const current = ++request
  status.value = 'loading'
  try {
    const response = await fetch(`${props.url}?q=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const data = await response.json()
    if (current !== request) return
    items.value = Array.isArray(data.items) ? data.items : []
    activeIndex.value = items.value.length ? 0 : -1
    status.value = 'done'
  } catch {
    if (current !== request) return
    items.value = []
    status.value = 'error'
  }
}

watch(trimmed, (value) => {
  clearTimeout(timer)
  open.value = true
  if (value.length < MIN_LENGTH) {
    request++
    items.value = []
    status.value = 'idle'
    return
  }
  timer = setTimeout(() => search(value), 250)
})
onBeforeUnmount(() => clearTimeout(timer))

const onKeydown = (event) => {
  if (event.key === 'Escape') {
    open.value = false
    return
  }
  if (!expanded.value || !items.value.length) return
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    const step = event.key === 'ArrowDown' ? 1 : -1
    activeIndex.value = (activeIndex.value + step + items.value.length) % items.value.length
  } else if (event.key === 'Enter' && activeIndex.value >= 0) {
    event.preventDefault()
    props.navigate(items.value[activeIndex.value].href)
  }
}
</script>

<template>
  <div class="relative" @focusout="(event) => { if (!event.currentTarget.contains(event.relatedTarget)) open = false }">
    <label class="flex h-[46px] items-center gap-2.5 rounded-pill bg-surface-muted px-4 text-ink-subtle focus-within:ring-2 focus-within:ring-border-focus">
      <MagnifyingGlassIcon class="size-[18px] shrink-0" aria-hidden="true" />
      <span class="sr-only">Buscar equipo por patente o código</span>
      <input
        v-model="query"
        type="search"
        role="combobox"
        autocomplete="off"
        placeholder="Buscar equipo por patente o código"
        class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base text-ink placeholder:text-ink-subtle focus:outline-none focus:ring-0"
        aria-autocomplete="list"
        aria-controls="equipment-search-results"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-activedescendant="activeId"
        @focus="open = true"
        @keydown="onKeydown"
      />
    </label>

    <div
      v-show="expanded"
      class="absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-card border border-border-subtle bg-surface-raised p-2 shadow-card"
    >
      <ul v-if="status === 'done' && items.length" id="equipment-search-results" role="listbox" aria-label="Equipos encontrados">
        <li
          v-for="(item, index) in items"
          :id="`equipment-search-option-${index}`"
          :key="item.id"
          role="option"
          :aria-selected="index === activeIndex ? 'true' : 'false'"
        >
          <a
            :href="item.href"
            tabindex="-1"
            class="block rounded-lg px-3 py-2"
            :class="index === activeIndex ? 'bg-surface-muted' : 'hover:bg-surface-muted'"
            @mouseenter="activeIndex = index"
          >
            <span class="block text-sm font-semibold text-ink">{{ item.label }}</span>
            <span class="block text-xs text-ink-muted">{{ item.detail }}</span>
          </a>
        </li>
      </ul>
      <p v-else-if="status === 'loading'" class="px-3 py-2 text-sm text-ink-muted" role="status">Buscando…</p>
      <p v-else-if="status === 'error'" class="px-3 py-2 text-sm text-danger-strong" role="alert">No pudimos buscar. Probá de nuevo.</p>
      <p v-else class="px-3 py-2 text-sm text-ink-muted" role="status">No encontramos equipos con «{{ trimmed }}».</p>
    </div>
  </div>
</template>
