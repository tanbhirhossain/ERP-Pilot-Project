/**
 * The UI-language kernel — U19 (Bengali / RTL).
 *
 * Deliberately *pure*: `translate()` and `directionFor()` take their inputs and touch no Vue or
 * Inertia context, which is what lets them be unit-tested in isolation and reused by the server-side
 * `Locale` class's front-end twin. `useLocale()` (the composable) is the only place that wires this to
 * the page props.
 *
 * How a lookup resolves: the active language, then English, then the key itself. English is the source
 * language, so an untranslated string on a page in Bangla mode shows its English rather than a blank —
 * localisation is genuinely incremental and a half-translated screen is still a working screen. For
 * menu items this is the whole trick: the key is the item's English label, so the sidebar localises with
 * one `t(item.label)` and never needs a per-item key, and an item nobody has translated yet still shows
 * correct English.
 *
 * Numbers and dates are intentionally NOT reformatted here. That is a display concern for each screen
 * (and `Intl` with a `bn-BD` locale is the right tool); the kernel only swaps strings and direction, so
 * adding it changes no existing formatting.
 */

export type LocaleCode = 'en' | 'bn';
export type Direction = 'ltr' | 'rtl';

/** `dir` is derived from the code and nowhere else, so it can never disagree with the language. */
export function directionFor(code: LocaleCode | string): Direction {
    return code === 'bn' ? 'rtl' : 'ltr';
}

type Dict = Record<string, string>;

/**
 * English strings. Most entries exist only so the en↔bn pair can be diffed and so a key used in
 * component code has a canonical English here; untranslated keys fall back to the key itself.
 */
const en: Dict = {
    'app.language': 'Language',
    'common.save': 'Save',
    'common.cancel': 'Cancel',
    'common.search': 'Search',
    'common.apply': 'Apply',
    'common.deposit': 'Deposit',
    'common.certify': 'Certify',
    'common.print': 'Print',
    'common.download': 'Download PDF',
    'common.new': 'New',
    'common.actions': 'Actions',
    'common.status': 'Status',
    'common.period': 'Period',
    'common.month': 'Month',
    'common.amount': 'Amount',
    'common.rate': 'Rate',
    'common.total': 'Total',
    'tax.vatReturn': 'Mushak 9.1 — VAT Return',
    'tax.vatReturnSubtitle': 'Output VAT less input credit and certified VDS, for the selected month.',
    'tax.outputVat': 'Output VAT',
    'tax.inputVat': 'Input VAT',
    'tax.netPayable': 'Net payable',
    'tax.creditCarried': 'Credit carried forward',
    'tax.withholding': 'TDS / VDS Withholding',
    'tax.inward': 'Inward (deducted on our purchases)',
    'tax.outward': 'Outward (withheld from our sales)',
    'tax.deposits': 'Deposits',
    'tax.rules': 'Rules',
    'tax.workingPaper': 'Return working paper',
    'tax.salesRegister': 'Sales register',
    'tax.purchaseRegister': 'Purchase register',
    'tax.outputTaxOnSupplies': 'Output tax on supplies',
    'tax.inputTaxCredit': 'Input tax credit',
    'tax.tabInward': 'Deducted on bills',
    'tax.tabOutward': 'VDS on our sales',
    'tax.tabDeposits': 'Deposits to NBR',
    'tax.tabRules': 'Rates',
    'assets.register': 'Fixed Asset Register',
    'assets.registerSubtitle': 'Capitalise assets, run monthly depreciation and keep the ledger’s fixed-asset block current.',
    'assets.new': 'New asset',
    'assets.capitalise': 'Capitalise',
    'assets.dispose': 'Dispose',
    'assets.runTitle': 'Monthly Depreciation',
    'assets.run': 'Run depreciation',
    'assets.cost': 'Cost',
    'assets.accumulated': 'Accumulated',
    'assets.nbv': 'Net book value',
    'budget.new': 'New budget',
    'budget.lines': 'Budget lines',
    'budget.approve': 'Approve',
    'budget.lock': 'Lock',
    'budget.variance': 'Budget vs Actual',
    'budget.budgeted': 'Budgeted',
    'budget.actual': 'Actual',
    'budget.addLine': 'Add line',

    'API Tokens': 'API Tokens',
    'API Reference': 'API Reference',
    'api.activeTokens': 'Active tokens',
    'api.tokensIntro': 'A token can only ever reach what your own roles allow, narrowed by the abilities you grant it. Issuing one is not an escalation, which is why this screen is not gated behind a permission.',
    'api.newToken': 'New token',
    'api.tokenName': 'Name',
    'api.abilities': 'Abilities',
    'api.expiresInDays': 'Valid for (days)',
    'api.noExpiry': 'Leave blank for no expiry',
    'api.revoke': 'Revoke',
    'api.lastUsed': 'Last used',
    'api.neverUsed': 'never',
    'api.expires': 'Expires',
    'api.oneTime': 'Copy it now — only the hash is stored, so it can never be shown again.',
    'api.copy': 'Copy token',
    'api.copied': 'Copied',
    'api.noTokens': 'No tokens yet. Create one and paste it into your integration as a Bearer token.',
    'api.requiredPermission': 'Required permission',
    'api.requiredAbility': 'Required ability',
    'api.parameters': 'Parameters',
    'api.endpoints': 'Endpoints',
    'api.specNote': 'Generated from the live route table — it can only describe what this deployment actually serves.',
    'api.usesCasts': 'Returns',

    'Budgets': 'Budgets',
    'Budget Variance': 'Budget Variance',
    // F22 — the offline till (queue desk, header signal, PWA install).
    'Offline POS Queue': 'Offline POS Queue',
    'Offline': 'Offline',
    'pos.offlineBadgeWaiting': ':n offline sale:nPlural · ৳:v',
    'pos.offlineIntro': 'Sales taken while this device had no network, and what the ledger has accepted from it. Nothing here is a draft: the money was handed over, the goods went out, and this queue is the only record until a sync lands.',
    'pos.offlineWaiting': 'Waiting to post',
    'pos.offlineNeedsAttention': 'Needs attention',
    'pos.offlinePostedToday': 'Posted today',
    'pos.offlineMoneyWaiting': 'Money waiting',
    'pos.offlineBackToTill': 'Back to the till',
    'pos.offlineSendNow': 'Send the queue',
    'pos.offlineNoNetwork': 'Still no connection — the queue stays where it is.',
    'pos.offlineSendFailed': 'The server did not answer. Nothing was dropped; everything will be tried again.',
    'pos.offlineSendDone': 'Sent: :posted posted, :attention need a decision.',
    'pos.offlineEngine': 'Queue storage on this device',
    'pos.offlineEngineIdb': 'IndexedDB — survives a closed tab and a reboot.',
    'pos.offlineEngineLocal': 'Browser local storage — IndexedDB was refused, so a very full device could drop the oldest tickets.',
    'pos.offlineEngineMemory': 'Memory only — this browser is blocking storage. Close the tab and the queue is gone.',
    'pos.offlineEngineNone': 'Unavailable — this browser has no storage at all. Do not rely on the queue.',
    'pos.offlineOnline': 'Online',
    'pos.offlineOffline': 'Offline',
    'pos.offlineOnlineNote': 'A browser that reports "online" may still be behind a dead router, so the queue keeps a ticket until the server answers for it.',
    'pos.offlineCatalog': 'Cached catalogue',
    'pos.offlineCatalogAge': 'cached :min min ago',
    'pos.offlineCatalogPartial': 'partial',
    'pos.offlineCatalogOtherTill': 'for another warehouse',
    'pos.offlineCatalogEmpty': 'Nothing cached yet. Refresh it once while the network is up, and the till can then price and scan without one.',
    'pos.offlineCatalogRefresh': 'Refresh catalogue',
    'pos.offlineStaleWarning': 'A ticket was rung up on an earlier date. The ledger dates a sale by the day it is posted, not the day it was taken, so those are refused until someone decides — close the drawer that owns that day first, or post it to today on purpose.',
    'pos.offlineQueue': 'This device’s queue',
    'pos.offlineEmpty': 'Nothing waiting',
    'pos.offlineEmptyNote': 'Every sale this device queued has been accepted by the ledger.',
    'pos.offlineAttempts': ':n attempt:nPlural',
    'pos.offlineStatus.queued': 'Waiting',
    'pos.offlineStatus.in_flight': 'Sending',
    'pos.offlineStatus.attention': 'Needs attention',
    'pos.offlineStatus.posted': 'In the ledger',
    'pos.offlineTryAgain': 'Try again',
    'pos.offlinePostToToday': 'Post it to today',
    'pos.offlineDiscard': 'Forget',
    'pos.offlineDiscardTitle': 'Forget this queued sale?',
    'pos.offlineDiscardBody': 'The ledger has never seen this ticket. Forgetting it removes it from this device only — it does not cancel or reverse anything, and if the money was taken it still has to be accounted for by hand.',
    'pos.offlineOpenReceipt': 'Open the receipt',
    'pos.offlineBatchNote': 'Only :max tickets go in one sync; the rest follow on the next one.',
    'pos.offlineSlip': 'Slip',
    'pos.offlinePrintSlip': 'Print slip',
    'pos.offlineSlipShop': 'ERP PILOT — OFFLINE SLIP',
    'pos.offlineSlipLedger': 'In the ledger as :no',
    'pos.offlineSlipPending': 'NOT YET IN THE LEDGER — held on this device',
    'pos.offlineSlipTotal': 'Total',
    'pos.offlineSlipFooter': 'Handed over while this till had no network. The official receipt is printed from the ledger entry once the sale posts.',
    'pos.offlineLedgerHeading': 'What the ledger accepted',
    'pos.offlineLedgerIntro': 'Sales from any device that came in through the offline queue since the start of the month, with the counter time beside the ledger date so a gap is visible rather than deniable.',
    'pos.offlineColSale': 'Sale',
    'pos.offlineColRung': 'Rung up at',
    'pos.offlineColAmount': 'Amount',
    'pos.offlineNoDrawer': 'no drawer',
    'pos.offlineLedgerEmpty': 'No sale has arrived from an offline queue this month.',
    'pos.offlineLateMinutes': ':min min after the counter',
    'pos.offlineLateHours': ':hours h after the counter',
    'pos.offlineInstall': 'Install the till',
    'pos.offlineInstallNote': 'Installed, the till opens in its own window, cold-boots with no network, and keeps the queue on this device.',
    'pos.offlineInstallButton': 'Install this till',
    'pos.offlineInstallManual': 'The browser has not offered an install prompt yet — open the browser menu and choose Install app or Add to Home screen.',
};

/**
 * Bangla. Nav labels are keyed by their exact English so the sidebar needs no per-item key — see the
 * module docblock. The dotted keys are the shell + tax-screen strings.
 */
const bn: Dict = {
    'app.language': 'ভাষা',
    'common.save': 'সংরক্ষণ',
    'common.cancel': 'বাতিল',
    'common.search': 'খুঁজুন',
    'common.apply': 'প্রয়োগ করুন',
    'common.deposit': 'জমা দিন',
    'common.certify': 'সনদ করুন',
    'common.print': 'প্রিন্ট',
    'common.download': 'পিডিএফ ডাউনলোড',
    'common.new': 'নতুন',
    'common.actions': 'কার্যক্রম',
    'common.status': 'অবস্থা',
    'common.period': 'সময়কাল',
    'common.month': 'মাস',
    'common.amount': 'টাকার পরিমাণ',
    'common.rate': 'হার',
    'common.total': 'মোট',
    'tax.vatReturn': 'মুশক ৯.১ — ভ্যাট রিটার্ন',
    'tax.vatReturnSubtitle': 'নির্বাচিত মাসে আউটপুট ভ্যাট বিয়োগ ইনপুট ক্রেডিট ও সনদকৃত ভিডিএস।',
    'tax.outputVat': 'আউটপুট ভ্যাট',
    'tax.inputVat': 'ইনপুট ভ্যাট',
    'tax.netPayable': 'পরিশোধযোগ্য নিট',
    'tax.creditCarried': 'ক্রেডিট হস্তান্তরিত',
    'tax.withholding': 'টিডিএস / ভিডিএস কর কর্তন',
    'tax.inward': 'অন্তর্মুখী (আমাদের ক্রয়ে কর্তন)',
    'tax.outward': 'বহির্মুখী (আমাদের বিক্রয় থেকে কর্তন)',
    'tax.deposits': 'জমাকৃত',
    'tax.rules': 'নিয়ম',
    'tax.workingPaper': 'রিটার্ন ওয়ার্কিং পেপার',
    'tax.salesRegister': 'বিক্রয় রেজিস্টার',
    'tax.purchaseRegister': 'ক্রয় রেজিস্টার',
    'tax.outputTaxOnSupplies': 'সরবরাহে আউটপুট কর',
    'tax.inputTaxCredit': 'ইনপুট কর ক্রেডিট',
    'tax.tabInward': 'বিলে কর্তন',
    'tax.tabOutward': 'আমাদের বিক্রয়ে ভিডিএস',
    'tax.tabDeposits': 'এনবিরে জমা',
    'tax.tabRules': 'হার',
    'assets.register': 'স্থায়ী সম্পদ নিবন্ধন',
    'assets.registerSubtitle': 'সম্পদ মূলধনায়ন করুন, মাসিক অবচয় চালান এবং লেজারের স্থায়ী-সম্পদ বিভাগ হালনাগাদ রাখুন।',
    'assets.new': 'নতুন সম্পদ',
    'assets.capitalise': 'মূলধনায়ন',
    'assets.dispose': 'বিলোপ',
    'assets.runTitle': 'মাসিক অবচয়',
    'assets.run': 'অবচয় চালান',
    'assets.cost': 'মূল্য',
    'assets.accumulated': 'জমাকৃত অবচয়',
    'assets.nbv': 'বইমূল্য (নিট)',
    'budget.new': 'নতুন বাজেট',
    'budget.lines': 'বাজেট লাইন',
    'budget.approve': 'অনুমোদন',
    'budget.lock': 'লক',
    'budget.variance': 'বাজেট বনাম প্রকৃত',
    'budget.budgeted': 'বাজেটকৃত',
    'budget.actual': 'প্রকৃত',
    'budget.addLine': 'লাইন যোগ করুন',
    'API Tokens': 'এপিআই টোকেন',
    'API Reference': 'এপিআই রেফারেন্স',
    'api.activeTokens': 'সক্রিয় টোকেন',
    'api.tokensIntro': 'টোকেন কেবল আপনার নিজের ভূমিকা যেটুকু অনুমতি দেয় তাই দেখতে পাবে, এবং ক্ষমতা দিয়ে তা আরও সীমিত হয়। তাই এই পাতা কোনো আলাদা অনুমতির পেছনে নেই।',
    'api.newToken': 'নতুন টোকেন',
    'api.tokenName': 'নাম',
    'api.abilities': 'ক্ষমতা',
    'api.expiresInDays': 'মেয়াদ (দিন)',
    'api.noExpiry': 'খালি রাখলে কোনো মেয়াদ নয়',
    'api.revoke': 'বাতিল',
    'api.lastUsed': 'সর্বশেষ ব্যবহার',
    'api.neverUsed': 'কখনো নয়',
    'api.expires': 'মেয়াদ শেষ',
    'api.oneTime': 'এখনই কপি করুন — শুধু হ্যাশ সংরক্ষিত হয়, তাই আবার দেখানো যাবে না।',
    'api.copy': 'টোকেন কপি',
    'api.copied': 'কপি হয়েছে',
    'api.noTokens': 'এখনো কোনো টোকেন নেই। একটি তৈরি করে আপনার ইন্টিগ্রেশনে Bearer টোকেন হিসেবে বসান।',
    'api.requiredPermission': 'প্রয়োজনীয় অনুমতি',
    'api.requiredAbility': 'প্রয়োজনীয় ক্ষমতা',
    'api.parameters': 'প্যারামিটার',
    'api.endpoints': 'এন্ডপয়েন্ট',
    'api.specNote': 'জীবন্ত রাউট টেবিল থেকে তৈরি — এই ইনস্টলেশন আসলে যা পরিবেশন করে তাই কেবল বর্ণনা করবে।',
    'api.usesCasts': 'রিটার্ন',
    'Budgets': 'বাজেট',
    'Budget Variance': 'বাজেট ব্যবধান',

    // Top-level navigation spine (keys are the English menu labels).
    Dashboard: 'ড্যাশবোর্ড',
    Accounts: 'হিসাব',
    Sales: 'বিক্রয়',
    Purchase: 'ক্রয়',
    Inventory: 'ইনভেন্টরি',
    Partners: 'অংশীদার',
    HRM: 'মানবসম্পদ',
    Payroll: 'বেতনভাতা',
    Organization: 'প্রতিষ্ঠান',
    Settings: 'সেটিংস',
    Approvals: 'অনুমোদন',
    'Financial Reports': 'আর্থিক প্রতিবেদন',
    'VAT Return': 'ভ্যাট রিটার্ন',
    Withholding: 'কর কর্তন',
    'Offline POS Queue': 'অফলাইন বিক্রির সারি',
    'Offline': 'অফলাইন',
    'pos.offlineBadgeWaiting': ':nটি বিক্রি অপেক্ষমাণ · ৳:v',
    'pos.offlineIntro': 'যে বিক্রিগুলো এই ডিভাইসে নেটওয়ার্ক না থাকার সময় নেওয়া হয়েছে, আর লেজার যেগুলো গ্রহণ করেছে। এখানে কিছুই খসড়া নয়: টাকা হাতে এসেছে, পণ্য গেছে, সার্ভারে সিঙ্ক হওয়ার আগে এই সারিই একমাত্র হিসাব।',
    'pos.offlineWaiting': 'যোগ হওয়ার অপেক্ষায়',
    'pos.offlineNeedsAttention': 'সমাধান দরকার',
    'pos.offlinePostedToday': 'আজ যোগ হয়েছে',
    'pos.offlineMoneyWaiting': 'অপেক্ষমাণ টাকা',
    'pos.offlineBackToTill': 'ক্যাশ কাউন্টারে ফিরুন',
    'pos.offlineSendNow': 'সারি পাঠান',
    'pos.offlineNoNetwork': 'সংযোগ নেই — সারি যেমন ছিল তেমনই আছে।',
    'pos.offlineSendFailed': 'সার্ভার উত্তর দেয়নি। কিছুই হারায়নি; সব আবার চেষ্টা করা হবে।',
    'pos.offlineSendDone': 'পাঠানো হয়েছে: :টি যোগ হয়েছে, :aটিতে সিদ্ধান্ত দরকার।',
    'pos.offlineEngine': 'এই ডিভাইসে সারি রাখার জায়গা',
    'pos.offlineEngineIdb': 'IndexedDB — ট্যাব বন্ধ করলেও, রিবুট হলেও থাকে।',
    'pos.offlineEngineLocal': 'ব্রাউজারের লোকাল স্টোরেজ — IndexedDB না পাওয়ায় পুরনো বিক্রিগুলো সরে যেতে পারে।',
    'pos.offlineEngineMemory': 'শুধু মেমরিতে — এই ব্রাউজার জমা রাখতে দেয় না। ট্যাব বন্ধ করলে সারি মুছে যাবে।',
    'pos.offlineEngineNone': 'জমা রাখার জায়গা নেই — সারির ওপর নির্ভর করবেন না।',
    'pos.offlineOnline': 'সংযোগ আছে',
    'pos.offlineOffline': 'সংযোগ নেই',
    'pos.offlineOnlineNote': 'ব্রাউজার "সংযোগ আছে" বললেও রাউটার বন্ধ থাকতে পারে, তাই সার্ভার নিশ্চয়তা না দেওয়া পর্যন্ত বিক্রিটি ধরে রাখা হয়।',
    'pos.offlineCatalog': 'জমা রাখা পণ্যের তালিকা',
    'pos.offlineCatalogAge': ':min মিনিট আগে জমা হয়েছে',
    'pos.offlineCatalogPartial': 'আংশিক',
    'pos.offlineCatalogOtherTill': 'অন্য গুদামের জন্য',
    'pos.offlineCatalogEmpty': 'এখনো কিছু জমা নেই। সংযোগ থাকতে একবার তাজা করলে এরপর বিক্রি ছাড়াও দাম ও স্ক্যান চলবে।',
    'pos.offlineCatalogRefresh': 'তালিকা তাজা করুন',
    'pos.offlineStaleWarning': 'একটি বিক্রি আগের দিনে নেওয়া হয়েছে। লেজার বিক্রির দিন ধরে সেই দিনটি নয় যখন তা জমা হয়, তাই কেউ সিদ্ধান্ত না দেওয়া পর্যন্ত সেগুলো ফিরিয়ে দেওয়া হয় — আগে সেই দিনের দরজা বন্ধ করুন, বা ইচ্ছা করে আজকের দিনে যোগ করুন।',
    'pos.offlineQueue': 'এই ডিভাইসের সারি',
    'pos.offlineEmpty': 'কিছুই অপেক্ষমাণ নয়',
    'pos.offlineEmptyNote': 'এই ডিভাইসের প্রতিটি বিক্রি লেজার গ্রহণ করেছে।',
    'pos.offlineAttempts': ':n বার চেষ্টা',
    'pos.offlineStatus.queued': 'অপেক্ষমাণ',
    'pos.offlineStatus.in_flight': 'পাঠানো হচ্ছে',
    'pos.offlineStatus.attention': 'সমাধান দরকার',
    'pos.offlineStatus.posted': 'লেজারে যোগ হয়েছে',
    'pos.offlineTryAgain': 'আবার চেষ্টা',
    'pos.offlinePostToToday': 'আজকের দিনে যোগ করুন',
    'pos.offlineDiscard': 'ভুলে যান',
    'pos.offlineDiscardTitle': 'এই অফলাইন বিক্রিটি মুছে ফেলবেন?',
    'pos.offlineDiscardBody': 'লেজার এই বিক্রি দেখেনি। মুছে ফেললে শুধু এই ডিভাইস থেকে সরবে — কোনো হিসাব বাতিল হবে না, আর টাকা হাতে এসে থাকলে সেটি হাতেই মিলাতে হবে।',
    'pos.offlineOpenReceipt': 'রসিদ খুলুন',
    'pos.offlineBatchNote': 'এক পাঠানোয় সর্বোচ্চ :maxটি বিক্রি যায়; বাকিগুলো পরেরবার।',
    'pos.offlineSlip': 'স্লিপ',
    'pos.offlinePrintSlip': 'স্লিপ ছাপুন',
    'pos.offlineSlipShop': 'ইআরপি পাইলট — অফলাইন স্লিপ',
    'pos.offlineSlipLedger': 'লেজারে :no নম্বরে আছে',
    'pos.offlineSlipPending': 'লেজারে এখনো যায়নি — এই ডিভাইসে আছে',
    'pos.offlineSlipTotal': 'মোট',
    'pos.offlineSlipFooter': 'সংযোগ না থাকার সময় গ্রাহককে দেওয়া হয়েছে। বিক্রি জমা হওয়ার পর লেজার থেকেই আসল রসিদ ছাপা হবে।',
    'pos.offlineLedgerHeading': 'লেজার যা গ্রহণ করেছে',
    'pos.offlineLedgerIntro': 'মাসের শুরু থেকে অফলাইন সারি হয়ে যেসব বিক্রি লেজারে এসেছে, কাউন্টারের সময়টি লেজারের পাশে—ফার্ক দেখা যায়, অস্বীকার করা যায় না।',
    'pos.offlineColSale': 'বিক্রি',
    'pos.offlineColRung': 'নেওয়ার সময়',
    'pos.offlineColAmount': 'টাকা',
    'pos.offlineNoDrawer': 'দরজা নেই',
    'pos.offlineLedgerEmpty': 'এই মাসে অফলাইন সারি থেকে কোনো বিক্রি আসেনি।',
    'pos.offlineLateMinutes': 'কাউন্টারের :min মিনিট পরে',
    'pos.offlineLateHours': 'কাউন্টারের :hours ঘণ্টা পরে',
    'pos.offlineInstall': 'ক্যাশ ইনস্টল করুন',
    'pos.offlineInstallNote': 'ইনস্টল করলে ক্যাশ আলাদা উইন্ডোতে খোলে, সংযোগ ছাড়াও চলে, আর সারি এই ডিভাইসেই থাকে।',
    'pos.offlineInstallButton': 'এই ক্যাশ ইনস্টল করুন',
    'pos.offlineInstallManual': 'ব্রাউজার এখনো ইনস্টলের প্রস্তাব দেয়নি— ব্রাউজার মেনুর "Install app" / "Add to Home screen" ব্যবহার করুন।',
};

const dictionaries: Record<LocaleCode, Dict> = { en, bn };

export interface TranslateOptions {
    locale: LocaleCode | string;
}

/** Fill `{name}` placeholders from `params`, leaving an unknown token untouched. */
export function interpolate(value: string, params?: Record<string, string | number>): string {
    if (!params) return value;

    return value.replace(/\{(\w+)\}/g, (whole, key: string) =>
        Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : whole,
    );
}

/**
 * Resolve one key. Falls back en → key, so a missing translation is a visible English word, never a
 * blank cell or a crash.
 */
export function translate(
    key: string,
    options: TranslateOptions,
    params?: Record<string, string | number>,
): string {
    const locale = (options.locale === 'bn' ? 'bn' : 'en') as LocaleCode;
    const raw = dictionaries[locale][key] ?? dictionaries.en[key] ?? key;

    return interpolate(raw, params);
}

/** Which keys this language actually translates — handy for coverage checks and the settings page. */
export function coverage(code: LocaleCode): { total: number; translated: number } {
    const total = Object.keys(en).length;
    const translated = Object.keys(dictionaries[code]).filter((k) => k in en).length;

    return { total, translated };
}
