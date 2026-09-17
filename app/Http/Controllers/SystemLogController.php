<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use SplFileObject;
use Throwable;

class SystemLogController extends Controller
{
    public function index(): Response
    {
        $path = storage_path('logs/laravel.log');

        return Inertia::render('System/Logs', [
            'log' => [
                'exists' => is_file($path),
                'size' => is_file($path) ? (filesize($path) ?: 0) : 0,
                'entries' => is_file($path) ? $this->readEntries($path) : [],
            ],
        ]);
    }

    private function readEntries(string $path): array
    {
        try {
            $contents = $this->tailBytes($path, 1024 * 1024);
            preg_match_all(
                '/^\[(?<date>[^\]]+)\]\s+(?<environment>[^.]+)\.(?<level>[A-Z]+):\s+(?<message>.*?)(?=^\[|\z)/ms',
                $contents,
                $matches,
                PREG_SET_ORDER,
            );

            return collect($matches)
                ->reverse()
                ->take(100)
                ->map(fn (array $match) => [
                    'date' => trim($match['date'] ?? ''),
                    'environment' => trim($match['environment'] ?? ''),
                    'level' => strtoupper(trim($match['level'] ?? 'INFO')),
                    'message' => mb_substr(trim($match['message'] ?? ''), 0, 8000),
                ])
                ->values()
                ->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function tailBytes(string $path, int $bytes): string
    {
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $offset = max(0, $size - $bytes);
            fseek($handle, $offset);

            $contents = stream_get_contents($handle);

            return is_string($contents) ? $contents : '';
        } finally {
            fclose($handle);
        }
    }
}
