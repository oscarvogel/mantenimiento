import { describe, expect, it, vi } from 'vitest'
import { createProfileLoader, loadFrontendProfile } from '../src/profiles/registry.js'
import base from '../src/profiles/base/profile.js'
import platform from '../src/profiles/platform/profile.js'
import vogel from '../src/profiles/vogel/profile.js'
import clientPilot from '../src/profiles/cliente_piloto/profile.js'

describe('frontend profile registry', () => {
  it('loads only compiled base, platform, and vogel profiles', async () => {
    await expect(loadFrontendProfile('base')).resolves.toBe(base)
    await expect(loadFrontendProfile('platform')).resolves.toBe(platform)
    await expect(loadFrontendProfile('vogel')).resolves.toBe(vogel)
    await expect(loadFrontendProfile('cliente_piloto')).resolves.toBe(clientPilot)
    await expect(loadFrontendProfile('../../x')).resolves.toBe(base)
  })

  it('uses the base shell when a registered profile chunk fails to load', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const loader = createProfileLoader({
      base: async () => ({ default: base }),
      vogel: async () => { throw new Error('chunk unavailable') },
    })

    await expect(loader('vogel')).resolves.toBe(base)
    expect(warn).toHaveBeenCalled()
    warn.mockRestore()
  })

  it('keeps page and dashboard registries isolated by profile', () => {
    const defaults = { 'custom-page': 'base-page' }
    const first = base.resolvePage('custom-page', defaults)
    const second = vogel.resolvePage('custom-page', defaults)

    expect(first).toBe('base-page')
    expect(second).toBe('base-page')
    expect(vogel.dashboardComponents).not.toBe(base.dashboardComponents)
    expect(vogel.key).toBe('vogel')
    expect(platform.key).toBe('platform')
    expect(clientPilot.dashboardComponents.operational).not.toBe(base.dashboardComponents.operational)
  })
})
