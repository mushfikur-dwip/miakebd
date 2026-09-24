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
 *
 * Deliberately not sent from the browser:
 *   - product names and categories. Here they read "acne", "salicylic",
 *     "eczema", and Meta restricts pixels it judges to be sending health
 *     information. The catalogue feed supplies names, matched on content_ids.
 *   - the customer's details. Matching is done by the Conversions API on the
 *     server, hashed there; handing them to the pixel meant calling
 *     fbq('init') a second time, which Meta reports as a duplicate pixel.
 *
 * Every entry point is guarded: with no pixel configured, or with fbevents.js
 * blocked (common), each call is a no-op. Tracking must never be able to break
 * a page.
 */

import axios from "axios";

/** Purchases already reported, so a refreshed receipt is not counted twice. */
const PURCHASE_KEY = "pixel-purchased-orders";

/** App\Enums\PaymentGateway::CASH_ON_DELIVERY and App\Enums\PaymentStatus::PAID. */
const CASH_ON_DELIVERY = 1;
const PAID = 5;

function config() {
    return (typeof window !== "undefined" && window.__BOOT_PIXEL__) || null;
}

function ready() {
    return typeof window !== "undefined" && typeof window.fbq === "function";
}

function currency() {
    return config()?.currency || "BDT";
}

/**
 * Which field a product is identified by - "id" or "sku".
 *
 * It must match the id column of the catalogue feed or catalogue ads retarget
 * the wrong item, so it is set once on the server (META_CONTENT_ID) and read
 * from there by the browser, the Conversions API and the feed alike.
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

function money(value) {
    const amount = Number(value);
    return Number.isFinite(amount) ? Number(amount.toFixed(2)) : 0;
}

/** `{ id, sku }` in, the catalogue id out - the SKU only when there is one. */
function contentId(product) {
    if (!product) {
        return null;
    }

    const preferred = contentIdField() === "sku" ? product.sku : product.id;
    const value = preferred !== undefined && preferred !== null && preferred !== "" ? preferred : product.id;

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
            content_ids: [...new Set(contents.map((c) => c.id))],
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
     * Fires once per order, and only for a real sale: cash on delivery, or an
     * online payment that has gone through. An abandoned bKash payment is not
     * a conversion - the server holds its copy back on the same rule.
     *
     * The receipt is a normal page a customer can reload, bookmark or reach
     * again from their order list, and every reload would otherwise be counted
     * - and paid for - as another sale.
     */
    purchase(order) {
        const orderId = order?.id;
        if (!ready() || !orderId) {
            return;
        }

        if (Number(order.payment_method) !== CASH_ON_DELIVERY && Number(order.payment_status) !== PAID) {
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
                const id = contentId(line.product_id ? { id: line.product_id, sku: line.product_sku } : null);
                return id ? { id, quantity: Number(line.quantity) || 1, item_price: money(line.price) } : null;
            })
            .filter(Boolean);

        // "order-{id}" on both sides: the server reports this same purchase
        // from OrderObserver, where no ad blocker can reach it, and Meta keeps
        // whichever copy arrives first. Never mirrored from here - a purchase
        // the browser could declare would be a forgeable conversion.
        this.track("Purchase", {
            content_ids: [...new Set(contents.map((c) => c.id))],
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
};
