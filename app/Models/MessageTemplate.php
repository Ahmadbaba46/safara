<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['buttons_en' => 'array', 'buttons_ha' => 'array'];
    }

    public function body(string $lang): string
    {
        return $lang === 'ha' && trim((string) $this->body_ha) !== '' ? $this->body_ha : $this->body_en;
    }

    /** @return string[] */
    public function buttons(string $lang): array
    {
        $clean = fn ($list) => array_values(array_filter($list ?? [], fn ($x) => trim((string) $x) !== ''));
        $ha = $clean($this->buttons_ha);

        return $lang === 'ha' && count($ha) === count($clean($this->buttons_en)) ? $ha : $clean($this->buttons_en);
    }

    /** Placeholders like {name} used in either language. */
    public function variables(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->body_en.' '.$this->body_ha.' '.implode(' ', $this->buttons_en ?? []), $m);

        return array_values(array_unique($m[1]));
    }

    public function kindLabel(): string
    {
        if ($this->kind !== 'template') {
            return 'Session reply';
        }

        return match ($this->meta_status) {
            'approved' => 'Template · approved',
            'rejected' => 'Template · rejected',
            default => 'Template · in review',
        };
    }

    public function kindTone(): string
    {
        return match (true) {
            $this->kind !== 'template' => 'mute',
            $this->meta_status === 'approved' => 'ok',
            $this->meta_status === 'rejected' => 'bad',
            default => 'warn',
        };
    }
}
