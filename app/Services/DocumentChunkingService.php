<?php

namespace App\Services;

class DocumentChunkingService
{
    public function __construct(
        protected int $chunkSize = 1200,
        protected int $chunkOverlap = 200,
    ) {
        $this->chunkSize = max(200, (int) config('services.document.chunk_size', $this->chunkSize));
        $configuredOverlap = max(0, (int) config('services.document.chunk_overlap', $this->chunkOverlap));
        $this->chunkOverlap = min($configuredOverlap, $this->chunkSize - 1);
    }

    /**
     * @return array<int, array{chunk_index:int, content:string, excerpt:string, char_count:int, token_count:int, metadata:array<string, mixed>}>
     */
    public function chunk(string $content): array
    {
        $normalized = preg_replace("/\r\n?|\n/u", "\n", trim($content)) ?? '';

        if ($normalized === '') {
            return [];
        }

        $length = mb_strlen($normalized);

        if ($length <= $this->chunkSize) {
            return [$this->buildChunk(0, $normalized)];
        }

        $chunks = [];
        $step = max(1, $this->chunkSize - $this->chunkOverlap);
        $index = 0;
        $start = 0;

        while ($start < $length) {
            $window = mb_substr($normalized, $start, $this->chunkSize);

            if ($window === '') {
                break;
            }

            $boundary = $this->resolveBoundary($window);
            $chunkContent = trim(mb_substr($window, 0, $boundary));

            if ($chunkContent !== '') {
                $chunks[] = $this->buildChunk($index, $chunkContent);
                $index++;
            }

            if (mb_strlen($window) < $this->chunkSize) {
                break;
            }

            $start += $step;
        }

        if (empty($chunks)) {
            $chunks[] = $this->buildChunk(0, $normalized);
        }

        return $chunks;
    }

    protected function resolveBoundary(string $window): int
    {
        $windowLength = mb_strlen($window);

        if ($windowLength <= 80) {
            return $windowLength;
        }

        $tailStart = max(0, $windowLength - 300);
        $tail = mb_substr($window, $tailStart);

        $lastParagraph = mb_strrpos($tail, "\n\n");
        if ($lastParagraph !== false) {
            return $tailStart + $lastParagraph;
        }

        $lastLineBreak = mb_strrpos($tail, "\n");
        if ($lastLineBreak !== false) {
            return $tailStart + $lastLineBreak;
        }

        $lastSentence = max(
            (int) mb_strrpos($tail, '. '),
            (int) mb_strrpos($tail, '! '),
            (int) mb_strrpos($tail, '? ')
        );

        if ($lastSentence > 0) {
            return $tailStart + $lastSentence + 1;
        }

        $lastSpace = mb_strrpos($tail, ' ');

        return $lastSpace !== false ? $tailStart + $lastSpace : $windowLength;
    }

    /**
     * @return array{chunk_index:int, content:string, excerpt:string, char_count:int, token_count:int, metadata:array<string, mixed>}
     */
    protected function buildChunk(int $index, string $content): array
    {
        $charCount = mb_strlen($content);

        return [
            'chunk_index' => $index,
            'content' => $content,
            'excerpt' => mb_substr($content, 0, 220),
            'char_count' => $charCount,
            'token_count' => $this->estimateTokenCount($content),
            'metadata' => [
                'chunk_size' => $this->chunkSize,
                'chunk_overlap' => $this->chunkOverlap,
            ],
        ];
    }

    protected function estimateTokenCount(string $content): int
    {
        $charCount = mb_strlen($content);

        return max(1, (int) ceil($charCount / 4));
    }
}
