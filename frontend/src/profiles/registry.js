import baseProfile from './base/profile.js'

const compiledProfileLoaders = Object.freeze({
  base: async () => ({ default: baseProfile }),
  platform: () => import('./platform/profile.js'),
  vogel: () => import('./vogel/profile.js'),
  cliente_piloto: () => import('./cliente_piloto/profile.js'),
})

export function createProfileLoader(loaders, fallbackProfile = baseProfile) {
  return async (profileKey) => {
    const loader = typeof profileKey === 'string' ? loaders[profileKey] : null
    if (typeof loader !== 'function') return fallbackProfile

    try {
      const module = await loader()
      return module?.default ?? fallbackProfile
    } catch (error) {
      console.warn(`No se pudo cargar el perfil visual "${profileKey}"; se usará el perfil base.`, error)
      return fallbackProfile
    }
  }
}

export const loadFrontendProfile = createProfileLoader(compiledProfileLoaders, baseProfile)
