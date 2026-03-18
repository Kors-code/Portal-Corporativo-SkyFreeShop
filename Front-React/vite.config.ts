import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react-swc'

export default defineConfig({
  base: '/panel/',
  plugins: [react()],
  build: {
    outDir: '../Backend/public/react', // 👈 aquí cambias la ruta
    emptyOutDir: true, // limpia antes de compilar
    manifest: true,
  }
})