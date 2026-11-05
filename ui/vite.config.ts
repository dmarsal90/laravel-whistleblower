import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue()],
  build: {
    lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'whistleblower-ui' },
    rollupOptions: { external: ['vue'] },
  },
  test: { environment: 'jsdom', include: ['tests/**/*.test.ts'] },
})
