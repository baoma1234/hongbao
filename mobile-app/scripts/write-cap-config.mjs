import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const appRoot = join(root, '..');
const appCfg = JSON.parse(readFileSync(join(appRoot, 'app.config.json'), 'utf8'));
const h5Url = String(process.env.H5_URL || appCfg.h5Url || '')
  .trim()
  .replace(/\/?$/, '/');

const config = {
  appId: appCfg.appId,
  appName: appCfg.appName,
  webDir: 'dist',
  server: {
    url: h5Url,
    cleartext: false,
    allowNavigation: appCfg.allowNavigation || [],
  },
  android: {
    allowMixedContent: false,
    backgroundColor: '#FFFFFF',
  },
  ios: {
    contentInset: 'automatic',
    backgroundColor: '#FFFFFF',
  },
  plugins: {
    SplashScreen: {
      launchAutoHide: true,
      launchShowDuration: 800,
      backgroundColor: '#FFFFFF',
      showSpinner: false,
    },
    StatusBar: {
      style: 'DARK',
      backgroundColor: '#FFFFFF',
    },
  },
};

writeFileSync(
  join(appRoot, 'capacitor.config.json'),
  JSON.stringify(config, null, 2) + '\n',
  'utf8'
);
console.log('wrote capacitor.config.json →', h5Url);
