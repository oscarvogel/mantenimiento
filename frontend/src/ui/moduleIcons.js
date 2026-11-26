// Module icons shared by the portal and the sidebar. Heroicons has no fuel pump
// or tire, so these two are drawn in the same outline style (24px, 1.5 stroke).
import { h } from 'vue'
import {
  BuildingOffice2Icon,
  Cog6ToothIcon,
  DocumentChartBarIcon,
  DocumentTextIcon,
  PresentationChartLineIcon,
  SparklesIcon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'

const outline = (name, children) => {
  const icon = (props, { attrs }) => h('svg', {
    xmlns: 'http://www.w3.org/2000/svg',
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    'stroke-width': 1.5,
    'stroke-linecap': 'round',
    'stroke-linejoin': 'round',
    ...attrs,
  }, children.map(([tag, shape]) => h(tag, shape)))
  icon.displayName = name
  return icon
}

export const FuelPumpIcon = outline('FuelPumpIcon', [
  ['path', { d: 'M3.75 21h10.5M4.5 21V4.5A1.5 1.5 0 0 1 6 3h6a1.5 1.5 0 0 1 1.5 1.5V21' }],
  ['path', { d: 'M4.5 9.75h9' }],
  ['path', { d: 'M13.5 12.75h1.5a1.5 1.5 0 0 1 1.5 1.5v2.25a1.5 1.5 0 0 0 3 0V9.31a1.5 1.5 0 0 0-.44-1.06L16.5 5.69' }],
])

export const TireIcon = outline('TireIcon', [
  ['circle', { cx: 12, cy: 12, r: 9 }],
  ['circle', { cx: 12, cy: 12, r: 3.75 }],
  ['path', { d: 'M12 3v2.25M12 18.75V21M3 12h2.25M18.75 12H21M5.64 5.64l1.59 1.59M16.77 16.77l1.59 1.59M5.64 18.36l1.59-1.59M16.77 7.23l1.59-1.59' }],
])

// Keyed by the catalog `icon` value (GetPlatformModuleCatalog).
export const moduleIcons = {
  wrench: WrenchScrewdriverIcon,
  truck: TruckIcon,
  fuel: FuelPumpIcon,
  tires: TireIcon,
  billing: DocumentTextIcon,
  management: PresentationChartLineIcon,
  reports: DocumentChartBarIcon,
  automations: Cog6ToothIcon,
  ai: SparklesIcon,
}

export const moduleIconFor = (name) => moduleIcons[name] ?? BuildingOffice2Icon
