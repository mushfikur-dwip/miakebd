import {useToast} from "vue-toastification";
/*
 * Position
 * --------------
 * bottom-right
 * top-center
 * top-left
 * bottom-right
 * bottom-center
 * bottom-left
 * */
export default {
    default: function (message = "Default", position = "bottom-right") {
        const toast = useToast();
        toast(message, {
            position: position,
        });
    },

    success: function (message = "Success", position = "bottom-right") {
        const toast = useToast();
        toast.success(message, {
            position: position,
        });
    },

    info: function (message = "Info", position = "bottom-right") {
        const toast = useToast();
        toast.info(message, {
            position: position,
        });
    },

    warning: function (message = "Warning", position = "bottom-right") {
        const toast = useToast();
        toast.warning(message, {
            position: position,
        });
    },

    error: function (message = "Error", position = "bottom-right") {
        const toast = useToast();
        toast.error(message, {
            position: position,
        });
    },

    /**
     * Error reporter for download requests.
     *
     * Export calls use `responseType: 'blob'`, so a failure response body
     * arrives as a Blob, not JSON. Every export handler did
     * `alertService.error(err.response.data.message)` — `.message` on a Blob is
     * undefined, so the toast said "undefined" or nothing at all and the real
     * server error was invisible. This reads the Blob back as text first.
     */
    downloadError: function (err, position = "bottom-right") {
        const body = err?.response?.data;
        const fallback = err?.message || "Export failed";

        if (body instanceof Blob) {
            body.text().then((text) => {
                let message = fallback;
                try {
                    message = JSON.parse(text).message || text || fallback;
                } catch (e) {
                    // Not JSON — an HTML error page or plain text. Showing raw
                    // markup in a toast helps nobody.
                    message = text && text.length < 200 ? text : fallback;
                }
                this.error(message, position);
            }).catch(() => this.error(fallback, position));
            return;
        }

        this.error(body?.message || fallback, position);
    },

    successFlip: function (status = null, message = "", position = "bottom-right") {
        const toast = useToast();
        if (status != null) {
            if (status) {
                message = message + " Updated Successfully.";
            } else {
                message = message + " Created Successfully.";
            }
        } else {
            message = message + " Deleted Successfully.";
        }

        toast.success(message, {
            position: position,
        });
    },

    successInfo: function (status = null, message = "", position = "bottom-right") {
        const toast = useToast();
        toast.success(message, {
            position: position,
        });
    },
};
