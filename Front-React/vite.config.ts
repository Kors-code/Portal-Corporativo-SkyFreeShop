import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react-swc'

export default defineConfig({
  base: '/panel/',
  plugins: [react()],
  build: {
    manifest: true,   // 👈 ESTO ES LO QUE FALTABA
  }
})
