import { onBeforeUnmount, onMounted, watch } from 'vue'

// Only presentation state enters here. Network requests and business outcomes stay in ChatWidget.
export function useChatRobotMotion(root, getState, loadMotion = () => import('./chatRobotMotion.js')) {
  let motion
  let context
  let media
  let observer
  let loading = false
  let disposed = false
  let visible = true
  let stopWatching

  function clear() {
    context?.revert()
    context = undefined
  }

  async function sync() {
    clear()
    if (disposed || !root.value || media?.matches || document.hidden || !visible) return
    if (!motion) {
      if (loading) return
      loading = true
      try {
        motion = await loadMotion()
      } catch {
        // Static expressions remain usable if the optional animation chunk cannot load.
        return
      } finally {
        loading = false
      }
      return sync()
    }
    context = motion.animateRobot(root.value, getState())
  }

  onMounted(() => {
    media = window.matchMedia?.('(prefers-reduced-motion: reduce)')
    media?.addEventListener('change', sync)
    document.addEventListener('visibilitychange', sync)
    if (typeof IntersectionObserver !== 'undefined') {
      visible = false
      observer = new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting
        sync()
      })
      observer.observe(root.value)
    }
    stopWatching = watch(getState, sync, { flush: 'post' })
    sync()
  })

  onBeforeUnmount(() => {
    disposed = true
    stopWatching?.()
    media?.removeEventListener('change', sync)
    document.removeEventListener('visibilitychange', sync)
    observer?.disconnect()
    clear()
  })
}
