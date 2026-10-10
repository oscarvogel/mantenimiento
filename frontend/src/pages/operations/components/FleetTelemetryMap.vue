<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet.markercluster'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'

const props = defineProps({ units: { type: Array, default: () => [] } })
const mapElement = ref(null)
let map = null
let markers = null

const selectedSource = (unit) => unit.sources?.find((source) => source.role === 'PRINCIPAL' && source.position)
  ?? unit.sources?.find((source) => source.position)
  ?? null

const popupFor = (unit, source) => {
  const content = document.createElement('div')
  const title = document.createElement('strong')
  title.textContent = unit.plate && unit.plate !== unit.code ? `${unit.plate} · ${unit.code}` : unit.code
  const status = document.createElement('div')
  status.textContent = ({
    AL_DIA: 'Al día', RECIENTE: 'Reciente', DESACTUALIZADO: 'Desactualizado',
    SIN_RESPUESTA: 'Sin respuesta del proveedor', SIN_DATO: 'Sin señal registrada',
  })[source.freshness] ?? 'Sin señal registrada'
  const details = document.createElement('div')
  details.textContent = [
    source.fuelLiters === null || source.fuelLiters === undefined ? null : `${source.fuelLiters} l`,
    source.kilometers === null || source.kilometers === undefined ? null : `${new Intl.NumberFormat('es-AR').format(source.kilometers)} km`,
  ].filter(Boolean).join(' · ') || 'Sin lecturas disponibles'
  content.append(title, status, details)
  return content
}

const renderMarkers = () => {
  if (!map || !markers) return
  markers.clearLayers()
  const bounds = []

  for (const unit of props.units) {
    const source = selectedSource(unit)
    if (!source) continue
    const { latitude, longitude } = source.position
    if (!Number.isFinite(Number(latitude)) || !Number.isFinite(Number(longitude))
      || Number(latitude) < -90 || Number(latitude) > 90 || Number(longitude) < -180 || Number(longitude) > 180) continue

    const issue = (source.sensorIssues?.length ?? 0) > 0
    const marker = L.circleMarker([Number(latitude), Number(longitude)], {
      radius: 9,
      color: '#ffffff',
      weight: 2,
      fillColor: issue ? '#ef4444' : (source.freshness === 'SIN_RESPUESTA' || source.freshness === 'DESACTUALIZADO' ? '#f59e0b' : '#22c55e'),
      fillOpacity: 0.95,
    }).bindPopup(popupFor(unit, source))
    markers.addLayer(marker)
    bounds.push([Number(latitude), Number(longitude)])
  }

  if (bounds.length === 1) map.setView(bounds[0], 13)
  else if (bounds.length > 1) map.fitBounds(bounds, { padding: [32, 32], maxZoom: 12 })
}

onMounted(() => {
  map = L.map(mapElement.value, { scrollWheelZoom: false, preferCanvas: true })
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map)
  markers = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 42 })
  map.addLayer(markers)
  map.setView([-34.6037, -58.3816], 5)
  renderMarkers()
  requestAnimationFrame(() => map?.invalidateSize())
})

watch(() => props.units, renderMarkers, { deep: true })

onBeforeUnmount(() => {
  map?.remove()
  markers = null
  map = null
})
</script>

<template>
  <div class="fleet-map-frame">
    <div ref="mapElement" class="fleet-map" role="application" aria-label="Mapa de ubicación de la flota" />
  </div>
</template>

<style scoped>
.fleet-map-frame { min-height: 26rem; overflow: hidden; border-radius: 1rem; background: #e2e8f0; }
.fleet-map { width: 100%; height: 100%; min-height: 26rem; }
:deep(.leaflet-popup-content-wrapper), :deep(.leaflet-popup-tip) { background: #fff; color: #172033; }
:deep(.leaflet-popup-content) { line-height: 1.55; }
@media (max-width: 640px) { .fleet-map-frame, .fleet-map { min-height: 20rem; } }
</style>
