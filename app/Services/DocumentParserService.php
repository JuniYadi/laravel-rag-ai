<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class DocumentParserService
{
    protected PdfParser $pdfParser;

    public function __construct()
    {
        $this->pdfParser = new PdfParser;
    }

    /**
     * Parse uploaded file and extract content
     */
    public function parse(UploadedFile $file): array
    {
        $fileType = strtolower($file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName();

        // Read content via get() which works for both local and S3-stored temp files
        $rawContent = $file->get();

        // Write to local temp file for parsers that need file path
        $tempFile = tempnam(sys_get_temp_dir(), 'rag_');
        file_put_contents($tempFile, $rawContent);

        try {
            $content = match ($fileType) {
                'pdf' => $this->parsePdf($tempFile),
                'txt' => $this->parseTxt($tempFile),
                'md', 'markdown' => $this->parseMarkdown($tempFile),
                default => throw new \InvalidArgumentException("Unsupported file type: {$fileType}"),
            };
        } finally {
            @unlink($tempFile);
        }

        $excerpt = $this->generateExcerpt($content);
        $storagePath = $this->storeFile($file, $rawContent);

        return [
            'title' => pathinfo($originalName, PATHINFO_FILENAME),
            'file_path' => $storagePath,
            'file_type' => $fileType,
            'content' => $content,
            'excerpt' => $excerpt,
        ];
    }

    /**
     * Parse PDF file and extract text
     */
    protected function parsePdf(string $filePath): string
    {
        $pdf = $this->pdfParser->parseFile($filePath);
        $text = $pdf->getText();

        return $this->normalizeText($text);
    }

    /**
     * Parse plain text file
     */
    protected function parseTxt(string $filePath): string
    {
        $content = file_get_contents($filePath);

        return $this->normalizeText($content);
    }

    /**
     * Parse markdown file
     */
    protected function parseMarkdown(string $filePath): string
    {
        $content = file_get_contents($filePath);

        // Strip markdown formatting for raw text extraction
        $content = preg_replace('/^#{1,6}\s+/m', '', $content); // Headers
        $content = preg_replace('/\*\*([^*]+)\*\*/', '$1', $content); // Bold
        $content = preg_replace('/\*([^*]+)\*/', '$1', $content); // Italic
        $content = preg_replace('/`([^`]+)`/', '$1', $content); // Inline code
        $content = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $content); // Links
        $content = preg_replace('/!\[([^\]]*)\]\([^)]+\)/', '', $content); // Images

        return $this->normalizeText($content);
    }

    /**
     * Normalize text by removing excessive whitespace
     */
    protected function normalizeText(string $text): string
    {
        // Convert to UTF-8 if needed
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Replace multiple spaces with single space
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Replace multiple newlines with double newline (paragraph separator)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim each line
        $lines = array_map('trim', explode("\n", $text));
        $text = implode("\n", $lines);

        return trim($text);
    }

    /**
     * Generate excerpt from content
     */
    protected function generateExcerpt(string $content, int $maxLength = 500): string
    {
        $excerpt = Str::limit($content, $maxLength);

        return $excerpt;
    }

    /**
     * Store uploaded file to disk
     */
    protected function storeFile(UploadedFile $file, string $content): string
    {
        $disk = config('services.document.disk', 'local');
        $path = config('services.document.storage_path', 'documents');

        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $fullPath = $path.'/'.$filename;

        Storage::disk($disk)->put($fullPath, $content);

        return $fullPath;
    }

    /**
     * Chunk content for large documents
     */
    public function chunk(string $content, int $chunkSize = 1000, int $overlap = 100): array
    {
        $chunks = [];
        $length = mb_strlen($content);
        $start = 0;

        while ($start < $length) {
            $end = min($start + $chunkSize, $length);

            // Try to break at sentence or paragraph boundary
            if ($end < $length) {
                $breakPoint = $this->findBreakPoint($content, $end, $overlap);
                $end = $breakPoint;
            }

            $chunks[] = mb_substr($content, $start, $end - $start);
            $start = $end - $overlap;

            if ($start < 0) {
                $start = 0;
            }
        }

        return array_values(array_filter($chunks));
    }

    /**
     * Find a good break point near the target end position
     */
    protected function findBreakPoint(string $content, int $targetEnd, int $overlap): int
    {
        // Look for paragraph breaks first (double newlines)
        $paragraphPos = mb_strrpos(mb_substr($content, 0, $targetEnd), "\n\n");

        if ($paragraphPos !== false && $paragraphPos > $targetEnd - $overlap) {
            return $paragraphPos + 2;
        }

        // Look for sentence breaks (period, question, exclamation followed by space)
        $sentencePattern = '/[.!?]\s+/u';
        $beforeTarget = mb_substr($content, 0, $targetEnd);

        if (preg_match_all($sentencePattern, $beforeTarget, $matches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($matches[0]);
            $sentencePos = $lastMatch[1];

            if ($sentencePos > $targetEnd - $overlap) {
                return $sentencePos + strlen($lastMatch[0]);
            }
        }

        // Look for line breaks
        $linePos = mb_strrpos(mb_substr($content, 0, $targetEnd), "\n");

        if ($linePos !== false && $linePos > $targetEnd - $overlap) {
            return $linePos + 1;
        }

        // Default to target end
        return $targetEnd;
    }
}
