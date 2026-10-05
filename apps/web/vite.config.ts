import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: true,
    port: 5173,
    watch: {
      // Bind mount Docker sur Windows : les événements du système de
      // fichiers ne traversent pas fiablement la VM Docker Desktop, chokidar
      // doit donc sonder les fichiers au lieu d'attendre des notifications.
      usePolling: true,
      interval: 300,
    },
  },
})
