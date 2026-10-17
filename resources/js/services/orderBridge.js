/**
 * The "Confirming your order..." screen master.blade.php paints on the order
 * page before the app has loaded.
 *
 * Placing an order leaves the app: the payment step is a server redirect, and
 * the customer comes back to /account/order-details/{id}?status=success with
 * a full page load. PaymentComponent shows an overlay the moment Confirm Order
 * is pressed, the browser keeps that page on screen through the redirects, and
 * this copy of it is already there when the new page first paints - so the
 * customer sees one screen from the tap to "Order placed!" instead of a blank
 * reload. It is the app's job to take it down again.
 */
const ID = "order-bridge";

function element() {
    return document.getElementById(ID);
}

export default {
    /** True while the screen is up and nothing has started taking it down. */
    isShown: function () {
        const bridge = element();

        return !!bridge && !bridge.hidden && !bridge.dataset.leaving;
    },

    /**
     * Takes the screen down.
     *
     * handoff: the success card is opening underneath with its own backdrop,
     * so the dark backdrop here goes at once and only the card fades - two
     * backdrops on top of each other would flash the page darker for a moment.
     * Otherwise (the customer was sent anywhere else, e.g. to log in) the whole
     * screen fades out.
     */
    release: function (handoff = false) {
        const bridge = element();

        if (!bridge || bridge.dataset.leaving) {
            return;
        }

        if (bridge.hidden) {
            bridge.remove();
            return;
        }

        bridge.dataset.leaving = "1";
        bridge.classList.add(handoff ? "ob-handoff" : "ob-leaving");
        setTimeout(() => bridge.remove(), 260);
    },
};
