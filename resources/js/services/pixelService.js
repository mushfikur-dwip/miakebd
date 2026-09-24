/**
 * Meta (Facebook) Pixel for the storefront.
 *
 * The base code is printed by master.blade.php, which also leaves the pixel id
 * on window.__BOOT_PIXEL__. Everything here is the part a single-page app has
 * to do for itself: the base code fires exactly one PageView, for the first
 * screen, and Meta never hears about any other page, product or purchase
 * unless the app says so.
 *
 * What this feeds:
 *   PageView        - every screen, so "visited the site" audiences are real
 *   ViewContent     - product pages: the event retargeting and catalogue ads
 *                     are built on
 *   AddToCart       - "added but did not buy" audiences
 *   InitiateCheckout- "reached checkout but did not buy" audiences
 *   Purchase        - conversion, and the value ads optimise towards
 *   Advanced Matching - the customer's own phone/name/city when the shop knows
 *                     them, which is what lets Meta match a visitor to a real
 *                     profile instead of guessing from a cookie
 *
 * Every entry point is guarded: with no pixel configured, or with fbevents.js
 * blocked (common), each call is a no-op. Tracking must never be able to break
 * a page.
 */

/**
 * Which field a product is identified by - "id" or "sku".
 *
 * It must match the id column of the Facebook catalogue feed or catalogue ads
 * retarget the wrong item, so it is set once on the server
 * (META_CONTENT_ID) and read from there by both sides.
 */
function contentIdField() {
    return config()?.content_id === "sku" ? "sku" : "id";
}

/** Ties a browser event to the server's copy of it, so Meta keeps only one. */
function newEventId() {
    try {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }
    } catch (e) {
        // falls through
    }

    return "e" + Date.now() + "-" + Math.random().toString(36).slice(2, 10);
}

import axios from "axios";

/** Purchases already reported, so a refreshed receipt is not counted twice. */
const PURCHASE_KEY = "pixel-purchased-orders";

/** Last Advanced Matching payload sent, to avoid re-initialising on every hop. */
let lastIdentity = null;

function config() {
    return (typeof window !== "undefined" && window.__BOOT_PIXEL__) || null;
}

function ready() {
    return typeof window !== "undefined" && typeof window.fbq === "function";
}

function currency() {
    return config()?.currency || "BDT";
}

/** Bangladeshi numbers the way Meta wants them: country code, digits only. */
function normalisePhone(phone, callingCode) {
    const digits = String(phone || "").replace(/[^0-9]/g, "");
    if (!digits) {
        return null;
    }

    const code = String(callingCode || "880").replace(/[^0-9]/g, "") || "880";

    if (digits.startsWith(code)) {
        return digits;
    }

    // Local form: 01712345678 or 1712345678.
    return code + digits.replace(/^0+/, "");
}

function money(value) {
    const amount = Number(value);
    return Number.isFinite(amount) ? Number(amount.toFixed(2)) : 0;
}

function contentId(product) {
    if (!product) {
        return null;
    }

    const value = product[contentIdField()] ?? product.id;
    return value === undefined || value === null ? null : String(value);
}

/**
 * Sends the same event to the shop's own server, which forwards it to Meta's
 * Conversions API.
 *
 * This is the copy that survives an ad blocker or iOS: facebook.net may be
 * unreachable, this site is not. Both copies carry the same event_id, so Meta
 * counts one. Prices are not sent - the server reads them from the database -
 * and a failure here is ignored, because tracking must never surface an error
 * to a customer.
 */
function mirrorToServer(event, eventId, body) {
    if (!config()?.id) {
        return;
    }

    try {
        axios.post("frontend/track", { event, event_id: eventId, source_url: window.location.href, ...body })
            .catch(() => {});
    } catch (e) {
        // ignore
    }
}

export default {
    /**
     * Tells Meta who this visitor is, when the shop knows.
     *
     * Passed to fbq('init'), which normalises and hashes the values in the
     * browser before they leave it - the raw phone number is never sent. A
     * matched visitor can be retargeted across their devices, and purchases
     * they make later are attributed to the ad they actually clicked.
     */
    identify(user = {}, address = {}) {
        const settings = config();
        if (!ready() || !settings?.id) {
            return;
        }

        const name = String(user.name || "").trim();
        const spaceAt = name.indexOf(" ");

        const data = {
            em: user.email || undefined,
            ph: normalisePhone(user.phone, user.country_code) || undefined,
            fn: (spaceAt > 0 ? name.slice(0, spaceAt) : name) || undefined,
            ln: (spaceAt > 0 ? name.slice(spaceAt + 1) : "") || undefined,
            ct: address.city || undefined,
            st: address.state || undefined,
            zp: address.zip_code || undefined,
            country: address.country || undefined,
            external_id: user.id ? String(user.id) : undefined,
        };

        Object.keys(data).forEach((key) => data[key] === undefined && delete data[key]);

        const fingerprint = JSON.stringify(data);
        if (!Object.keys(data).length || fingerprint === lastIdentity) {
            return;
        }
        lastIdentity = fingerprint;

        try {
            window.fbq("init", settings.id, data);
        } catch (e) {
            // Never let tracking break the page.
        }
    },

    /** Reads whatever the store already knows about this visitor. */
    identifyFromStore(store) {
        try {
            const user = store?.getters?.authInfo || {};
            const address = store?.getters?.["frontendCart/shippingAddress"] || {};
            this.identify(user, address);
        } catch (e) {
            // ignore
        }
    },

    /**
     * `eventId` is what pairs this with the server's copy of the same event.
     * Meta keeps whichever arrives first and drops the other.
     */
    track(event, params = {}, eventId = null) {
        if (!ready()) {
            return;
        }

        try {
            window.fbq("track", event, params, eventId ? { eventID: eventId } : undefined);
        } catch (e) {
            // ignore
        }
    },

    pageView() {
        this.track("PageView");
    },

    /** The product page a visitor lands on from an ad. */
    viewContent(product) {
        const id = contentId(product);
        if (!id) {
            return;
        }

        const eventId = newEventId();

        this.track("ViewContent", {
            content_ids: [id],
            content_type: "product",
            content_name: product.name,
            content_category: product.category_name || product.category?.name || undefined,
            value: money(product.flat_discounted_price ?? product.flat_price ?? product.price),
            currency: currency(),
        }, eventId);

        mirrorToServer("ViewContent", eventId, { product_id: product.id });
    },

    addToCart(product, quantity = 1) {
        const id = contentId(product);
        if (!id) {
            return;
        }

        const price = money(product.flat_discounted_price ?? product.flat_price ?? product.price);
        const eventId = newEventId();

        this.track("AddToCart", {
            content_ids: [id],
            content_type: "product",
            content_name: product.name,
            contents: [{ id, quantity, item_price: price }],
            value: money(price * quantity),
            currency: currency(),
        }, eventId);

        mirrorToServer("AddToCart", eventId, { product_id: product.id, quantity });
    },

    /** Cart lines as the storefront cart stores them. */
    initiateCheckout(lines = [], total = 0) {
        const contents = lines
            .map((line) => {
                const id = contentId(line.product_id ? { id: line.product_id, sku: line.sku } : null);
                return id ? { id, quantity: line.quantity, item_price: money(line.price) } : null;
            })
            .filter(Boolean);

        const eventId = newEventId();

        this.track("InitiateCheckout", {
            content_ids: contents.map((c) => c.id),
            content_type: "product",
            contents,
            num_items: contents.reduce((sum, c) => sum + (Number(c.quantity) || 0), 0),
            value: money(total),
            currency: currency(),
        }, eventId);

        // Product ids and quantities only: the server re-prices the basket.
        mirrorToServer("InitiateCheckout", eventId, {
            contents: lines
                .filter((line) => line.product_id)
                .map((line) => ({ id: line.product_id, quantity: Number(line.quantity) || 1 })),
        });
    },

    /**
     * Fires once per order. The receipt is a normal page a customer can
     * reload, bookmark or reach again from their order list, and every reload
     * would otherwise be counted - and paid for - as another sale.
     */
    purchase(order) {
        const orderId = order?.id;
        if (!ready() || !orderId) {
            return;
        }

        let reported = [];
        try {
            reported = JSON.parse(window.localStorage.getItem(PURCHASE_KEY) || "[]");
        } catch (e) {
            reported = [];
        }

        if (reported.includes(orderId)) {
            return;
        }

        const contents = (order.order_products || order.products || [])
            .map((line) => {
                const id = line.product_id ? String(line.product_id) : null;
                return id ? { id, quantity: line.quantity, item_price: money(line.price) } : null;
            })
            .filter(Boolean);

        // "order-{id}" on both sides: the server reports this same purchase
        // from OrderObserver, where no ad blocker can reach it, and Meta keeps
        // whichever copy arrives first. Never mirrored from here - a purchase
        // the browser could declare would be a forgeable conversion.
        this.track("Purchase", {
            content_ids: contents.map((c) => c.id),
            content_type: "product",
            contents,
            num_items: contents.reduce((sum, c) => sum + (Number(c.quantity) || 0), 0),
            // OrderDetailsResource calls the plain number total_amount_price;
            // the others are formatted strings with a currency symbol in them.
            value: money(order.total_amount_price ?? order.total),
            currency: currency(),
        }, "order-" + orderId);

        try {
            window.localStorage.setItem(PURCHASE_KEY, JSON.stringify([...reported, orderId].slice(-50)));
        } catch (e) {
            // A private window cannot store this; a duplicate is better than a lost sale.
        }
    },

    /** For the lead capture work, when that lands. */
    lead(params = {}) {
        this.track("Lead", { currency: currency(), ...params });
    },
};
