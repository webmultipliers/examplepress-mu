import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  // Workers and CSS url() references resolve relative to the script.
  // An empty base means all asset URLs are emitted as relative paths,
  // so the built files work regardless of the theme's install path.
  base: './',
  build: {
    manifest: true,
    outDir: 'dist',
    rollupOptions: {
      input: {
        apps:         resolve(__dirname, 'assets/src/apps/main.js'),
        theme:        resolve(__dirname, 'assets/src/theme/main.js'),
        navigation:   resolve(__dirname, 'assets/src/navigation/main.js'),
        dependencies: resolve(__dirname, 'assets/src/dependencies/main.js'),
        library:      resolve(__dirname, 'assets/src/library/main.js'),
        settings:      resolve(__dirname, 'assets/src/settings/main.js'),
        notifications: resolve(__dirname, 'assets/src/notifications/main.js'),
        system:        resolve(__dirname, 'assets/src/system/main.js'),
        docs:         resolve(__dirname, 'assets/src/docs/main.js'),
        editor:       resolve(__dirname, 'assets/src/editor/main.js'),
      },
    },
  },
  server: {
    origin: 'http://localhost:5173',
  },
});
