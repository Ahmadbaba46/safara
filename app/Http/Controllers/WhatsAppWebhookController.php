<?php

namespace App\Http\Controllers;

use App\Integrations\WhatsApp\MetaWhatsApp;
use App\Jobs\ProcessInboundMessage;
use App\Models\Message;
use App\Services\InboundMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /** Meta calls this once when you register the webhook URL. */
    public function verify(Request $request)
    {
        $token = config('safara.meta.verify_token');
        if ($token && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request)
    {
        if (! MetaWhatsApp::validSignature($request->getContent(), $request->header('X-Hub-Signature-256'), config('safara.meta.app_secret'))) {
            Log::warning('Rejected WhatsApp webhook with a bad signature.');

            return response('Invalid signature', 401);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] ?? '' => $c['profile']['name'] ?? null]);

                foreach ($value['messages'] ?? [] as $m) {
                    $in = InboundMessage::fromMeta($m, $names[$m['from'] ?? ''] ?? null);
                    ProcessInboundMessage::dispatch(ProcessInboundMessage::fromInbound($in)->message);
                }

                foreach ($value['statuses'] ?? [] as $s) {
                    if (! empty($s['id']) && in_array($s['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true)) {
                        Message::query()->where('wa_id', $s['id'])->update(['status' => $s['status']]);
                    }
                }
            }
        }

        return response('ok');
    }
}
