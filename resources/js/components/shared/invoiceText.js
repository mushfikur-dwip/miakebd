import i18n from "../../i18n";

/**
 * The online-order invoice's wording, English and Bangla. Its own module so
 * it travels only with the pages that print an invoice.
 */
const text = {
    en: {
        invoice: "Invoice",
        invoice_no: "Invoice no.",
        date: "Date",
        order_type: "Order type",
        delivery: "Home delivery",
        pick_up: "Pick up",
        payment: "Payment",
        paid: "Paid",
        unpaid: "Unpaid",
        bill_to: "Bill to",
        ship_to: "Ship to",
        pick_up_from: "Pick up from",
        sl: "SL",
        product: "Product",
        unit_price: "Unit price",
        qty: "Qty",
        amount: "Amount",
        subtotal: "Subtotal",
        discount: "Discount",
        wallet: "Paid from wallet",
        tax: "Tax",
        shipping: "Delivery charge",
        grand_total: "Grand total",
        paid_amount: "Paid",
        due_amount: "Due",
        cod_note: "Please pay {amount} on delivery.",
        thanks: "Thank you for shopping with us!",
        support: "Questions about this order? Call {phone}",
        generated: "This is a computer-generated invoice and needs no signature.",
    },
    bn: {
        invoice: "ইনভয়েস",
        invoice_no: "ইনভয়েস নং",
        date: "তারিখ",
        order_type: "অর্ডারের ধরন",
        delivery: "হোম ডেলিভারি",
        pick_up: "পিক আপ",
        payment: "পেমেন্ট",
        paid: "পরিশোধিত",
        unpaid: "অপরিশোধিত",
        bill_to: "বিল প্রাপক",
        ship_to: "ডেলিভারি ঠিকানা",
        pick_up_from: "যেখান থেকে নেবেন",
        sl: "ক্রম",
        product: "পণ্য",
        unit_price: "একক মূল্য",
        qty: "পরিমাণ",
        amount: "মূল্য",
        subtotal: "সাবটোটাল",
        discount: "ছাড়",
        wallet: "ওয়ালেট থেকে পরিশোধ",
        tax: "ট্যাক্স",
        shipping: "ডেলিভারি চার্জ",
        grand_total: "সর্বমোট",
        paid_amount: "পরিশোধিত",
        due_amount: "বাকি",
        cod_note: "ডেলিভারির সময় {amount} পরিশোধ করুন।",
        thanks: "আমাদের কাছ থেকে কেনার জন্য ধন্যবাদ!",
        support: "এই অর্ডার নিয়ে প্রশ্ন? কল করুন {phone}",
        generated: "এটি কম্পিউটারে তৈরি ইনভয়েস, স্বাক্ষরের প্রয়োজন নেই।",
    },
};

export function tr(key, params = {}) {
    const locale = i18n.global.locale.value;
    let value = (text[locale] && text[locale][key]) || text.en[key] || key;

    Object.keys(params).forEach((name) => {
        value = value.replace("{" + name + "}", params[name]);
    });

    return value;
}
