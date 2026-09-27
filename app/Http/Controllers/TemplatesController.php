<?php

namespace App\Http\Controllers;

use App\Contracts\WhatsAppClient;
use App\Models\MessageTemplate;
use App\Services\Messenger;
use Illuminate\Http\Request;

class TemplatesController extends Controller
{
    /** Sample values for the preview. */
    public const SAMPLE = [
        'name' => 'Aisha', 'field' => 'passport number', 'total' => '2', 'n' => '2',
        'details' => "Name: AISHA BELLO\nPassport: A09•••421\nBorn: 04 Jun 1991\nExpires: 14 Mar 2031\n\nTrip: KAN → JED · Mon 12 Oct",
        'route' => 'Kano → Jeddah', 'route_codes' => 'KAN → JED', 'date' => 'Mon 12 Oct', 'return' => '', 'travellers' => '1 traveller',
        'amount' => '₦685,000', 'hold' => '2 hours', 'time' => '12:45', 'pnr' => 'K7Q2LM', 'airline' => 'EgyptAir',
        'diff' => '₦38,000', 'alt_date' => 'Mon 26 Oct', 'expiry' => '14 Mar 2031', 'months' => '6', 'number' => 'A09•••421',
        'destination' => 'Jeddah', 'origin' => 'Kano', 'change' => '₦14,000 more than before.', 'refund_time' => 'a few working days',
        'value' => '17 Nov 1988', 'hint' => 'Type it as on your passport, for example: 04 06 1991', 'old_amount' => '₦240,000',
    ];

    public function index(Request $request)
    {
        $templates = MessageTemplate::query()->orderBy('position')->get();
        $current = $templates->firstWhere('key', $request->query('t')) ?? $templates->first();
        $lang = $request->query('lang') === 'ha' ? 'ha' : 'en';

        return view('templates.index', [
            'templates' => $templates,
            'current' => $current,
            'lang' => $lang,
            'preview' => $current ? Messenger::fill($current->body($lang), self::SAMPLE) : '',
            'previewButtons' => $current ? array_map(fn ($b) => Messenger::fill($b, self::SAMPLE), $current->buttons($lang)) : [],
        ]);
    }

    public function update(Request $request, MessageTemplate $template)
    {
        $data = $request->validate([
            'body_en' => 'required|string|max:1024',
            'body_ha' => 'nullable|string|max:1024',
            'buttons_en' => 'nullable|array|max:3',
            'buttons_en.*' => 'nullable|string|max:20',
            'buttons_ha' => 'nullable|array|max:3',
            'buttons_ha.*' => 'nullable|string|max:20',
            'meta_name' => 'nullable|string|max:120',
            'meta_status' => 'nullable|in:approved,in_review,rejected',
        ]);
        $data['body_ha'] = (string) ($data['body_ha'] ?? '');
        $template->update($data);

        return redirect()->route('templates.index', ['t' => $template->key, 'lang' => $request->input('lang', 'en')])->with('status', 'Template saved.');
    }

    public function test(Request $request, MessageTemplate $template, WhatsAppClient $wa)
    {
        $phone = config('safara.operator_phone');
        if (! $phone) {
            return back()->with('error', 'Set SAFARA_OPERATOR_PHONE to send test messages to your phone.');
        }
        $lang = $request->input('lang') === 'ha' ? 'ha' : 'en';
        try {
            $wa->sendText($phone, Messenger::fill($template->body($lang), self::SAMPLE));
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not send: '.$e->getMessage());
        }

        return back()->with('status', 'Test sent to +'.$phone.'.');
    }
}
