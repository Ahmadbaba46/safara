<?php

namespace App\Support;

/**
 * A tiny single-purpose PDF writer (text, lines, filled rectangles) so the
 * e-ticket needs no extra package. Coordinates are in points from the TOP-left
 * of an A4 page (595 × 842). Text uses the built-in Helvetica / Courier fonts
 * with Windows-1252 encoding — "₦" isn't in it, so write "NGN".
 */
final class PdfWriter
{
    private const W = 595.28;
    private const H = 841.89;

    /** @var string[] page content streams */
    private array $pages = [];

    private string $ops = '';

    private array $fonts = ['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Courier', 'F4' => 'Courier-Bold'];

    public function __construct(private string $title = 'Document')
    {
        $this->addPage();
    }

    public function addPage(): self
    {
        if ($this->ops !== '') {
            $this->pages[] = $this->ops;
        }
        $this->ops = '';

        return $this;
    }

    /** Hex colour "#0E5A47" → "r g b" */
    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    public function rect(float $x, float $y, float $w, float $h, string $fill): self
    {
        $this->ops .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", self::rgb($fill), $x, self::H - $y - $h, $w, $h);

        return $this;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#E3DED2', float $width = 1, bool $dashed = false): self
    {
        $dash = $dashed ? '[3 3] 0 d ' : '[] 0 d ';
        $this->ops .= sprintf("%s RG %.2F w %s%.2F %.2F m %.2F %.2F l S\n", self::rgb($color), $width, $dash, $x1, self::H - $y1, $x2, self::H - $y2);

        return $this;
    }

    /**
     * @param  string  $font  regular | bold | mono | monobold
     * @param  string  $align  left | right | center (x is the anchor)
     */
    public function text(float $x, float $y, string $text, float $size = 11, string $font = 'regular', string $color = '#17191C', string $align = 'left'): self
    {
        $key = ['regular' => 'F1', 'bold' => 'F2', 'mono' => 'F3', 'monobold' => 'F4'][$font] ?? 'F1';
        $encoded = self::encode($text);
        if ($align !== 'left') {
            $width = self::width($encoded, $size, $key);
            $x -= $align === 'right' ? $width : $width / 2;
        }
        $this->ops .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $key, $size, self::rgb($color), $x, self::H - $y, self::escape($encoded));

        return $this;
    }

    public function output(): string
    {
        $pages = $this->pages;
        if ($this->ops !== '' || ! $pages) {
            $pages[] = $this->ops;
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontObjs = [];
        $n = 3;
        foreach ($this->fonts as $key => $base) {
            $fontObjs[$key] = $n;
            $objects[$n++] = "<< /Type /Font /Subtype /Type1 /BaseFont /$base /Encoding /WinAnsiEncoding >>";
        }
        $fontRefs = implode(' ', array_map(fn ($k, $i) => "/$k $i 0 R", array_keys($fontObjs), $fontObjs));

        $kids = [];
        foreach ($pages as $content) {
            $streamId = $n++;
            $pageId = $n++;
            $objects[$streamId] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << %s >> >> /Contents %d 0 R >>', self::W, self::H, $fontRefs, $streamId);
            $kids[] = "$pageId 0 R";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        $infoId = $n++;
        $objects[$infoId] = '<< /Title ('.self::escape(self::encode($this->title)).') /Producer (Safara) >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";

        return $pdf;
    }

    private static function encode(string $text): string
    {
        $text = str_replace(['₦', '→', '—', '–'], ['NGN ', '>', '-', '-'], $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    /** Approximate width — exact for Courier, averaged for Helvetica. */
    private static function width(string $encoded, float $size, string $font): float
    {
        $len = strlen($encoded);
        if ($font === 'F3' || $font === 'F4') {
            return $len * 0.6 * $size;
        }
        $narrow = preg_match_all('/[il.,:;\'|!ftjI1 ]/', $encoded);
        $wide = preg_match_all('/[MWmw@%]/', $encoded);
        $upper = preg_match_all('/[A-Z]/', $encoded);
        $factor = $font === 'F2' ? 0.58 : 0.54;

        return ($len * $factor + $upper * 0.1 - $narrow * 0.26 + $wide * 0.25) * $size;
    }
}
