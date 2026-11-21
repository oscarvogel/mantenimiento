import TheoreticalCostPage from './TheoreticalCostPage.vue'
import FleetBoardPage from './FleetBoardPage.vue'

export { TheoreticalCostPage, FleetBoardPage }

export const managerialPagesByType = Object.freeze({
  'theoretical-cost-km': TheoreticalCostPage,
  'fleet-board': FleetBoardPage,
})
