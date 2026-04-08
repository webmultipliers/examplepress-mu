import { defineConfig } from 'vite';
import { resolve } from 'path';

const mu = 'examplepress-mu';

export default defineConfig({
  base: './',
  build: {
    manifest: true,
    outDir: resolve(__dirname, mu, 'dist'),
    emptyOutDir: true,
    rollupOptions: {
      input: {
        apps:          resolve(__dirname, mu, 'assets/src/apps/main.js'),
        updates:       resolve(__dirname, mu, 'assets/src/updates/main.js'),
        theme:         resolve(__dirname, mu, 'assets/src/theme/main.js'),
        navigation:    resolve(__dirname, mu, 'assets/src/navigation/main.js'),
        dependencies:  resolve(__dirname, mu, 'assets/src/dependencies/main.js'),
        library:       resolve(__dirname, mu, 'assets/src/library/main.js'),
        settings:      resolve(__dirname, mu, 'assets/src/settings/main.js'),
        notifications: resolve(__dirname, mu, 'assets/src/notifications/main.js'),
        system:        resolve(__dirname, mu, 'assets/src/system/main.js'),
        docs:          resolve(__dirname, mu, 'assets/src/docs/main.js'),
        editor:        resolve(__dirname, mu, 'assets/src/editor/main.js'),
      },
      external: ['monaco-editor'],
    },
  },
  server: {
    origin: 'http://localhost:5173',
  },
});
