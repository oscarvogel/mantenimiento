import base from '../base/profile.js'

const pageOverrides = Object.freeze({})
const dashboardComponents = Object.freeze({ ...base.dashboardComponents })

export default Object.freeze({
  key: 'vogel',
  shellComponent: base.shellComponent,
  resolvePage(pageKey, defaults = {}) {
    return pageOverrides[pageKey] ?? base.resolvePage(pageKey, defaults)
  },
  dashboardComponents,
  styles: Object.freeze([]),
})
