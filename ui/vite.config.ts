import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue()],
  build: {
    lib: { entry: 'src/index.ts', formats: ['es'], fileName: 'whistleblower-ui' },
    rollupOptions: { external: ['vue'] },
  },
  // The components format dates in the browser's timezone; tests pin it so they do not depend on the machine.
  test: { environment: 'jsdom', include: ['tests/**/*.test.ts'], env: { TZ: 'UTC' } },
})
