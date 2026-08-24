import './utils/url-polyfill.js'
import { applySafeAreaCssVars } from './utils/safe-area.js'
import {
	createSSRApp
} from "vue";
import App from "./App.vue";
export function createApp() {
	try {
		applySafeAreaCssVars()
	} catch (e) {}
	const app = createSSRApp(App);
	return {
		app,
	};
}
