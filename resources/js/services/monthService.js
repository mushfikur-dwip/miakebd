/**
 * Calendar months as the admin pages filter by them.
 *
 * Earnings, sales and order lists open on the current month and step through
 * months from there. A month goes to the server as from_date/to_date, the same
 * pair the Filter panels send, so the report endpoints need no new parameters.
 *
 * Dates are plain 'YYYY-MM-DD' in local (shop) time. A Date object reaches the
 * server as free text it has to guess at, and toISOString() would turn a
 * Bangladesh midnight into the previous day.
 */
const pad = (n) => String(n).padStart(2, '0');

const monthService = {
    toYmd(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    },

    /** A filter value - 'YYYY-MM-DD', or the Date the date picker stores - as a local Date. */
    toDate(value) {
        if (!value) {
            return null;
        }
        if (value instanceof Date) {
            return value;
        }
        const ymd = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        if (ymd) {
            return new Date(+ymd[1], ymd[2] - 1, +ymd[3]);
        }
        const date = new Date(value);

        return isNaN(date) ? null : date;
    },

    /** Filter dates as the [from, to] Date pair a range date picker shows; null when unset. */
    dates(range) {
        const from = monthService.toDate(range.from_date);
        const to = monthService.toDate(range.to_date);

        return from && to ? [from, to] : null;
    },

    /** The month a moment falls in, as filter dates. */
    monthOf(date = new Date()) {
        return {
            from_date: monthService.toYmd(new Date(date.getFullYear(), date.getMonth(), 1)),
            to_date: monthService.toYmd(new Date(date.getFullYear(), date.getMonth() + 1, 0)),
        };
    },

    /** The month `step` months away from the one the range starts in (or from this month). */
    shift(range, step) {
        const from = monthService.toDate(range.from_date) ?? new Date();

        return monthService.monthOf(new Date(from.getFullYear(), from.getMonth() + step, 1));
    },

    /** True when the range runs from the first to the last day of one month. */
    isWholeMonth(range) {
        const from = monthService.toDate(range.from_date);
        const to = monthService.toDate(range.to_date);
        if (!from || !to) {
            return false;
        }
        const month = monthService.monthOf(from);

        return monthService.toYmd(from) === month.from_date && monthService.toYmd(to) === month.to_date;
    },
};

export default monthService;
