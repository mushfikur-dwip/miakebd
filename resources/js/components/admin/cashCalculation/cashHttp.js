import axios from "axios";
import { tr } from "./cashCalculationText";

// Long enough for a slow mobile line, short enough that a dead one is noticed
// instead of the button spinning for minutes.
export const READ_TIMEOUT = 20000;
export const WRITE_TIMEOUT = 30000;

function newKey() {
    try {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
    } catch (e) {
        // Older browsers and plain-http pages: the fallback below.
    }
    return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
}

/**
 * A save that is safe to send again. When the answer is lost on a slow line
 * the server may already have saved it; sending the same data again reuses
 * the same one-time key, and the server answers from the first save instead
 * of saving twice (see the Idempotent middleware). Changed data is a new save
 * with a new key, and so is the same data again after a success - two equal
 * cash-ins in a row are two cash-ins.
 *
 * `memo` is a plain object the caller keeps for one form.
 */
export function save(url, data, memo) {
    const signature = url + "|" + JSON.stringify(data);
    if (memo.signature !== signature) {
        memo.signature = signature;
        memo.key = newKey();
    }

    return axios.post(url, data, {
        timeout: WRITE_TIMEOUT,
        headers: { "X-Idempotency-Key": memo.key },
    }).then((res) => {
        memo.signature = "";
        return res;
    });
}

// What to tell the employee when a save did not go through.
export function failure(err) {
    if (err && err.isNetworkError) {
        return tr("network_retry");
    }
    const body = (err && err.response && err.response.data) || {};
    return body.message || tr("something_wrong");
}
