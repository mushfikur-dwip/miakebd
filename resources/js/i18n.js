import { createI18n } from "vue-i18n";
import en from "./languages/en.json";

/**
 * Locales are loaded on demand.
 *
 * These files are large (40–60 kB each) and only one is ever active, but the
 * previous eager import.meta.glob bundled all of them into the entry chunk
 * every visitor downloads before first paint. English ships with the bundle so
 * there is always a synchronous fallback; the rest arrive when selected.
 */
const loaders = import.meta.glob("./languages/*.json");

const i18n = createI18n({
    legacy: false,
    locale: "en",
    fallbackLocale: "en",
    messages: { en },
});

/**
 * Switches locale, fetching its messages first if they are not loaded yet.
 * Safe to call with an unknown, empty or already-active code.
 */
export async function loadLocale(code) {
    if (!code || code === i18n.global.locale.value) {
        return;
    }

    if (!i18n.global.availableLocales.includes(code)) {
        const loader = loaders[`./languages/${code}.json`];

        if (!loader) {
            return;
        }

        const messages = await loader();
        i18n.global.setLocaleMessage(code, messages.default || messages);
    }

    i18n.global.locale.value = code;
}

export default i18n;
