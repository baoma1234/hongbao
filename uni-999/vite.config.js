import { defineConfig } from 'vite'
import uni from '@dcloudio/vite-plugin-uni'

// https://vitejs.dev/config/
export default defineConfig({
  base: '/999/',
  plugins: [uni()],
  build: {
    outDir: '../public/999',
    emptyOutDir: true,
  },
  server: {
    port: 5199,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1',
        changeOrigin: true,
      },
      '/im-ws': {
        target: 'ws://127.0.0.1:17272',
        ws: true,
        changeOrigin: true,
        rewrite: (p) => p.replace(/^\/im-ws/, ''),
      },
    },
  },
})
