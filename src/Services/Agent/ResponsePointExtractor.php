<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

class ResponsePointExtractor
{
    /**
     * @return array{text_without_points:string,points:array<int,array{text:string,marker:string,index:int}>}
     */
    public function extract(string $content): array
    {
        $points = [];
        $textLines = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^([-*+]|[0-9]+[.)])\s+(.+)$/u', $trimmed, $matches) === 1) {
                $points[] = [
                    'text' => trim($matches[2]),
                    'marker' => $matches[1],
                    'index' => count($points) + 1,
                ];

                continue;
            }

            $textLines[] = rtrim($line);
        }

        return [
            'text_without_points' => trim(implode("\n", array_filter(
                $textLines,
                static fn (string $line): bool => trim($line) !== ''
            ))),
            'points' => $points,
        ];
    }
}
