<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit(Settings $settings)
    {
        $s = $settings->all();
        $example = 646200;

        return view('settings.edit', [
            's' => $s,
            'example' => $example,
            'exampleQuote' => $settings->pricing()->quote($example),
            'gatewayName' => app(\App\Contracts\PaymentGateway::class)->label(),
        ]);
    }

    public function update(Request $request, Settings $settings)
    {
        $data = $request->validate([
            'markup_percent' => 'required|numeric|min:0|max:100',
            'min_margin' => 'required|integer|min:0',
            'round_to' => 'required|in:0,500,1000,5000',
            'hold_minutes' => 'required|integer|in:30,60,120,240',
            'payment_methods' => 'required|array|min:1',
            'payment_methods.*' => 'in:card,transfer,ussd',
            'absorb_limit' => 'required|integer|min:0',
            'night_start' => 'required|date_format:H:i',
            'night_end' => 'required|date_format:H:i',
            'retention' => 'required|in:expiry,after_travel,after_ticket',
            'refund_time' => 'required|string|max:60',
            'fx_usd' => 'required|numeric|min:1',
            'fx_gbp' => 'required|numeric|min:1',
            'fx_eur' => 'required|numeric|min:1',
        ]);

        $settings->set([
            'markup_percent' => (float) $data['markup_percent'],
            'min_margin' => (int) $data['min_margin'],
            'round_to' => (int) $data['round_to'],
            'hold_minutes' => (int) $data['hold_minutes'],
            'payment_methods' => array_values($data['payment_methods']),
            'auto_issue' => $request->boolean('auto_issue'),
            'absorb' => $request->boolean('absorb'),
            'absorb_limit' => (int) $data['absorb_limit'],
            'alert_operator' => $request->boolean('alert_operator'),
            'night_pause' => $request->boolean('night_pause'),
            'night_start' => $data['night_start'],
            'night_end' => $data['night_end'],
            'retention' => $data['retention'],
            'refund_time' => $data['refund_time'],
            'fx_rates' => ['NGN' => 1, 'USD' => (float) $data['fx_usd'], 'GBP' => (float) $data['fx_gbp'], 'EUR' => (float) $data['fx_eur']],
        ]);

        return back()->with('status', 'Settings saved.');
    }
}
