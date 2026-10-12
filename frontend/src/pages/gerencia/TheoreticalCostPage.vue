<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  ArrowDownTrayIcon,
  ArrowLeftIcon,
  ArrowPathIcon,
  ChartBarIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
  PlusIcon,
  TruckIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({ data: { type: Object, required: true } })

const apiRoot = '/gerencial/costo-teorico-km'
const urls = computed(() => props.data.urls ?? {})
const canEdit = computed(() => props.data.canEdit === true)
const csrfHash = ref(props.data.csrf?.hash ?? '')
const loading = ref(false)
const calculating = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const notice = ref('')
const exchangeLoading = ref(false)
const exchangeError = ref('')
const activeTab = ref('calculator')
const activeResult = ref(null)
const assets = ref({ tractores: [], semis: [] })
const scenarios = ref([])
const selectedScenario = ref(null)
const editingScenarioId = ref(null)
const scenarioName = ref('')
const fromDate = ref('')
const toDate = ref('')

const standardOtherItems = () => [
  { codigo: 'lubricantes', nombre: 'Lubricantes', importe: '0', unidad: 'por_km', moneda: 'ARS' },
  { codigo: 'costo_financiero', nombre: 'Costo financiero', importe: '0', unidad: 'por_mes', moneda: 'ARS' },
]
const standardOtherCodes = new Set(standardOtherItems().map((item) => item.codigo))

const parameters = reactive({
  km_mensuales: '',
  fx: { ars_por_usd: '', fecha: '', fuente: '' },
  tractor: { valor_usd: '', valor_residual_usd: '', vida_util_anios: '' },
  semi: { valor_usd: '', valor_residual_usd: '', vida_util_anios: '' },
  combustible: { litros_100km: '', precio: '', moneda: 'ARS', fecha: '' },
  neumaticos: { costo_juego: '', recapados: '', vida_util_km: '', moneda: 'ARS' },
  mantenimiento_pct: '',
  costos_mensuales: [
    { codigo: 'seguro', nombre: 'Seguro', importe: '', moneda: 'ARS' },
    { codigo: 'patentes_tasas', nombre: 'Patentes y tasas', importe: '', moneda: 'ARS' },
    { codigo: 'personal', nombre: 'Personal', importe: '', moneda: 'ARS' },
    { codigo: 'gastos_generales', nombre: 'Gastos generales', importe: '', moneda: 'ARS' },
  ],
  otros_rubros: standardOtherItems(),
  recargo_pct: '',
})
const autoResidualValues = reactive({ tractor: null, semi: null })

const selection = reactive({ tractor_id: '', semi_id: '' })
const trip = reactive({ km_facturables: '', km_no_facturados: '', peajes: '', moneda: 'ARS' })

const urlAliases = {
  assets: ['assets', 'activos'],
  exchangeRate: ['exchangeRate'],
  calculate: ['calculate', 'calcular'],
  scenarios: ['scenarios', 'escenarios'],
  saveScenario: ['saveScenario', 'save', 'guardarEscenario', 'escenarios'],
  scenarioVersion: ['scenarioVersion', 'version'],
  excel: ['excel', 'export'],
}
const requestUrl = (key, fallback) => {
  const configured = urlAliases[key]?.map((alias) => urls.value[alias]).find(Boolean)
  return configured || `${apiRoot}${fallback}`
}
const numberOrNull = (value) => value === '' || value === null || value === undefined ? null : Number(value)
const setEstimatedResidual = (key) => {
  const replacementValue = Number(parameters[key].valor_usd)
  if (!Number.isFinite(replacementValue) || replacementValue < 0) return
  const residual = Math.round(replacementValue * 0.3 * 100) / 100
  autoResidualValues[key] = residual
  parameters[key].valor_residual_usd = String(residual)
}
const markResidualManual = (key) => { autoResidualValues[key] = null }
for (const key of ['tractor', 'semi']) {
  watch(() => parameters[key].valor_usd, (replacementValue) => {
    const currentResidual = parameters[key].valor_residual_usd
    const previousAutoValue = autoResidualValues[key]
    const followsAutoEstimate = previousAutoValue !== null && Number(currentResidual) === previousAutoValue
    if (currentResidual !== '' && !followsAutoEstimate) return
    if (replacementValue === '' || replacementValue === null || replacementValue === undefined || !Number.isFinite(Number(replacementValue)) || Number(replacementValue) < 0) {
      if (followsAutoEstimate) parameters[key].valor_residual_usd = ''
      autoResidualValues[key] = null
      return
    }
    setEstimatedResidual(key)
  })
}
const effectiveResidual = (residual, replacementValue) => {
  const entered = numberOrNull(residual)
  if (entered !== null) return entered
  const value = numberOrNull(replacementValue)
  return value !== null && value >= 0 ? Math.round(value * 0.3 * 100) / 100 : null
}
const codeFromName = (name) => String(name || '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLowerCase()
  .replace(/[^a-z0-9]+/g, '_')
  .replace(/^_|_$/g, '')
const normalizedParameters = () => ({
  km_mensuales: numberOrNull(parameters.km_mensuales),
  fx: {
    ars_por_usd: numberOrNull(parameters.fx.ars_por_usd),
    fecha: parameters.fx.fecha || null,
    fuente: parameters.fx.fuente.trim() || null,
  },
  tractor: {
    valor_usd: numberOrNull(parameters.tractor.valor_usd),
    residual_usd: effectiveResidual(parameters.tractor.valor_residual_usd, parameters.tractor.valor_usd),
    vida_util_anios: numberOrNull(parameters.tractor.vida_util_anios),
  },
  semi: {
    valor_usd: numberOrNull(parameters.semi.valor_usd),
    residual_usd: effectiveResidual(parameters.semi.valor_residual_usd, parameters.semi.valor_usd),
    vida_util_anios: numberOrNull(parameters.semi.vida_util_anios),
  },
  combustible: {
    litros_100km: numberOrNull(parameters.combustible.litros_100km),
    precio: numberOrNull(parameters.combustible.precio),
    moneda: parameters.combustible.moneda,
    fecha: parameters.combustible.fecha || null,
  },
  neumaticos: {
    costo_juego: numberOrNull(parameters.neumaticos.costo_juego),
    recapados: numberOrNull(parameters.neumaticos.recapados),
    vida_util_km: numberOrNull(parameters.neumaticos.vida_util_km),
    moneda: parameters.neumaticos.moneda,
  },
  mantenimiento_pct: numberOrNull(parameters.mantenimiento_pct),
  costos_mensuales: parameters.costos_mensuales.map((item) => ({
    codigo: item.codigo || codeFromName(item.nombre),
    nombre: item.nombre,
    unidad: 'por_mes',
    importe: numberOrNull(item.importe),
    moneda: item.moneda,
  })),
  otros_rubros: parameters.otros_rubros.map((item) => ({
    codigo: item.codigo || codeFromName(item.nombre),
    nombre: item.nombre,
    importe: numberOrNull(item.importe),
    unidad: item.unidad,
    moneda: item.moneda,
  })),
  recargo_pct: numberOrNull(parameters.recargo_pct),
})

const normalizedTrip = () => ({
  km_facturables: numberOrNull(trip.km_facturables),
  km_no_facturados: numberOrNull(trip.km_no_facturados),
  peajes: numberOrNull(trip.peajes),
  moneda: trip.moneda,
})

const readJson = async (response) => {
  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    const message = body.error?.message
      || body.message
      || (typeof body.error === 'string' ? body.error : null)
      || 'No se pudo completar la operación. Revisá los datos e intentá de nuevo.'
    throw new Error(message)
  }
  return body
}

const apiPost = async (url, body) => {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfHash.value },
    body: JSON.stringify(body),
  })
  const result = await readJson(response)
  if (result.csrf?.hash) csrfHash.value = result.csrf.hash
  return result
}

const loadAssets = async () => {
  const response = await fetch(requestUrl('assets', '/activos'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  const body = await readJson(response)
  assets.value = body.data ?? { tractores: [], semis: [] }
}

const loadBcraExchangeRate = async (force = false) => {
  if (exchangeLoading.value || (!force && parameters.fx.ars_por_usd)) return
  exchangeLoading.value = true
  exchangeError.value = ''
  try {
    const response = await fetch(requestUrl('exchangeRate', '/cotizacion-bcra'), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    const body = await readJson(response)
    if (!body.data?.ars_por_usd || !body.data?.fecha || !body.data?.fuente) {
      throw new Error('El BCRA no devolvió una cotización completa.')
    }
    Object.assign(parameters.fx, {
      ars_por_usd: String(body.data.ars_por_usd),
      fecha: body.data.fecha,
      fuente: body.data.fuente,
    })
  } catch (error) {
    exchangeError.value = error.message
  } finally {
    exchangeLoading.value = false
  }
}

const markExchangeRateManual = () => {
  if (parameters.fx.fuente.startsWith('BCRA ·')) {
    parameters.fx.fuente = 'Modificada manualmente (referencia BCRA)'
  }
}

const loadScenarios = async () => {
  const params = new URLSearchParams()
  if (selection.tractor_id) params.set('tractor_id', selection.tractor_id)
  if (selection.semi_id) params.set('semi_id', selection.semi_id)
  if (fromDate.value) params.set('desde', fromDate.value)
  if (toDate.value) params.set('hasta', toDate.value)
  const query = params.size ? `?${params.toString()}` : ''
  const response = await fetch(`${requestUrl('scenarios', '/escenarios')}${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  const body = await readJson(response)
  scenarios.value = body.data?.items ?? []
}

const initialize = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    await Promise.all([loadAssets(), loadScenarios(), loadBcraExchangeRate()])
  } catch (error) {
    errorMessage.value = error.message
  } finally {
    loading.value = false
  }
}

const calculate = async () => {
  errorMessage.value = ''
  notice.value = ''
  calculating.value = true
  try {
    if (selectedScenario.value && (
      String(selectedScenario.value.tractor?.id ?? '') !== String(selection.tractor_id)
      || String(selectedScenario.value.semi?.id ?? '') !== String(selection.semi_id)
    )) {
      // Cambiar el conjunto inicia otro escenario, no una versión de la unidad anterior.
      editingScenarioId.value = null
      scenarioName.value = ''
    }
    const body = await apiPost(requestUrl('calculate', '/calcular'), {
      tractor_id: numberOrNull(selection.tractor_id),
      semi_id: numberOrNull(selection.semi_id),
      parametros: normalizedParameters(),
      viaje: normalizedTrip(),
    })
    activeResult.value = body.data?.resultado ?? null
    selectedScenario.value = null
    if (!activeResult.value) notice.value = 'El servidor no devolvió un resultado para estos supuestos.'
  } catch (error) {
    errorMessage.value = error.message
  } finally {
    calculating.value = false
  }
}

const saveScenario = async () => {
  if (!scenarioName.value.trim()) {
    errorMessage.value = 'Escribí un nombre para reconocer este escenario en el historial.'
    return
  }
  saving.value = true
  errorMessage.value = ''
  notice.value = ''
  try {
    const body = await apiPost(requestUrl('saveScenario', '/escenarios'), {
      escenario_id: editingScenarioId.value,
      nombre: scenarioName.value.trim(),
      tractor_id: numberOrNull(selection.tractor_id),
      semi_id: numberOrNull(selection.semi_id),
      parametros: normalizedParameters(),
      viaje: normalizedTrip(),
    })
    activeResult.value = body.data?.resultado ?? activeResult.value
    if (body.data?.id) {
      editingScenarioId.value = body.data.id
      selectedScenario.value = {
        id: body.data.id,
        version: body.data.version,
        guardado_en: body.data.guardado_en,
      }
    }
    notice.value = body.data?.version
      ? `Escenario guardado como versión ${body.data.version}. Ya podés volver a abrirlo desde el historial.`
      : 'Escenario guardado. Ya podés volver a abrirlo desde el historial.'
    await loadScenarios()
    activeTab.value = 'calculator'
  } catch (error) {
    errorMessage.value = error.message
  } finally {
    saving.value = false
  }
}

const selectScenario = async (scenario, version = null) => {
  errorMessage.value = ''
  notice.value = ''
  loading.value = true
  try {
    const scenarioId = scenario.id
    const versionNumber = version ?? scenario.version
    const response = await fetch(scenarioVersionUrl(scenarioId, versionNumber), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    const body = await readJson(response)
    const record = body.data
    if (!record) throw new Error('No se encontró la versión seleccionada.')
    selectedScenario.value = record
    editingScenarioId.value = null
    const baseName = String(record.nombre || scenario.nombre || 'Escenario').trim()
    scenarioName.value = `${baseName.slice(0, 112).trimEnd()} (copia)`
    selection.tractor_id = String(record.tractor?.id ?? scenario.tractor_id ?? '')
    selection.semi_id = String(record.semi?.id ?? scenario.semi_id ?? '')
    autoResidualValues.tractor = null
    autoResidualValues.semi = null
    applyParameters(record.parametros ?? {})
    applyTrip(record.viaje ?? {})
    activeResult.value = record.resultado ?? null
    activeTab.value = 'calculator'
  } catch (error) {
    errorMessage.value = error.message
  } finally {
    loading.value = false
  }
}

const applyParameters = (source) => {
  for (const key of ['km_mensuales', 'mantenimiento_pct', 'recargo_pct']) {
    parameters[key] = source[key] ?? ''
  }
  for (const key of ['fx', 'tractor', 'semi', 'combustible', 'neumaticos']) {
    Object.assign(parameters[key], source[key] ?? {})
  }
  for (const key of ['tractor', 'semi']) {
    if (source[key]?.residual_usd !== undefined) {
      parameters[key].valor_residual_usd = source[key].residual_usd
    }
  }
  if (Array.isArray(source.costos_mensuales)) parameters.costos_mensuales = source.costos_mensuales.map((item) => ({ ...item, importe: item.importe ?? '' }))
  if (Array.isArray(source.otros_rubros)) {
    const savedItems = source.otros_rubros
    const standards = standardOtherItems().map((defaults) => {
      const saved = savedItems.find((item) => item.codigo === defaults.codigo)
      return { ...defaults, ...saved, importe: saved?.importe ?? defaults.importe }
    })
    const custom = savedItems
      .filter((item) => !standardOtherCodes.has(item.codigo))
      .map((item) => ({ ...item, importe: item.importe ?? '' }))
    parameters.otros_rubros = [...standards, ...custom]
  }
}

const applyTrip = (source) => Object.assign(trip, {
  km_facturables: source.km_facturables ?? '',
  km_no_facturados: source.km_no_facturados ?? '',
  peajes: source.peajes ?? '',
  moneda: source.moneda ?? 'ARS',
})

const addMonthlyCost = () => parameters.costos_mensuales.push({ codigo: '', nombre: '', importe: '', moneda: 'ARS' })
const addOtherItem = () => parameters.otros_rubros.push({ codigo: '', nombre: '', importe: '', unidad: 'por_km', moneda: 'ARS' })
const loadDocumentedAssumptions = () => {
  parameters.combustible.litros_100km = '37.04'
  parameters.neumaticos.vida_util_km = '250000'

  for (const key of ['tractor', 'semi']) {
    if (parameters[key].valor_residual_usd === '' && parameters[key].valor_usd !== '') setEstimatedResidual(key)
  }

  const tireSetCost = Number(parameters.neumaticos.costo_juego)
  if (parameters.neumaticos.costo_juego !== '' && Number.isFinite(tireSetCost) && tireSetCost >= 0) {
    parameters.neumaticos.recapados = (tireSetCost * 0.3).toFixed(2)
  }
  if (parameters.neumaticos.recapados === '') parameters.neumaticos.recapados = '0'
  for (const item of parameters.otros_rubros) {
    if (item.importe === '') item.importe = '0'
  }

  notice.value = 'Se cargaron los supuestos documentados y los residuales estimados al 30%. Los rubros sin un importe estándar actual comienzan en $0 editable; cargá tus valores para incluirlos.'
  errorMessage.value = ''
}

const ratio = (result) => result?.cobertura?.porcentaje ?? result?.cobertura?.porcentaje_completo ?? null
const coverageComplete = computed(() => activeResult.value?.cobertura === 'completa'
  || activeResult.value?.cobertura?.completa === true
  || activeResult.value?.cobertura?.estado === 'COMPLETA'
  || activeResult.value?.cobertura?.estado === 'COMPLETO')
const missingItems = computed(() => {
  const value = activeResult.value?.faltantes ?? activeResult.value?.cobertura?.faltantes ?? []
  return Array.isArray(value) ? value : []
})
const pendingAssumptions = computed(() => {
  const pending = []
  const add = (key, label, target) => pending.push({ key, label, target })
  const hasValue = (value) => value !== '' && value !== null && value !== undefined && Number.isFinite(Number(value)) && Number(value) >= 0
  const isPositive = (value) => hasValue(value) && Number(value) > 0

  if (!isPositive(parameters.km_mensuales)) add('km', 'Kilómetros operativos previstos por mes', '#km-mensuales')
  if (!isPositive(parameters.fx.ars_por_usd) || !parameters.fx.fecha || !parameters.fx.fuente.trim()) {
    add('fx', 'Cotización del dólar, fecha y fuente de referencia', '#quote-title')
  }
  for (const key of ['tractor', 'semi']) {
    if (!hasValue(parameters[key].valor_usd) || !isPositive(parameters[key].vida_util_anios)) {
      const name = key === 'tractor' ? 'tractor' : 'semi'
      add(key, `Valor de reposición y vida útil del ${name}`, `#${key}-value`)
    }
  }

  const missingFuel = []
  if (!isPositive(parameters.combustible.litros_100km)) missingFuel.push('consumo')
  if (!hasValue(parameters.combustible.precio)) missingFuel.push('precio')
  if (!parameters.combustible.fecha) missingFuel.push('fecha')
  if (missingFuel.length) add('fuel', `${missingFuel.map((item, index) => index === 0 ? item[0].toUpperCase() + item.slice(1) : item).join(', ')} del gasoil`, '#fuel-price')

  const missingTires = []
  if (!hasValue(parameters.neumaticos.costo_juego)) missingTires.push('costo del juego')
  if (!hasValue(parameters.neumaticos.recapados)) missingTires.push('costo de recapados')
  if (!isPositive(parameters.neumaticos.vida_util_km)) missingTires.push('vida útil')
  if (missingTires.length) add('tires', `Neumáticos: ${missingTires.join(', ')}`, '#tire-cost')

  const monthlyMissing = parameters.costos_mensuales
    .filter((item) => !hasValue(item.importe))
    .map((item) => item.nombre || 'Otro costo mensual')
  if (monthlyMissing.length) {
    add('monthly', `Importes mensuales: ${monthlyMissing.join(', ')} (cargá el valor o 0 si no aplica)`, '#monthly-costs')
  }

  const unpricedOtherCosts = parameters.otros_rubros.filter((item) => !hasValue(item.importe))
  if (unpricedOtherCosts.length) {
    add('other', `Importes pendientes: ${unpricedOtherCosts.map((item) => item.nombre || 'Otro rubro').join(', ')}`, '#special-costs')
  }
  const zeroStandardCosts = standardOtherRubros.value.filter((item) => item.importe !== '' && item.importe !== null && item.importe !== undefined && Number(item.importe) === 0)
  if (zeroStandardCosts.length) {
    add('zero-standard', `Confirmá ${zeroStandardCosts.map((item) => item.nombre).join(' y ')}: empiezan en $0 y no se incluyen hasta cargar un importe`, '#special-costs')
  }
  if (!hasValue(parameters.mantenimiento_pct)) add('maintenance', 'Porcentaje de mantenimiento y reparaciones', '#maintenance-pct')
  if (!hasValue(parameters.recargo_pct)) add('surcharge', 'Recargo para sugerir una tarifa', '#surcharge-pct')

  return pending
})
const resultAmount = (amount, currency) => {
  if (amount && typeof amount === 'object') return amount[currency.toLowerCase()] ?? amount[currency] ?? null
  if (amount === null || amount === undefined) return null
  if (currency === 'ARS') return amount
  const quote = Number(activeResult.value?.cotizacion?.ars_por_usd)
  return Number.isFinite(quote) && quote > 0 ? Number(amount) / quote : null
}
const formatted = (value, digits = 2) => value === null || value === undefined || value === '' || !Number.isFinite(Number(value))
  ? '—'
  : new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: digits }).format(Number(value))
const currencyLabel = (value, currency = 'ARS') => value === null || value === undefined
  ? '—'
  : `${currency === 'USD' ? 'US$' : '$'} ${formatted(value)}`
const costArs = computed(() => resultAmount(activeResult.value?.costo_km, 'ARS'))
const costUsd = computed(() => resultAmount(activeResult.value?.costo_km, 'USD'))
const rateArs = computed(() => resultAmount(activeResult.value?.tarifa_km, 'ARS'))
const rateUsd = computed(() => resultAmount(activeResult.value?.tarifa_km, 'USD'))
const routeResult = computed(() => activeResult.value?.viaje ?? null)
const breakdown = computed(() => activeResult.value?.rubros ?? [])
const standardOtherRubros = computed(() => parameters.otros_rubros.filter((item) => standardOtherCodes.has(item.codigo)))
const customOtherRubros = computed(() => parameters.otros_rubros.filter((item) => !standardOtherCodes.has(item.codigo)))
const breakdownStatus = (item) => {
  if (item.estado === 'pendiente_km_mensuales') return 'Datos cargados; faltan los km operativos mensuales para distribuir este costo por km.'
  return item.estado_label || item.motivo || (item.estado === 'sin_cotizacion' ? 'Falta una cotización de referencia válida' : 'Sin configurar')
}
const breakdownExplanation = (item) => {
  if (item.codigo === 'combustible') {
    const liters = numberOrNull(parameters.combustible.litros_100km)
    const price = numberOrNull(parameters.combustible.precio)
    if (liters !== null && price !== null) {
      return `${formatted(liters)} l/100 km × ${currencyLabel(price, parameters.combustible.moneda)}/l ÷ 100`
    }
  }
  if (item.codigo === 'tractor' || item.codigo === 'semi') {
    return 'Depreciación mensual ÷ km operativos mensuales.'
  }
  if (item.codigo === 'neumaticos') {
    return 'Costo del juego y recapados ÷ vida útil total.'
  }
  if (item.unidad === 'por_mes') return 'Importe mensual ÷ km operativos mensuales.'
  return ''
}
const missingLabel = (item) => {
  if (typeof item !== 'string') return item?.mensaje || item?.descripcion || item?.campo || 'Revisá este supuesto'
  return ({
    km_mensuales: 'Completá los kilómetros operativos previstos por mes.',
    tractor: 'Completá valor, residual y vida útil del tractor.',
    semi: 'Completá valor, residual y vida útil del semi.',
    combustible: 'Completá consumo, precio, moneda y fecha del gasoil.',
    neumaticos: 'Completá costo del juego, recapados y vida útil de neumáticos.',
    mantenimiento_pct: 'Indicá un porcentaje de mantenimiento menor al 100 %.',
    recargo_pct: 'Indicá el recargo sobre el costo.',
    cotizacion_usd: 'Completá una cotización ARS/USD válida para convertir los importes en dólares.',
  })[item] ?? `Revisá el rubro ${item}.`
}
const exchangeLabel = computed(() => {
  const quote = activeResult.value?.cotizacion ?? {}
  if (quote.ars_por_usd === null || quote.ars_por_usd === undefined) return 'Cotización pendiente'
  const date = quote.fecha ? ` · ${quote.fecha}` : ''
  const source = quote.fuente || quote.tipo || ''
  return `$ ${formatted(quote.ars_por_usd, 4)} por US$ 1${date}${source ? ` · ${source}` : ''}`
})
const latestVersion = (item) => item.version ?? item.versiones?.at(-1)?.numero ?? 1
const scenarioKey = (item) => String(item.id)
const scenarioDate = (item) => {
  const timestamp = item.guardado_en ?? item.versiones?.find((version) => version.numero === latestVersion(item))?.guardado_en
  return timestamp ? new Date(String(timestamp).replace(' ', 'T')).toLocaleString('es-AR') : 'Fecha no disponible'
}
const equipmentScenarioLabel = (item) => {
  const tractor = item.tractor?.codigo || item.tractor_codigo
  const semi = item.semi?.codigo || item.semi_codigo
  const selected = [tractor, semi].filter(Boolean)
  return selected.length ? selected.join(' + ') : 'Escenario general · sin equipo asociado'
}
const scenarioVersionUrl = (scenarioId, versionNumber) => {
  const configured = urls.value.scenarioVersion || urls.value.version
  if (!configured) return `${requestUrl('scenarios', '/escenarios')}/${encodeURIComponent(scenarioId)}/versiones/${encodeURIComponent(versionNumber)}`
  return String(configured)
    .replaceAll('{id}', encodeURIComponent(scenarioId))
    .replaceAll(':id', encodeURIComponent(scenarioId))
    .replaceAll('{version}', encodeURIComponent(versionNumber))
    .replaceAll(':version', encodeURIComponent(versionNumber))
}
const excelUrl = (record) => {
  const configured = urls.value.excel
  if (!configured) return `${requestUrl('scenarios', '/escenarios')}/${encodeURIComponent(record.id)}/versiones/${encodeURIComponent(record.version)}/excel`
  return String(configured)
    .replaceAll('{id}', encodeURIComponent(record.id))
    .replaceAll(':id', encodeURIComponent(record.id))
    .replaceAll('{version}', encodeURIComponent(record.version))
    .replaceAll(':version', encodeURIComponent(record.version))
}

watch([() => selection.tractor_id, () => selection.semi_id], async () => {
  if (!selection.tractor_id && !selection.semi_id) return
  try { await loadScenarios() } catch (error) { errorMessage.value = error.message }
})

onMounted(initialize)
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6 pb-10">
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <a :href="data.urls?.gerencial || '/dashboard'" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover">
          <ArrowLeftIcon class="size-4" aria-hidden="true" /> Volver a Gerencial
        </a>
        <p class="flex items-center gap-2 text-sm font-semibold text-primary"><ChartBarIcon class="size-5" aria-hidden="true" />Gerencial</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Costo teórico por km</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">Una referencia para evaluar cuánto cuesta operar el conjunto y qué tarifa sugerir. Se calcula con tus supuestos, no con gastos reales ni valores sectoriales automáticos.</p>
      </div>
      <div class="rounded-lg border border-info/30 bg-info-subtle px-4 py-3 text-sm text-info-strong sm:max-w-sm">
        <p class="font-semibold">Referencia de gestión</p>
        <p class="mt-1">No es una tarifa oficial ni incluye impuestos de venta. Revisá los supuestos antes de cotizar.</p>
      </div>
    </header>

    <div v-if="errorMessage" role="alert" class="flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-subtle px-4 py-3 text-sm text-danger-strong">
      <ExclamationTriangleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" /><p>{{ errorMessage }}</p>
    </div>
    <div v-if="notice" role="status" class="rounded-xl border border-success/30 bg-success-subtle px-4 py-3 text-sm font-medium text-success-strong">{{ notice }}</div>

    <div class="flex gap-2 overflow-x-auto border-b border-border" role="tablist" aria-label="Costo teórico por km">
      <button type="button" role="tab" :aria-selected="activeTab === 'calculator'" class="min-h-11 shrink-0 border-b-2 px-4 text-sm font-semibold" :class="activeTab === 'calculator' ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'" @click="activeTab = 'calculator'">Calculadora</button>
      <button type="button" role="tab" :aria-selected="activeTab === 'history'" class="min-h-11 inline-flex shrink-0 items-center gap-2 border-b-2 px-4 text-sm font-semibold" :class="activeTab === 'history' ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'" @click="activeTab = 'history'"><ClockIcon class="size-4" aria-hidden="true" />Escenarios guardados <span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs">{{ scenarios.length }}</span></button>
    </div>

    <div v-if="loading" role="status" class="rounded-xl border border-border bg-surface-raised px-5 py-8 text-center text-sm text-ink-muted">Cargando equipos e historial…</div>

    <template v-else-if="activeTab === 'calculator'">
      <section v-if="pendingAssumptions.length" data-testid="assumptions-warning" role="status" aria-live="polite" aria-labelledby="assumptions-warning-title" class="rounded-xl border-2 border-warning/60 bg-warning-subtle p-5 shadow-sm sm:p-6">
        <div class="flex items-start gap-3">
          <ExclamationTriangleIcon class="mt-0.5 size-7 shrink-0 text-warning-strong sm:size-8" aria-hidden="true" />
          <div>
            <h2 id="assumptions-warning-title" class="text-lg font-extrabold text-warning-strong sm:text-xl">Faltan supuestos para completar la referencia</h2>
            <p class="mt-1 text-sm font-semibold text-warning-strong">Hay {{ pendingAssumptions.length }} datos pendientes. Completalos antes de usar el costo/km como orientación para cotizar.</p>
          </div>
        </div>
        <ul class="mt-4 grid gap-2 sm:grid-cols-2">
          <li v-for="item in pendingAssumptions" :key="item.key" class="rounded-lg border border-warning/30 bg-surface px-3 py-2.5">
            <a :href="item.target" class="flex min-h-7 items-center justify-between gap-2 text-sm font-bold text-warning-strong underline decoration-2 underline-offset-2 hover:text-ink">
              <span>{{ item.label }}</span><span class="shrink-0 text-xs">Completar ↓</span>
            </a>
          </li>
        </ul>
        <p class="mt-4 border-t border-warning/30 pt-3 text-sm text-warning-strong">El botón «Cargar supuestos» completa los valores documentados. Los importes propios de la empresa y los precios actuales se deben cargar o confirmar en $0 si no aplican.</p>
      </section>
      <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(20rem,0.85fr)]">
        <div class="space-y-6">
          <section class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="equipment-title">
            <div class="mb-4 flex items-start gap-3"><span class="rounded-lg bg-primary-subtle p-2 text-primary"><TruckIcon class="size-5" aria-hidden="true" /></span><div><h2 id="equipment-title" class="font-bold text-ink">Conjunto y utilización</h2><p class="mt-1 text-sm text-ink-muted">El escenario general no requiere asociar equipos. Si querés identificar el conjunto, seleccioná tractor y semi. Los kilómetros mensuales incluyen recorridos cargados y vacíos.</p></div></div>
            <div class="mb-4 rounded-lg border border-info/30 bg-info-subtle p-3 text-sm text-info-strong">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                  <p class="font-semibold">Supuestos técnicos documentados</p>
                  <p class="mt-1 text-xs leading-5">37,04 l/100 km de consumo; neumáticos con 250.000 km de vida total, incluyendo un recapado. Si ingresaste el costo del juego, el costo del recapado se estima al 30 %.</p>
                  <p class="mt-1 text-xs leading-5">Referencia histórica para transporte de cereales; ajustá estos supuestos a tu flota.</p>
                  <a class="mt-1 inline-flex text-xs font-semibold underline underline-offset-2 hover:text-primary" href="https://www.argentina.gob.ar/normativa/nacional/disposici%C3%B3n-273-2011-182568/texto" target="_blank" rel="noopener noreferrer">Fuente: Disposición 273/2011 · modelo elaborado con FADEEAC</a>
                </div>
                <button v-if="canEdit" type="button" aria-label="Cargar supuestos documentados" class="ui-interactive inline-flex min-h-10 shrink-0 items-center justify-center rounded-lg border border-info/40 bg-surface px-3 py-2 text-xs font-semibold text-info-strong hover:bg-info-subtle" @click="loadDocumentedAssumptions">Cargar supuestos</button>
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="grid gap-1.5 text-sm font-semibold text-ink">Tractor (opcional)<select v-model="selection.tractor_id" :disabled="!canEdit" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70"><option value="">Sin asociar</option><option v-for="item in assets.tractores" :key="item.id" :value="String(item.id)">{{ item.codigo }} · {{ item.descripcion }}<template v-if="item.sucursal"> ({{ item.sucursal }})</template></option></select></label>
              <label class="grid gap-1.5 text-sm font-semibold text-ink">Semi (opcional)<select v-model="selection.semi_id" :disabled="!canEdit" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70"><option value="">Sin asociar</option><option v-for="item in assets.semis" :key="item.id" :value="String(item.id)">{{ item.codigo }} · {{ item.descripcion }}<template v-if="item.sucursal"> ({{ item.sucursal }})</template></option></select></label>
              <label class="grid gap-1.5 text-sm font-semibold text-ink sm:col-span-2">Kilómetros operativos previstos por mes<input id="km-mensuales" v-model="parameters.km_mensuales" type="number" min="1" step="1" inputmode="decimal" :disabled="!canEdit" placeholder="Ej.: 10.000" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70" /><span class="text-xs font-normal text-ink-muted">Se usan para distribuir depreciación y costos mensuales entre los km recorridos.</span></label>
            </div>
            <div class="mt-4 rounded-lg bg-info-subtle p-3 text-sm text-info-strong">Asociar un tractor o un semi es opcional; podés seleccionar uno solo, ambos o ninguno.</div>
          </section>

          <section class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="quote-title">
            <div class="mb-4"><h2 id="quote-title" class="font-bold text-ink">Cotización de referencia</h2><p class="mt-1 text-sm text-ink-muted">Se aplica a los importes en USD de este escenario y queda guardada con él.</p></div>
            <div class="grid gap-4 sm:grid-cols-3">
              <label class="grid gap-1.5 text-sm font-semibold text-ink">ARS por 1 USD<input id="fx-rate" v-model="parameters.fx.ars_por_usd" type="number" min="0.01" step="0.01" inputmode="decimal" :disabled="!canEdit" placeholder="Ej.: 1.250" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70" @input="markExchangeRateManual" /></label>
              <label class="grid gap-1.5 text-sm font-semibold text-ink">Fecha de referencia<input v-model="parameters.fx.fecha" type="date" :disabled="!canEdit" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70" @change="markExchangeRateManual" /></label>
              <label class="grid gap-1.5 text-sm font-semibold text-ink">Fuente o tipo<input v-model="parameters.fx.fuente" type="text" maxlength="120" :disabled="!canEdit" placeholder="Ej.: referencia propia" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70" @input="markExchangeRateManual" /></label>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3">
              <button v-if="canEdit" type="button" :disabled="exchangeLoading" class="inline-flex min-h-9 items-center gap-2 rounded-lg border border-info/40 px-3 py-1.5 text-xs font-semibold text-info-strong hover:bg-info-subtle disabled:opacity-50" @click="loadBcraExchangeRate(true)"><ArrowPathIcon class="size-4" :class="exchangeLoading ? 'animate-spin' : ''" aria-hidden="true" />{{ exchangeLoading ? 'Consultando BCRA…' : 'Actualizar desde BCRA' }}</button>
              <p class="text-xs text-ink-muted">Se usa el tipo de cambio de referencia Com. A 3500, con la última fecha publicada por el BCRA.</p>
            </div>
            <p v-if="exchangeError" role="status" class="mt-2 rounded-lg bg-warning-subtle p-3 text-xs text-warning-strong">{{ exchangeError }} La cotización queda editable para que puedas cargarla manualmente.</p>
          </section>

          <section class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="assets-title">
            <div class="mb-4"><h2 id="assets-title" class="font-bold text-ink">Valor y vida útil de los activos</h2><p class="mt-1 text-sm text-ink-muted">En USD, por separado. Se estima depreciación lineal entre valor de reposición y residual. Si dejás residual vacío, se estima en 30% del valor de reposición.</p></div>
            <div class="grid gap-4 lg:grid-cols-2">
              <fieldset class="min-w-0 rounded-lg border border-border-subtle p-4"><legend class="px-1 text-sm font-bold text-ink">Tractor</legend><div class="grid gap-3 sm:grid-cols-3"><label class="grid gap-1 text-xs font-semibold text-ink-muted">Reposición (US$)<input id="tractor-value" v-model="parameters.tractor.valor_usd" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 w-full rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70" /></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Residual (US$)<input v-model="parameters.tractor.valor_residual_usd" @input="markResidualManual('tractor')" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 w-full rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70" /><span v-if="numberOrNull(parameters.tractor.valor_residual_usd) === null && effectiveResidual('', parameters.tractor.valor_usd) !== null" class="text-[10px] font-normal">Estimado al 30%: {{ currencyLabel(effectiveResidual('', parameters.tractor.valor_usd), 'USD') }}</span></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Vida útil (años)<input v-model="parameters.tractor.vida_util_anios" type="number" min="0.1" step="0.1" :disabled="!canEdit" class="min-h-10 w-full rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70" /></label></div></fieldset>
              <fieldset class="min-w-0 rounded-lg border border-border-subtle p-4"><legend class="px-1 text-sm font-bold text-ink">Semi</legend><div class="grid gap-3 sm:grid-cols-3"><label class="grid gap-1 text-xs font-semibold text-ink-muted">Reposición (US$)<input id="semi-value" v-model="parameters.semi.valor_usd" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 w-full rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70" /></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Residual (US$)<input v-model="parameters.semi.valor_residual_usd" @input="markResidualManual('semi')" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 w-full rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70" /><span v-if="numberOrNull(parameters.semi.valor_residual_usd) === null && effectiveResidual('', parameters.semi.valor_usd) !== null" class="text-[10px] font-normal">Estimado al 30%: {{ currencyLabel(effectiveResidual('', parameters.semi.valor_usd), 'USD') }}</span></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Vida útil (años)<input v-model="parameters.semi.vida_util_anios" type="number" min="0.1" step="0.1" :disabled="!canEdit" class="min-h-10 w-full rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70" /></label></div></fieldset>
            </div>
          </section>

          <section class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="variable-costs-title">
            <div class="mb-4"><h2 id="variable-costs-title" class="font-bold text-ink">Costos por uso</h2><p class="mt-1 text-sm text-ink-muted">Ingresá supuestos representativos de tu operación. Los importes en USD usan la cotización indicada arriba.</p></div>
            <div class="grid gap-5 lg:grid-cols-2">
              <fieldset class="grid content-start gap-3"><legend class="mb-2 text-sm font-bold text-ink">Gasoil</legend><label class="grid gap-1 text-xs font-semibold text-ink-muted">Consumo (litros cada 100 km)<input v-model="parameters.combustible.litros_100km" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label><div class="grid grid-cols-[minmax(0,1fr)_6rem] gap-2"><label class="grid gap-1 text-xs font-semibold text-ink-muted">Precio por litro<input id="fuel-price" v-model="parameters.combustible.precio" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Moneda<select v-model="parameters.combustible.moneda" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70"><option>ARS</option><option>USD</option></select></label></div><label class="grid gap-1 text-xs font-semibold text-ink-muted">Fecha del precio<input v-model="parameters.combustible.fecha" type="date" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label></fieldset>
              <fieldset class="grid content-start gap-3"><legend class="mb-2 text-sm font-bold text-ink">Neumáticos y recapados</legend><div class="grid grid-cols-2 gap-2"><label class="grid gap-1 text-xs font-semibold text-ink-muted">Juego de neumáticos<input id="tire-cost" v-model="parameters.neumaticos.costo_juego" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Recapados del ciclo<input v-model="parameters.neumaticos.recapados" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label></div><div class="grid grid-cols-2 gap-2"><label class="grid gap-1 text-xs font-semibold text-ink-muted">Vida útil total (km)<input v-model="parameters.neumaticos.vida_util_km" type="number" min="1" step="1" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Moneda<select v-model="parameters.neumaticos.moneda" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70"><option>ARS</option><option>USD</option></select></label></div></fieldset>
            </div>
          </section>

          <section id="monthly-costs" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="monthly-title">
            <div class="mb-4"><h2 id="monthly-title" class="font-bold text-ink">Costos mensuales y mantenimiento</h2><p class="mt-1 text-sm text-ink-muted">Cada costo mensual se distribuye sobre los km previstos. Mantenimiento y reparaciones se estiman juntos como porcentaje del costo total.</p></div>
            <div class="space-y-2">
              <div v-for="(item, index) in parameters.costos_mensuales" :key="`monthly-${index}`" class="grid grid-cols-[minmax(6rem,1fr)_minmax(6rem,1fr)_5rem] gap-2 sm:grid-cols-[minmax(8rem,1fr)_minmax(8rem,1fr)_6rem]">
                <label class="sr-only" :for="`monthly-name-${index}`">Concepto mensual</label><input :id="`monthly-name-${index}`" v-model="item.nombre" :disabled="!canEdit" placeholder="Concepto" class="min-h-10 min-w-0 rounded-lg border border-border-strong bg-surface px-2 text-sm disabled:opacity-70" />
                <label class="sr-only" :for="`monthly-value-${index}`">Importe mensual</label><input :id="`monthly-value-${index}`" v-model="item.importe" type="number" min="0" step="0.01" :disabled="!canEdit" placeholder="Importe / mes" class="min-h-10 min-w-0 rounded-lg border border-border-strong bg-surface px-2 text-sm disabled:opacity-70" />
                <label class="sr-only" :for="`monthly-currency-${index}`">Moneda del costo mensual</label><select :id="`monthly-currency-${index}`" v-model="item.moneda" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-1 text-xs disabled:opacity-70"><option>ARS</option><option>USD</option></select>
              </div>
              <button v-if="canEdit" type="button" class="inline-flex min-h-10 items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover" @click="addMonthlyCost"><PlusIcon class="size-4" aria-hidden="true" />Agregar costo mensual</button>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
              <label class="grid gap-1.5 text-sm font-semibold text-ink">Mantenimiento y reparaciones (% del costo total)<input id="maintenance-pct" v-model="parameters.mantenimiento_pct" type="number" min="0" max="99.99" step="0.01" :disabled="!canEdit" placeholder="Ej.: 10" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70" /><span class="text-xs font-normal text-ink-muted">Debe ser menor a 100 %. No cargues reparaciones de nuevo en otro rubro si usás este porcentaje.</span></label>
              <label class="grid gap-1.5 text-sm font-semibold text-ink">Recargo sobre el costo (%)<input id="surcharge-pct" v-model="parameters.recargo_pct" type="number" min="0" step="0.01" :disabled="!canEdit" placeholder="Ej.: 20" class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 font-normal disabled:opacity-70" /><span class="text-xs font-normal text-ink-muted">Es un recargo para orientar la tarifa; no representa margen neto ni rentabilidad garantizada.</span></label>
            </div>
            <div id="special-costs" class="mt-5 rounded-lg border border-border-subtle p-4">
              <h3 class="text-sm font-bold text-ink">Lubricantes y costo financiero</h3>
              <p class="mt-1 text-xs leading-5 text-ink-muted">Estimación inicial editable: $0 excluye el rubro hasta que cargues un importe propio. El costo financiero se expresa por mes y se distribuye entre los kilómetros previstos.</p>
              <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label v-for="item in standardOtherRubros" :key="item.codigo" class="grid gap-1 text-xs font-semibold text-ink-muted">{{ item.nombre }} (ARS por {{ item.unidad === 'por_km' ? 'km' : 'mes' }}):<input v-model="item.importe" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label>
              </div>
            </div>
            <details class="mt-5 rounded-lg border border-border-subtle">
              <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-ink">Otros rubros de costo</summary>
              <div class="space-y-2 border-t border-border-subtle p-4">
                <p class="text-xs leading-5 text-ink-muted">Indicá la unidad para no mezclar importes mensuales con costos por km.</p>
                <div v-for="(item, index) in customOtherRubros" :key="`other-${index}`" class="grid grid-cols-[minmax(6rem,1fr)_minmax(5rem,0.7fr)_6rem_5rem] gap-2">
                  <label class="sr-only" :for="`other-name-${index}`">Concepto</label><input :id="`other-name-${index}`" v-model="item.nombre" :disabled="!canEdit" placeholder="Concepto" class="min-h-10 min-w-0 rounded-lg border border-border-strong bg-surface px-2 text-sm disabled:opacity-70" />
                  <label class="sr-only" :for="`other-value-${index}`">Importe</label><input :id="`other-value-${index}`" v-model="item.importe" type="number" min="0" step="0.01" :disabled="!canEdit" placeholder="Importe" class="min-h-10 min-w-0 rounded-lg border border-border-strong bg-surface px-2 text-sm disabled:opacity-70" />
                  <label class="sr-only" :for="`other-unit-${index}`">Unidad del rubro</label><select :id="`other-unit-${index}`" v-model="item.unidad" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-1 text-xs disabled:opacity-70"><option value="por_km">por km</option><option value="por_mes">por mes</option></select>
                  <label class="sr-only" :for="`other-currency-${index}`">Moneda</label><select :id="`other-currency-${index}`" v-model="item.moneda" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-1 text-xs disabled:opacity-70"><option>ARS</option><option>USD</option></select>
                </div>
                <button v-if="canEdit" type="button" class="inline-flex min-h-10 items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover" @click="addOtherItem"><PlusIcon class="size-4" aria-hidden="true" />Agregar otro rubro</button>
              </div>
            </details>
          </section>

          <section class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="trip-title">
            <div class="mb-4"><h2 id="trip-title" class="font-bold text-ink">Simulación de un viaje</h2><p class="mt-1 text-sm text-ink-muted">El costo del tramo no facturado y los peajes se distribuyen entre los kilómetros que sí se cobran.</p></div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <label class="grid gap-1 text-xs font-semibold text-ink-muted">Km facturables<input v-model="trip.km_facturables" type="number" min="0" step="1" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label>
              <label class="grid gap-1 text-xs font-semibold text-ink-muted">Km no facturados<input v-model="trip.km_no_facturados" type="number" min="0" step="1" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label>
              <label class="grid gap-1 text-xs font-semibold text-ink-muted">Peajes<input v-model="trip.peajes" type="number" min="0" step="0.01" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink disabled:opacity-70" /></label>
              <label class="grid gap-1 text-xs font-semibold text-ink-muted">Moneda<select v-model="trip.moneda" :disabled="!canEdit" class="min-h-10 rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink disabled:opacity-70"><option>ARS</option><option>USD</option></select></label>
            </div>
          </section>

          <div v-if="!canEdit" class="flex items-start gap-3 rounded-xl border border-info/30 bg-info-subtle p-4 text-sm text-info-strong"><InformationCircleIcon class="size-5 shrink-0" aria-hidden="true" /><p>Tenés acceso de consulta. Podés revisar resultados e historial, pero no modificar supuestos ni guardar escenarios.</p></div>
          <div v-else class="flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div><p class="font-semibold text-ink">¿Querés ver cómo quedan estos supuestos?</p><p class="mt-1 text-sm text-ink-muted">Al calcular, los cambios todavía no se guardan en el historial.</p></div>
            <button type="button" :disabled="calculating" class="ui-interactive inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50" @click="calculate"><ArrowPathIcon class="size-5" :class="calculating ? 'animate-spin' : ''" aria-hidden="true" />{{ calculating ? 'Calculando…' : 'Calcular referencia' }}</button>
          </div>
        </div>

        <aside class="space-y-5 xl:sticky xl:top-5">
          <section v-if="activeResult" class="overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="result-title" aria-live="polite">
            <div class="border-b border-border-subtle p-5 sm:p-6">
              <div class="flex items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-primary">Resultado teórico</p><h2 id="result-title" class="mt-1 text-lg font-bold text-ink">Costo por km recorrido</h2></div><span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="coverageComplete ? 'bg-success-subtle text-success-strong' : 'bg-warning-subtle text-warning-strong'">{{ coverageComplete ? 'Cobertura completa' : 'Cobertura parcial' }}</span></div>
              <p class="mt-3 text-3xl font-bold tracking-tight text-ink tabular-nums">{{ currencyLabel(costArs, 'ARS') }}<span class="text-base font-semibold text-ink-muted"> / km</span></p>
              <p class="mt-1 text-sm text-ink-muted">{{ currencyLabel(costUsd, 'USD') }} / km</p>
              <p class="mt-3 rounded-lg bg-surface-subtle px-3 py-2 text-xs text-ink-muted">Cotización usada: {{ exchangeLabel }}</p>
            </div>
            <div class="p-5 sm:p-6">
              <div class="rounded-lg border border-primary/20 bg-primary-subtle p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-primary">Tarifa de referencia con recargo</p>
                <p class="mt-2 text-2xl font-bold text-ink tabular-nums">{{ currencyLabel(rateArs, 'ARS') }}<span class="text-sm font-semibold text-ink-muted"> / km</span></p>
                <p class="mt-1 text-sm text-ink-muted">{{ currencyLabel(rateUsd, 'USD') }} / km</p>
              </div>
              <div v-if="missingItems.length" class="mt-4 rounded-lg border border-warning/30 bg-warning-subtle p-3 text-sm text-warning-strong">
                <p class="font-bold">Faltan datos para completar la referencia</p>
                <ul class="mt-2 list-disc space-y-1 pl-5"><li v-for="(item, index) in missingItems" :key="index">{{ missingLabel(item) }}</li></ul>
              </div>
              <p v-else-if="!coverageComplete" class="mt-4 rounded-lg bg-warning-subtle p-3 text-sm text-warning-strong">El cálculo no tiene cobertura completa. Revisá la configuración antes de usar esta cifra para cotizar.</p>
              <div class="mt-5">
                <h3 class="text-sm font-bold text-ink">Desglose por rubro</h3>
                <div v-if="breakdown.length" class="mt-2 divide-y divide-border-subtle">
                  <div v-for="(item, index) in breakdown" :key="item.codigo || item.nombre || index" class="flex items-start justify-between gap-3 py-2.5 text-sm"><div class="min-w-0"><p class="font-medium text-ink">{{ item.nombre || item.concepto || item.codigo }}</p><p v-if="item.estado && item.estado !== 'configurado'" class="mt-0.5 text-xs text-warning-strong">{{ breakdownStatus(item) }}</p><p v-if="breakdownExplanation(item)" class="mt-0.5 text-xs text-ink-muted">{{ breakdownExplanation(item) }}</p></div><div class="shrink-0 text-right tabular-nums"><p class="font-semibold text-ink">{{ item.ars_km == null ? 'Sin dato' : `${currencyLabel(item.ars_km, 'ARS')} / km` }}</p><p v-if="item.usd_km != null" class="text-xs text-ink-muted">{{ currencyLabel(item.usd_km, 'USD') }} / km</p></div></div>
                </div>
                <p v-else class="mt-2 text-sm text-ink-muted">El servidor no devolvió el detalle por rubro.</p>
              </div>
              <div v-if="routeResult" class="mt-5 rounded-lg border border-border-subtle p-4">
                <h3 class="text-sm font-bold text-ink">Tarifa para el viaje</h3>
                <p class="mt-1 text-xs text-ink-muted">{{ formatted(routeResult.km_totales) }} km de recorrido · {{ formatted(routeResult.km_facturables ?? trip.km_facturables) }} km facturables</p>
                <p v-if="routeResult.estado !== 'calculado' || routeResult.cobertura === 'parcial'" class="mt-2 rounded-md bg-warning-subtle px-2.5 py-2 text-xs font-semibold text-warning-strong">Simulación parcial: revisá datos faltantes antes de usar esta tarifa.</p>
                <p class="mt-3 text-xl font-bold text-ink tabular-nums">{{ currencyLabel(routeResult.tarifa_ars_km, 'ARS') }} <span class="text-sm font-semibold text-ink-muted">/ km facturable</span></p>
                <p class="mt-1 text-sm text-ink-muted">{{ currencyLabel(routeResult.tarifa_usd_km, 'USD') }} / km facturable</p>
                <p v-if="routeResult.costo_ars != null" class="mt-2 text-xs text-ink-muted">Costo estimado del recorrido: {{ currencyLabel(routeResult.costo_ars, 'ARS') }} · peajes {{ currencyLabel(routeResult.peajes_ars, 'ARS') }}</p>
              </div>
              <div v-if="canEdit" class="mt-5 border-t border-border-subtle pt-4">
                <label for="scenario-name" class="block text-sm font-semibold text-ink">{{ editingScenarioId ? 'Guardar nueva versión' : 'Guardar escenario para consultarlo después' }}</label>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row"><input id="scenario-name" v-model="scenarioName" type="text" maxlength="120" placeholder="Ej.: Tractor 12 · ruta al norte" class="min-h-11 min-w-0 flex-1 rounded-lg border border-border-strong bg-surface px-3 text-sm" /><button type="button" :disabled="saving" class="ui-interactive inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary-subtle disabled:opacity-50" @click="saveScenario">{{ saving ? 'Guardando…' : (editingScenarioId ? 'Guardar versión' : 'Guardar') }}</button></div>
                <p class="mt-2 text-xs text-ink-muted">Guardar crea una versión nueva y conserva las anteriores.</p>
                <button v-if="editingScenarioId" type="button" class="mt-2 text-xs font-semibold text-primary underline-offset-2 hover:underline" @click="editingScenarioId = null; selectedScenario = null; scenarioName = ''">Guardar como escenario nuevo</button>
              </div>
              <div v-if="selectedScenario" class="mt-5 flex flex-col gap-2 border-t border-border-subtle pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-ink-muted">Viendo la versión {{ selectedScenario.version }} guardada el {{ scenarioDate(selectedScenario) }}.</p><a :href="excelUrl(selectedScenario)" class="ui-interactive inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-lg border border-border-strong px-3 py-2 text-sm font-semibold text-ink hover:bg-surface-muted"><ArrowDownTrayIcon class="size-4" aria-hidden="true" />Descargar Excel</a></div>
            </div>
          </section>
          <section v-else class="rounded-xl border border-dashed border-border-strong bg-surface-raised p-6 text-center">
            <ChartBarIcon class="mx-auto size-9 text-ink-subtle" aria-hidden="true" /><h2 class="mt-3 font-bold text-ink">Tu resultado va a aparecer acá</h2><p class="mt-1 text-sm leading-6 text-ink-muted">Completá los supuestos y calculá para ver costo/km, tarifa sugerida y faltantes de cobertura.</p>
          </section>
          <section class="rounded-xl border border-info/30 bg-info-subtle p-4 text-sm text-info-strong"><div class="flex gap-2"><InformationCircleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" /><p>El costo por km aplica a kilómetros recorridos, incluso los vacíos. La tarifa del viaje reparte el costo de todos los tramos y peajes entre los km facturables.</p></div></section>
        </aside>
      </div>
    </template>

    <section v-else class="space-y-5" aria-labelledby="history-title">
      <div class="flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6">
        <div><h2 id="history-title" class="text-lg font-bold text-ink">Escenarios guardados</h2><p class="mt-1 text-sm text-ink-muted">Cada versión conserva sus supuestos y cotización originales.</p></div>
        <div class="grid grid-cols-2 gap-2 sm:flex sm:items-end"><label class="grid gap-1 text-xs font-semibold text-ink-muted">Desde<input v-model="fromDate" type="date" class="min-h-10 rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink" /></label><label class="grid gap-1 text-xs font-semibold text-ink-muted">Hasta<input v-model="toDate" type="date" class="min-h-10 rounded-lg border border-border-strong bg-surface px-2 text-sm font-normal text-ink" /></label><button type="button" class="col-span-2 min-h-10 rounded-lg border border-border-strong px-3 text-sm font-semibold text-ink hover:bg-surface-muted sm:col-span-1" @click="loadScenarios">Filtrar</button></div>
      </div>
      <div v-if="scenarios.length" class="grid gap-3 lg:grid-cols-2">
        <article v-for="item in scenarios" :key="scenarioKey(item)" class="rounded-xl border border-border bg-surface-raised p-4 sm:p-5">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0"><h3 class="truncate font-bold text-ink">{{ item.nombre || 'Escenario sin nombre' }}</h3><p class="mt-1 text-sm text-ink-muted">{{ equipmentScenarioLabel(item) }}</p><p class="mt-1 text-xs text-ink-subtle">Versión {{ item.version ?? 1 }} · {{ scenarioDate(item) }}</p></div>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="item.versiones?.find((version) => version.numero === latestVersion(item))?.cobertura === 'completa' ? 'bg-success-subtle text-success-strong' : 'bg-warning-subtle text-warning-strong'">{{ item.versiones?.find((version) => version.numero === latestVersion(item))?.cobertura === 'completa' ? 'Cobertura completa' : 'Cobertura parcial' }}</span>
          </div>
          <div class="mt-4 flex flex-col gap-3 border-t border-border-subtle pt-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-ink-muted">Costo/km: <strong class="text-ink">{{ currencyLabel(item.versiones?.find((version) => version.numero === latestVersion(item))?.costo_ars_km, 'ARS') }}</strong></p><button type="button" class="min-h-10 rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary hover:bg-primary-subtle" @click="selectScenario(item, latestVersion(item))">Usar como base</button></div>
          <div v-if="item.versiones?.length > 1" class="mt-3"><label :for="`version-${item.id}`" class="sr-only">Versión guardada</label><select :id="`version-${item.id}`" class="min-h-10 w-full rounded-lg border border-border-strong bg-surface px-3 text-sm text-ink sm:w-auto" :value="latestVersion(item)" @change="selectScenario(item, Number($event.target.value))"><option v-for="version in item.versiones" :key="version.numero" :value="version.numero">Versión {{ version.numero }} · {{ scenarioDate({ guardado_en: version.guardado_en }) }}</option></select></div>
        </article>
      </div>
      <div v-else class="rounded-xl border border-dashed border-border-strong bg-surface-raised px-5 py-12 text-center"><ClockIcon class="mx-auto size-9 text-ink-subtle" aria-hidden="true" /><p class="mt-3 font-semibold text-ink">No hay escenarios con estos filtros</p><p class="mt-1 text-sm text-ink-muted">Calculá y guardá un escenario para encontrarlo acá más adelante.</p><button type="button" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary hover:bg-primary-subtle" @click="activeTab = 'calculator'"><PlusIcon class="size-4" aria-hidden="true" />Ir a la calculadora</button></div>
    </section>
  </div>
</template>
