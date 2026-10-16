import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // Nom affiché de l'application (provisoire) : VITE_APP_NAME au build, sinon cette valeur par défaut.
  // Il est injecté dans le code (import.meta.env.VITE_APP_NAME) et dans le <title> de index.html.
  const nomApplication = loadEnv(mode, process.cwd(), 'VITE_').VITE_APP_NAME?.trim() || 'Prise Inventaire'

  return {
    plugins: [
      react(),
      tailwindcss(),
      { name: 'nom-application', transformIndexHtml: (html: string) => html.replaceAll('%NOM_APPLICATION%', nomApplication) },
    ],
    define: {
      'import.meta.env.VITE_APP_NAME': JSON.stringify(nomApplication),
    },
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
  }
})
