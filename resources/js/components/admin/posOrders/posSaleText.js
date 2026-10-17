import i18n from "../../../i18n";

/**
 * Wording for putting a POS sale right - Reverse and Change payment method -
 * in English and Bangla. Kept beside the POS order pages rather than in
 * languages/*.json, which ship with the storefront.
 */
const text = {
    en: {
        reverse_sale: "Reverse sale",
        change_method: "Change payment method",
        reversed: "Reversed",
        reversed_banner: "This sale was reversed. Its money, its stock and every report have been taken back; it stays here as a record.",
        reverse_help: "The whole sale is taken back everywhere: the money leaves the drawer (or the day's card / MFS sales), the items go back into this branch's stock, and the sale leaves every report. It cannot be undone - a real sale reversed by mistake is rung up again.",
        method_help: "For a sale that was paid differently from what was chosen at the till. Its money moves to the right place; the sale itself stays as it is.",
        paid_now: "Paid now by",
        new_method: "Paid by",
        reason: "Reason",
        reason_placeholder: "Why is this being done?",
        branch_pin: "Branch PIN",
        method_pin: "Payment-method PIN",
        cancel: "Cancel",
        reversed_ok: "Sale reversed.",
        method_ok: "Payment method changed.",
        history: "Changes to this sale",
        history_reverse: "Reversed",
        history_method: "Payment method changed",
        by: "By",
        locked_note: "Both need a PIN. Every change is recorded with your name and sent to the owner.",
    },
    bn: {
        reverse_sale: "বিক্রি রিভার্স",
        change_method: "পেমেন্ট মেথড বদল",
        reversed: "রিভার্স করা",
        reversed_banner: "এই বিক্রি রিভার্স করা হয়েছে। এর টাকা, স্টক ও সব রিপোর্ট থেকে ফেরত নেওয়া হয়েছে; রেকর্ড হিসেবে এখানে থাকবে।",
        reverse_help: "পুরো বিক্রি সব জায়গা থেকে ফেরত যাবে: টাকা ড্রয়ার থেকে (বা দিনের কার্ড / MFS বিক্রি থেকে) বাদ যাবে, পণ্য এই শাখার স্টকে ফিরবে, আর বিক্রিটি সব রিপোর্ট থেকে বাদ যাবে। এটা আর ফেরানো যায় না - ভুল করে রিভার্স হলে বিক্রিটি আবার করুন।",
        method_help: "যে বিক্রির টাকা টিলে বাছাই করা মেথডের বদলে অন্যভাবে দেওয়া হয়েছে তার জন্য। টাকা সঠিক জায়গায় সরে যাবে; বিক্রি যেমন ছিল তেমনই থাকবে।",
        paid_now: "এখন পরিশোধ দেখাচ্ছে",
        new_method: "আসলে পরিশোধ",
        reason: "কারণ",
        reason_placeholder: "কেন করা হচ্ছে?",
        branch_pin: "শাখার পিন",
        method_pin: "পেমেন্ট মেথড পিন",
        cancel: "বাতিল",
        reversed_ok: "বিক্রি রিভার্স হয়েছে।",
        method_ok: "পেমেন্ট মেথড বদলানো হয়েছে।",
        history: "এই বিক্রিতে করা পরিবর্তন",
        history_reverse: "রিভার্স",
        history_method: "পেমেন্ট মেথড বদল",
        by: "করেছেন",
        locked_note: "দুটোতেই পিন লাগে। প্রতিটি পরিবর্তন আপনার নামে রেকর্ড হয় আর মালিকের কাছে যায়।",
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
