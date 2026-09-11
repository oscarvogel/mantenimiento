import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  base: './',
  plugins: [vue()],
  build: {
    manifest: true,
    outDir: '../assets/dashboard',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.js',
      output: {
        manualChunks(id) {
          if (id.includes('node_modules')) return 'vendor'
          if (id.includes('/pages/operations/components/Chat')) return 'chatbot'
          if (id.includes('/pages/reports/')) return 'reports'
          if (id.includes('/pages/admin/')) return 'admin'
          return undefined
        },
      },
    },
  },
  server: {
    host: '0.0.0.0',
    allowedHosts: ['terminal.local'],
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/setup.js'],
  },
})
