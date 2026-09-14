import './utils/url-polyfill.js'
import { applySafeAreaCssVars, installSafariViewportGuard } from './utils/safe-area.js'
import {
	createSSRApp
} from "vue";
import App from "./App.vue";
export function createApp() {
	try {
		applySafeAreaCssVars()
		installSafariViewportGuard()
	} catch (e) {}
	const app = createSSRApp(App);
	return {
		app,
	};
}
