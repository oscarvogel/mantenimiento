import baseProfile from './base/profile.js'

const compiledProfileLoaders = Object.freeze({
  base: async () => ({ default: baseProfile }),
  platform: () => import('./platform/profile.js'),
  vogel: () => import('./vogel/profile.js'),
  cliente_piloto: () => import('./cliente_piloto/profile.js'),
})

export const baseFrontendProfile = baseProfile

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

export function shouldLoadFrontendProfile(profileKey, page) {
  return page === 'dashboard' && profileKey === 'cliente_piloto'
}

export const loadFrontendProfile = createProfileLoader(compiledProfileLoaders, baseProfile)
