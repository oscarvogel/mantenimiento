import { describe, expect, it } from 'vitest'
import { equipmentDetailTabs } from './equipmentDetailTabs.js'

describe('equipment detail tabs', () => {
  it('keeps telemetry visible when no source is linked yet', () => {
    expect(equipmentDetailTabs().map((tab) => tab.id)).toContain('telemetria')
  })
})
