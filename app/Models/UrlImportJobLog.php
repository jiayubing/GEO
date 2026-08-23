<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrlImportJobLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'url_import_job_logs';

    protected $fillable = [
        'job_id',
        'step',
        'level',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
            'step' => 'string',
        ];
    }

    public function setMessageAttribute(?string $message): void
    {
        $this->attributes['message'] = self::redactUrlQuery((string) $message);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(UrlImportJob::class, 'job_id');
    }

    private static function redactUrlQuery(string $message): string
    {
        return preg_replace_callback(
            '#https?://[^\s<>()]+#i',
            static function (array $match): string {
                $url = rtrim((string) $match[0], '.,;:!?');
                $suffix = substr((string) $match[0], strlen($url));
                $parts = parse_url($url);
                if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                    return $match[0];
                }

                $safe = strtolower((string) $parts['scheme']).'://'.(string) $parts['host'];
                if (isset($parts['port'])) {
                    $safe .= ':'.(int) $parts['port'];
                }
                $safe .= (string) ($parts['path'] ?? '');

                return $safe.$suffix;
            },
            $message,
        ) ?? $message;
    }
}
