// Bangladeshi mobile numbers as customers actually type them: with or without
// the leading 0, with +880 pasted in front, with spaces or dashes, and very
// often in Bengali digits from a Bangla keyboard. The old keypress filter
// (appService.phoneNumber) rejected Bengali digits outright, so those
// customers could not enter a number at all.
const BENGALI_ZERO = 0x09E6;

export default {
    // "০১৭১২" -> "01712"; anything that is not a digit is left for the caller.
    toLatinDigits: function (value) {
        return String(value ?? '').replace(/[০-৯]/g, digit => String(digit.charCodeAt(0) - BENGALI_ZERO));
    },

    // What the field keeps while the customer types: digits only, so a pasted
    // "+880 1712-345678" or a stray letter never reaches the model.
    clean: function (value) {
        return this.toLatinDigits(value).replace(/[^0-9]/g, '');
    },

    // The local subscriber number the server stores: "1712345678". Same
    // normalisation as GuestStartRequest and AddressRequest, so one number is
    // one identity however it was typed.
    local: function (value) {
        let digits = this.clean(value);

        if (digits.startsWith('880')) {
            digits = digits.slice(3);
        }

        return digits.replace(/^0+/, '');
    },

    // 013-019 are the operator prefixes in use; 10 digits after the 0.
    isValid: function (value) {
        return /^1[3-9][0-9]{8}$/.test(this.local(value));
    }
}
