import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

// https://vite.dev/config/
export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  // Build desktop (Tauri) : chemins d'assets relatifs (chargement via tauri://).
  // Build web : base absolue '/' (servi à la racine, routes profondes OK).
  base: mode === 'desktop' ? './' : '/',
  // Tauri attend un port de dev fixe.
  server: {
    port: 5173,
    strictPort: true,
  },
}))
