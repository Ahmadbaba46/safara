<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The installable app: manifest, icons, offline page and a service worker that never caches private data. */
class PwaTest extends TestCase
{
    private function manifest(): array
    {
        return json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_manifest_has_what_browsers_need_to_offer_installation(): void
    {
        $m = $this->manifest();

        $this->assertSame('standalone', $m['display']);
        $this->assertNotEmpty($m['name']);
        $this->assertNotEmpty($m['short_name']);
        $this->assertSame('/', $m['scope']);
        $this->assertStringStartsWith('/', $m['start_url']);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $m['theme_color']);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $m['background_color']);

        $any = collect($m['icons'])->where('type', 'image/png')->where('purpose', 'any')->pluck('sizes');
        $masked = collect($m['icons'])->where('purpose', 'maskable')->pluck('sizes');
        $this->assertTrue($any->contains('192x192') && $any->contains('512x512'), 'needs 192 and 512 icons');
        $this->assertTrue($masked->contains('512x512'), 'needs a maskable 512 icon for Android');
    }

    public function test_every_icon_exists_at_the_size_it_claims(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            // Apache on Debian aliases /icons/ to its own folder, so nothing of ours may live there.
            $this->assertStringStartsNotWith('/icons/', $icon['src']);
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);

            if ($icon['type'] === 'image/png') {
                [$w, $h, $type] = getimagesize($path);
                $this->assertSame(IMAGETYPE_PNG, $type, $icon['src']);
                $this->assertSame($icon['sizes'], "{$w}x{$h}", $icon['src']);
            }
        }

        [$w, $h] = getimagesize(public_path('brand/apple-touch-icon.png'));
        $this->assertSame([180, 180], [$w, $h]);
        $this->assertSame("\x00\x00\x01\x00", substr(file_get_contents(public_path('favicon.ico')), 0, 4), 'favicon.ico is an ICO file');
    }

    public function test_the_chat_page_wires_up_the_manifest_icons_and_service_worker(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('manifest.webmanifest', $html);
        $this->assertStringContainsString('brand/apple-touch-icon.png', $html);
        $this->assertStringContainsString('serviceWorker.register', $html);
        $this->assertStringContainsString('id="install"', $html);
        $this->assertStringNotContainsString('/icons/', $html);
    }

    public function test_the_offline_page_is_self_contained(): void
    {
        $html = file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString("You're offline", $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html, 'must not depend on a file that may not be cached');
        $this->assertStringContainsString('<style>', $html);
    }

    public function test_the_service_worker_only_caches_static_files_and_never_private_pages(): void
    {
        $js = file_get_contents(public_path('sw.js'));

        // Everything it precaches must exist.
        $this->assertMatchesRegularExpression('/const SHELL = \[(.*?)\];/s', $js);
        preg_match('/const SHELL = \[(.*?)\];/s', $js, $shell);
        preg_match_all("/'([^']+)'/", $shell[1], $paths);
        $this->assertContains('/offline.html', $paths[1]);
        foreach ($paths[1] as $path) {
            $this->assertFileExists(public_path(ltrim($path, '/')), $path);
        }

        // What it will store: translate its JS pattern and test real URLs against it.
        $this->assertSame(1, preg_match('#const STATIC = /(.+)/;#', $js, $re));
        $static = fn (string $path) => (bool) preg_match('~'.str_replace('~', '\~', $re[1]).'~', $path);

        foreach (['/css/app.css', '/css/safara.css', '/brand/icon.svg', '/brand/icon-192.png', '/manifest.webmanifest', '/offline.html'] as $ok) {
            $this->assertTrue($static($ok), "$ok should be cacheable");
        }
        foreach (['/', '/app/messages', '/app/send', '/app/documents/1', '/pay/abc123', '/pay/abc123/done', '/desk', '/login', '/bookings/SF-2401', '/bookings/SF-2401/ticket', '/clients', '/webhooks/payments'] as $private) {
            $this->assertFalse($static($private), "$private must never be cached");
        }

        // Only GET requests, and only this site's own files, are ever touched.
        $this->assertStringContainsString("req.method !== 'GET'", $js);
        $this->assertStringContainsString('url.origin !== self.location.origin', $js);
    }
}
