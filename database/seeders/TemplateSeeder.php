<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Everything the bot says. Operators edit these on the Message templates
 * screen; re-running this seeder only adds templates that are missing.
 *
 * The Hausa was drafted by machine — have a fluent speaker review it before
 * going live.
 */
class TemplateSeeder extends Seeder
{
    public const TEMPLATES = [
        // ---- Starting ----------------------------------------------------------
        ['ask_trip', 'Welcome & trip question', 'Starting', 'session',
            "Salam, welcome to {brand}. Where are you flying from and to, on what date, and how many people?\nFor example: Kano to Jeddah, 12 October, 1 person.",
            "Salam, barka da zuwa {brand}. Daga ina zuwa ina za ku yi tafiya, a wace rana, kuma mutum nawa?\nMisali: Kano zuwa Jeddah, 12 October, mutum 1."],
        ['ask_destination', 'Ask destination', 'Starting', 'session', 'Where would you like to fly to?', 'Ina za ku je?'],
        ['ask_origin', 'Ask departure city', 'Starting', 'session', 'And where will you be flying from to {destination}?', 'Daga wane gari za ku tashi zuwa {destination}?'],
        ['ask_date', 'Ask travel date', 'Starting', 'session', 'What date would you like to travel?', 'A wace rana kuke son tafiya?'],
        ['ask_travellers', 'Ask how many travellers', 'Starting', 'session', 'How many people are travelling, including you?', 'Mutum nawa ne za su yi tafiya, har da ku?'],
        ['language_set', 'Language changed', 'Starting', 'session', "Okay, I'll reply in English.", 'To, zan riƙa amsawa da Hausa.'],
        ['youre_welcome', 'After a finished booking', 'Starting', 'session', "You're welcome! Reply HELP any time if you need anything.", 'Babu komai! Ku rubuta HELP a duk lokacin da kuke buƙatar taimako.'],

        // ---- Passport ----------------------------------------------------------
        ['ask_passport', 'Ask for passport', 'Passport', 'session',
            "Please send a clear photo of your passport's photo page.",
            'Don Allah a turo hoto mai kyau na shafin fasfo mai ɗauke da hoto.'],
        ['ask_passport_group', 'Ask for passports (group)', 'Passport', 'session',
            'Please send a clear photo of the passport photo page for each of the {total} travellers, one at a time. Start with yours.',
            'Don Allah a turo hoton shafin fasfo mai ɗauke da hoto na kowane ɗaya daga cikin matafiya {total}, ɗaya bayan ɗaya. A fara da naku.'],
        ['ask_passport_next', 'Next traveller’s passport', 'Passport', 'session',
            'Thanks. Now send the passport photo page for traveller {n} of {total}.',
            'Na gode. Yanzu a turo shafin fasfo na matafiyi na {n} cikin {total}.'],
        ['ask_passport_again', 'Passport reminder', 'Passport', 'session',
            "Please send a photo of the passport's photo page, the page with the picture.",
            'Don Allah a turo hoton shafin fasfo, shafin da ke da hoto.'],
        ['saved_passport', 'Offer saved passport', 'Passport', 'session',
            'Welcome back, {name}. Shall I use your saved passport ({number}, expires {expiry})?',
            'Barka da dawowa, {name}. In yi amfani da fasfon ku da aka ajiye ({number}, zai ƙare {expiry})?',
            ['Use saved passport', 'Send a new one'], ['Yi amfani da shi', 'Zan turo sabo']],
        ['photo_unclear', 'Photo unclear', 'Passport', 'session',
            "The photo is a little blurry, so I couldn't read the {field}. Lay the passport flat in good light, with all four corners in the frame, and send it again.",
            'Hoton bai fito sosai ba, don haka ban iya karanta {field} ba. A shimfiɗa fasfon a wuri mai haske, kusurwoyi huɗu duka su bayyana, sannan a sake turowa.'],
        ['passport_expiring', 'Passport expires soon', 'Passport', 'session',
            "Heads up: this passport expires on {expiry}, less than {months} months after your trip. Many countries and airlines won't accept that. You can send a different passport, or reply HELP and our team will check.",
            "Ku lura: wannan fasfo zai ƙare a ranar {expiry}, ƙasa da wata {months} bayan tafiyarku. Ƙasashe da kamfanonin jirgi da yawa ba sa karɓa. Kuna iya turo wani fasfo, ko ku rubuta HELP don ma'aikatanmu su duba."],

        // ---- Confirming ---------------------------------------------------------
        ['confirm_details', 'Confirm details', 'Confirming details', 'session',
            "Thanks, {name}. Here's what I read:\n\n{details}\n\nIs everything correct?",
            "Na gode, {name}. Ga abin da na karanta:\n\n{details}\n\nKomai daidai ne?",
            ['Yes, correct', 'Fix something'], ['Eh, daidai ne', 'Gyara wani abu']],
        ['fix_which', 'Which detail to fix', 'Confirming details', 'session', 'No problem. Which detail is wrong?', 'Babu matsala. Wane bayani ne ba daidai ba?'],
        ['fix_which_traveller', 'Which traveller to fix', 'Confirming details', 'session', 'No problem. Whose details need fixing?', "Babu matsala. Bayanan wa ake buƙatar gyarawa?"],
        ['fix_prompt_name', 'Ask for corrected name', 'Confirming details', 'session',
            'Type the name exactly as on the passport, surname first. For example: BELLO AISHA',
            'Rubuta sunan kamar yadda yake a fasfo, sunan iyali da farko. Misali: BELLO AISHA'],
        ['fix_prompt_dob', 'Ask for corrected birth date', 'Confirming details', 'session',
            'Type it exactly as on your passport, day, month, year. For example: 04 06 1991',
            'Rubuta kamar yadda yake a fasfo: rana, wata, shekara. Misali: 04 06 1991'],
        ['fix_prompt_number', 'Ask for corrected passport number', 'Confirming details', 'session',
            'Type the passport number exactly as printed. For example: A09123421',
            'Rubuta lambar fasfo daidai yadda aka buga ta. Misali: A09123421'],
        ['fix_prompt_expiry', 'Ask for corrected expiry', 'Confirming details', 'session',
            'Type the expiry date as on the passport, day, month, year. For example: 14 03 2031',
            'Rubuta ranar ƙarewa kamar yadda take a fasfo: rana, wata, shekara. Misali: 14 03 2031'],
        ['fix_prompt_trip', 'Ask for corrected trip', 'Confirming details', 'session',
            'Tell me the trip again, for example: Kano to Jeddah, 12 October, 1 person.',
            'Sake faɗa min tafiyar, misali: Kano zuwa Jeddah, 12 October, mutum 1.'],
        ['fix_invalid', 'Correction not understood', 'Confirming details', 'session', "Sorry, I couldn't read that. {hint}", 'Yi haƙuri, ban gane ba. {hint}'],
        ['fixed', 'Detail corrected', 'Confirming details', 'session',
            'Updated. {field}: {value}. Is everything correct now?',
            'An gyara. {field}: {value}. Komai daidai ne yanzu?',
            ['Yes, correct', 'Fix something'], ['Eh, daidai ne', 'Gyara wani abu']],

        // ---- Quote & payment -----------------------------------------------------
        ['searching', 'Checking fares', 'Quote & payment', 'session', 'Thanks, {name}. Checking fares for you now…', 'Na gode, {name}. Ina duba farashi yanzu…'],
        ['quote', 'Quote', 'Quote & payment', 'session',
            "Your quote: {route}, {date}, {travellers}.\nTotal {amount}, all-in. Price held for {hold}.\nYour ticket is issued as soon as payment is confirmed.",
            "Farashinku: {route}, {date}.\nJimilla {amount}, komai a ciki. Farashin zai tsaya na {hold}.\nZa a fitar da tikitinku da zarar an tabbatar da biyan kuɗi.",
            ['Pay {amount}'], ['Biya {amount}']],
        ['pay_reminder', 'Pay link reminder', 'Quote & payment', 'session',
            'Your price of {amount} is held until {time}. Tap below to pay.',
            'An tsayar da farashinku na {amount} har zuwa ƙarfe {time}. Danna ƙasa don biya.',
            ['Pay {amount}'], ['Biya {amount}']],
        ['hold_expired', 'Price hold expired', 'Quote & payment', 'template',
            "Your price hold ended {time}. Fares change, so I checked again: {route} is now {amount}. {change}\nReply with a different date to check another day.",
            "Lokacin tsayar da farashinku ya ƙare. Farashi yana canzawa, don haka na sake dubawa: {route} yanzu {amount} ne.\nKu turo wata rana idan kuna son a duba wata ranar.",
            ['Pay {amount}'], ['Biya {amount}']],
        ['no_flights', 'No flights found', 'Quote & payment', 'session',
            "Sorry, I couldn't find seats for {route} on that date. Reply with another date and I'll check again.",
            'Yi haƙuri, ban sami kujera ba don {route} a wannan ranar. Ku turo wata rana, zan sake dubawa.'],
        ['search_delay', 'Search problem', 'Quote & payment', 'session',
            "Sorry, I'm having trouble checking fares right now. Our team has been told and will get back to you here shortly.",
            "Yi haƙuri, ana samun matsala wajen duba farashi yanzu. An sanar da ma'aikatanmu, za su dawo gare ku nan ba da daɗewa ba."],
        ['payment_received', 'Payment received', 'Quote & payment', 'session', 'Payment received · {amount}. Booking your seat now.', 'An karɓi kuɗi · {amount}. Ana ajiye muku kujera yanzu.'],
        ['paid_wait', 'Paid, still booking', 'Quote & payment', 'session',
            "Your payment is confirmed and we're booking your seat. Your ticket will arrive here soon.",
            'An tabbatar da biyan kuɗinku, muna ajiye muku kujera. Tikitinku zai iso nan ba da daɗewa ba.'],

        // ---- Ticketed ------------------------------------------------------------
        ['ticket_issued', 'Ticket issued', 'Ticketed', 'session',
            "You're booked, {name}. Booking ref {pnr}. Your e-ticket is attached.",
            'An kammala, {name}. Lambar booking ɗinku {pnr}. Tikitinku yana haɗe.'],
        ['help_footer', 'After the ticket', 'Ticketed', 'session',
            'Safe journey. Reply HELP any time, or CHANGE to ask about changing your flight.',
            'Allah ya kiyaye hanya. Ku rubuta HELP a kowane lokaci, ko CHANGE don neman canza jirginku.'],
        ['save_passport_ask', 'Ask to keep passport', 'Ticketed', 'session',
            "Would you like me to keep your passport details for your next booking? They're stored encrypted, and you can ask us to delete them any time.",
            'Kuna so in ajiye bayanan fasfonku don booking na gaba? Ana ajiye su a ɓoye, kuma kuna iya cewa a goge su a kowane lokaci.',
            ['Yes, save it', 'No thanks'], ['Eh, a ajiye', "A'a, na gode"]],
        ['save_passport_yes', 'Passport kept', 'Ticketed', 'session', "Done. Next time you won't need to send a photo.", 'An gama. Nan gaba ba sai kun turo hoto ba.'],
        ['save_passport_no', 'Passport not kept', 'Ticketed', 'session', "No problem. We'll delete your passport details after your trip.", 'Babu matsala. Za mu goge bayanan fasfonku bayan tafiyarku.'],

        // ---- Fare changes -------------------------------------------------------------
        ['fare_changed', 'Fare rose after payment', 'Fare changes', 'template',
            "Sorry, {name}, the fare went up by {diff} while your payment was processing. Choose what you'd like:",
            'Ayi haƙuri, {name}, farashin ya ƙaru da {diff} yayin da ake sarrafa biyan kuɗinku. Zaɓi abin da kuke so:',
            ['Pay {diff} more', 'Fly {alt_date}', 'Full refund'], ['Ƙara {diff}', 'Tashi {alt_date}', 'Maido min kuɗi']],
        ['fare_pay_diff', 'Pay the difference', 'Fare changes', 'template',
            'Sorry, {name}, the fare went up by {diff} while your payment was processing. Pay the difference to keep {date}.',
            'Ayi haƙuri, {name}, farashin ya ƙaru da {diff} yayin da ake sarrafa biyan kuɗinku. Ku biya bambancin don riƙe ranar {date}.',
            ['Pay {diff}'], ['Biya {diff}']],
        ['fare_alt_done', 'Moved to another date', 'Fare changes', 'session',
            'Done. Booking {travellers} on {date}, {route}. Your ticket will arrive here in a few minutes.',
            "An gama. Ana yin booking a ranar {date}, {route}. Tikitin zai iso nan cikin 'yan mintuna."],
        ['refund_sent', 'Refund sent', 'Fare changes', 'template',
            "Sorry, {name}, we couldn't get your seats at the price you paid, so we've refunded {amount}. It should reach you within {refund_time}.",
            'Ayi haƙuri, {name}, ba mu sami kujeru a farashin da kuka biya ba, don haka mun maido muku {amount}. Zai iso gare ku cikin {refund_time}.'],
        ['choose_option', 'Choose an option', 'Fare changes', 'session',
            'Please tap one of the options above so we can finish your booking.',
            'Don Allah ku danna ɗaya daga cikin zaɓuɓɓukan da ke sama don mu kammala booking ɗinku.'],

        // ---- Help & manual ------------------------------------------------------------
        ['handover', 'Handed to a person', 'Help & manual quotes', 'session',
            'Thanks. A member of our team will reply here shortly.',
            "Na gode. Ɗaya daga cikin ma'aikatanmu zai amsa muku nan ba da daɗewa ba."],
        ['change_request', 'Change request', 'Help & manual quotes', 'session',
            "I've passed your request to our team. They'll reply here shortly.",
            "Na isar da buƙatarku ga ma'aikatanmu. Za su amsa muku nan ba da daɗewa ba."],
        ['manual_start', 'Manual quote start', 'Help & manual quotes', 'template',
            "Salam from {brand}. We're arranging your flight: {route}, {date}. Please send a clear photo of the passport photo page for each traveller ({total}), one at a time.",
            'Salam daga {brand}. Muna shirya muku tafiya: {route}, {date}. Don Allah a turo hoto mai kyau na shafin fasfo na kowane matafiyi ({total}), ɗaya bayan ɗaya.'],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $i => $t) {
            [$key, $name, $stage, $kind, $en, $ha] = $t;
            MessageTemplate::query()->firstOrCreate(['key' => $key], [
                'name' => $name,
                'stage' => $stage,
                'position' => $i + 1,
                'kind' => $kind,
                'meta_status' => $kind === 'template' ? 'in_review' : null,
                'body_en' => $en,
                'body_ha' => $ha,
                'buttons_en' => $t[6] ?? null,
                'buttons_ha' => $t[7] ?? null,
            ]);
        }
    }
}
