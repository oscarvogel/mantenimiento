import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FleetRankingChart from './FleetRankingChart.vue'
import FleetCutsTimeline from './FleetCutsTimeline.vue'
import ServiceKmChart from './ServiceKmChart.vue'
import ConciliationShareBar from './ConciliationShareBar.vue'

const fecha = (value) => String(value)
const km = (value) => `${value} km`

describe('FleetRankingChart', () => {
  it('orders from highest to lowest and lists units without data apart instead of drawing a zero', () => {
    const wrapper = mount(FleetRankingChart, {
      props: {
        id: 'km', title: 'Km recorridos', formatter: km,
        items: [
          { key: 1, label: 'AF117ZK', value: 63 },
          { key: 2, label: 'AD738EA', value: 6969 },
          { key: 3, label: 'RHB1C77', value: null },
          { key: 4, label: 'AB499OK', value: 5622 },
        ],
      },
    })

    expect(wrapper.findAll('li').map((li) => li.text())).toEqual(['AD738EA6969 km', 'AB499OK5622 km', 'AF117ZK63 km'])
    expect(wrapper.findAll('li')[0].find('[aria-hidden] div').attributes('style')).toContain('width: 100%')
    expect(wrapper.text()).toContain('Sin datos: RHB1C77.')
  })

  it('says so when no unit has data', () => {
    const wrapper = mount(FleetRankingChart, { props: { id: 'x', title: 'Excesos', items: [], emptyText: 'Ningún camión superó el límite en el período.' } })

    expect(wrapper.text()).toContain('Ningún camión superó el límite en el período.')
  })
})

describe('FleetCutsTimeline', () => {
  it('places each cut inside the period and an open cut reaches the end', () => {
    const wrapper = mount(FleetCutsTimeline, {
      props: {
        desde: '2026-11-01 00:00', hasta: '2026-11-11 00:00', formatDate: fecha,
        cortes: [
          { integracion: 'Obersat', desde: '2026-11-03 00:00', hasta: '2026-11-04 00:00', horas: 24, unidadesAfectadas: 14, unidadesTotales: 14, sigueAbierto: false },
          { integracion: 'Obersat', desde: '2026-11-08 00:00', hasta: null, horas: 72, unidadesAfectadas: 14, unidadesTotales: 14, sigueAbierto: true },
        ],
      },
    })
    const bandas = wrapper.findAll('button')

    expect(bandas).toHaveLength(2)
    expect(bandas[0].attributes('style')).toContain('left: 20%')
    expect(bandas[0].attributes('style')).toContain('width: 10%')
    expect(bandas[1].attributes('style')).toContain('left: 70%')
    expect(bandas[1].attributes('style')).toContain('width: 30%')
    expect(bandas[1].attributes('aria-label')).toContain('sigue abierto')
    expect(wrapper.text()).toContain('Corte del proveedor')
  })

  it('shows a single clean track when there are no cuts', () => {
    const wrapper = mount(FleetCutsTimeline, { props: { desde: '2026-11-01 00:00', hasta: '2026-11-11 00:00', cortes: [], formatDate: fecha } })

    expect(wrapper.find('[aria-label="Sin cortes del proveedor en el período"]').exists()).toBe(true)
  })
})

describe('ServiceKmChart', () => {
  const plan = (planId, patente, estado, kmRestantes, estimado = null) => ({
    planId, patente, servicio: 'Service motor', real: { estado, kmRestantes }, estimado,
  })

  it('puts the most urgent first, overdue to the left of zero and clips a corrupt odometer', () => {
    const wrapper = mount(ServiceKmChart, {
      props: {
        formatKm: km,
        planes: [
          plan(1, 'AB499OK', 'AL_DIA', 7119, { estado: 'PROXIMO', kmRestantes: 2731 }),
          plan(2, 'ITV9J84', 'VENCIDO', -10333744),
          plan(3, 'AF117ZK', 'VENCIDO', -5906),
          plan(4, 'GZD587', 'SIN_DATOS', null),
        ],
      },
    })
    const filas = wrapper.findAll('li[title]')

    expect(filas.map((f) => f.text().split(' · ')[0])).toEqual(['ITV9J84', 'AF117ZK', 'AB499OK'])
    expect(filas[0].text()).toContain('Pasado por 10333744 km')
    expect(filas[0].text()).toContain('Fuera de escala')
    expect(filas[2].text()).toContain('Faltan 2731 km · Próximo · GPS')
    // La escala sale del mayor valor normal (5.906 km), no del odómetro corrupto.
    expect(filas[1].find('span[style]').attributes('style')).toContain('width: 50%')
  })
})

describe('ConciliationShareBar', () => {
  it('stacks the trips by result and writes each count in the legend', () => {
    const wrapper = mount(ConciliationShareBar, {
      props: { porEstado: { CONCILIADO: 1, DECLARADO_SUPERA: 0, DECLARADO_MENOR: 0, SIN_DATOS_SUFICIENTES: 29, EN_CURSO: 0 } },
    })

    expect(wrapper.find('[role="img"]').attributes('aria-label')).toBe('Conciliado: 1, Sin datos suficientes: 29')
    expect(wrapper.text()).toContain('Sin datos suficientes: 29')
    expect(wrapper.text()).not.toContain('Declaró de más')
  })

  it('says so when there are no trips', () => {
    expect(mount(ConciliationShareBar, { props: { porEstado: {} } }).text()).toContain('No hay viajes en el período.')
  })
})
