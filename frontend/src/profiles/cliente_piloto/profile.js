import base from '../base/profile.js'
import ClientDashboard from './pages/ClientDashboard.vue'
import ClientShell from './shell/ClientShell.vue'
import './styles.css'

const dashboardComponents = Object.freeze({
  ...base.dashboardComponents,
  operational: ClientDashboard,
})

export default Object.freeze({
  key: 'cliente_piloto',
  shellComponent: ClientShell,
  resolvePage: base.resolvePage,
  dashboardComponents,
  styles: Object.freeze([]),
})
