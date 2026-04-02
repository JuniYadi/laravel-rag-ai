<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RagService
{
    protected VectorSearchService $vectorSearchService;

    protected EmbeddingService $embeddingService;

    protected string $llmProvider;

    protected string $llmModel;

    protected string $llmBaseUrl;

    protected string $openaiApiKey;

    protected int $maxChunks;

    protected int $maxContextChars;

    protected int $maxContextTokens;

    protected float $minSimilarity;

    protected float $lowConfidenceSimilarity;

    public function __construct(
        VectorSearchService $vectorSearchService,
        EmbeddingService $embeddingService
    ) {
        $this->vectorSearchService = $vectorSearchService;
        $this->embeddingService = $embeddingService;

        $this->llmProvider = mb_strtolower((string) config('services.llm.provider', 'openai'));
        $this->llmModel = (string) config('services.llm.model', 'gpt-4o-mini');
        $this->llmBaseUrl = rtrim((string) config('services.llm.base_url', 'https://api.openai.com/v1'), '/');
        $this->openaiApiKey = $this->resolveOpenAiApiKey();

        $this->maxChunks = max(1, (int) config('services.rag.max_chunks', 5));
        $this->maxContextChars = max(500, (int) config('services.rag.max_context_chars', 12000));
        $this->maxContextTokens = max(128, (int) config('services.rag.max_context_tokens', 3000));
        $this->minSimilarity = max(0.0, min(1.0, (float) config('services.rag.min_similarity', 0.7)));
        $this->lowConfidenceSimilarity = max(0.0, min(1.0, (float) config('services.rag.low_confidence_similarity', 0.55)));

        if ($this->llmModel === '') {
            throw new RuntimeException('LLM model is not configured. Set services.llm.model / LLM_MODEL.');
        }
    }

    protected function resolveOpenAiApiKey(): string
    {
        $primaryApiKey = (string) config('services.llm.api_key', '');

        if ($primaryApiKey !== '') {
            return $primaryApiKey;
        }

        $fallbackApiKey = (string) config('services.openai.api_key', env('OPENAI_API_KEY', ''));

        if ($fallbackApiKey === '') {
            throw new RuntimeException('Missing LLM API key. Set services.llm.api_key or OPENAI_API_KEY.');
        }

        return $fallbackApiKey;
    }

    /**
     * Query the RAG system with a question.
     */
    public function query(string $question, int $maxDocuments = 5): array
    {
        $documents = $this->retrieveDocuments($question, $maxDocuments, $this->currentUserId());

        $topSimilarity = (float) ($documents->max(fn ($doc) => (float) ($doc->rag_similarity ?? 0.0)) ?? 0.0);
        $isLowConfidence = $documents->isNotEmpty() && $topSimilarity < $this->lowConfidenceSimilarity;

        $answer = $this->generateAnswer($question, $documents, $isLowConfidence);

        return [
            'question' => $question,
            'answer' => $answer,
            'sources' => $documents->values()->map(function ($doc, int $index) {
                $sourceRef = 'S'.($index + 1);

                return [
                    'id' => $doc->id,
                    'source_ref' => $sourceRef,
                    'document_id' => $doc->document_id ?? $doc->id,
                    'title' => $doc->document->title ?? $doc->title,
                    'excerpt' => $doc->excerpt,
                    'file_type' => $doc->document->file_type ?? $doc->file_type,
                    'chunk_index' => $doc->chunk_index ?? null,
                    'similarity' => round((float) ($doc->rag_similarity ?? 0.0), 4),
                    'char_count' => (int) ($doc->char_count ?? mb_strlen((string) ($doc->content ?? ''))),
                    'token_count' => (int) ($doc->token_count ?? $this->estimateTokenCount((string) ($doc->content ?? ''))),
                    'truncated' => (bool) ($doc->rag_truncated ?? false),
                ];
            }),
            'document_count' => $documents->count(),
            'retrieval' => [
                'top_similarity' => round($topSimilarity, 4),
                'is_low_confidence' => $isLowConfidence,
                'used_chunks' => $documents->count(),
                'budget' => [
                    'max_chunks' => $this->maxChunks,
                    'max_context_chars' => $this->maxContextChars,
                    'max_context_tokens' => $this->maxContextTokens,
                    'min_similarity' => $this->minSimilarity,
                    'low_confidence_similarity' => $this->lowConfidenceSimilarity,
                ],
            ],
        ];
    }

    /**
     * Retrieve relevant chunks for the query with confidence filtering and prompt budgeting.
     */
    public function retrieveDocuments(string $query, int $limit = 5, ?int $userId = null): Collection
    {
        $queryEmbedding = $this->embeddingService->generateEmbedding($query);
        $effectiveLimit = max(1, min($limit, $this->maxChunks));
        $candidateLimit = max($effectiveLimit * 3, $effectiveLimit);
        $resolvedUserId = $userId ?? $this->currentUserId();

        $candidates = $this->vectorSearchService->searchByEmbedding(
            $queryEmbedding,
            $candidateLimit,
            $this->minSimilarity,
            $resolvedUserId
        );

        $scored = $candidates
            ->map(fn ($chunk) => $this->withSimilarity($chunk, $queryEmbedding))
            ->filter(fn ($chunk) => (float) ($chunk->rag_similarity ?? 0.0) >= $this->minSimilarity)
            ->sortByDesc(fn ($chunk) => (float) ($chunk->rag_similarity ?? 0.0))
            ->values();

        return $this->applyContextBudget($scored, $effectiveLimit);
    }

    /**
     * Generate answer using LLM with retrieved context.
     */
    protected function generateAnswer(string $question, Collection $documents, bool $isLowConfidence = false): string
    {
        if ($documents->isEmpty()) {
            return "I don't have any relevant document chunks to answer your question. Please upload documents that cover this topic first.";
        }

        $context = $this->buildContext($documents);
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($question, $context, $isLowConfidence);

        return $this->callLlm($systemPrompt, $userPrompt);
    }

    /**
     * Build context from retrieved chunks.
     */
    protected function buildContext(Collection $documents): string
    {
        $contextParts = [];

        foreach ($documents->values() as $index => $document) {
            $sourceRef = 'S'.($index + 1);
            $title = $document->document->title ?? $document->title;
            $chunkLabel = isset($document->chunk_index) ? '#'.((int) $document->chunk_index + 1) : '#1';
            $similarity = number_format((float) ($document->rag_similarity ?? 0.0), 3);

            $contextParts[] = "[{$sourceRef}] {$title} (chunk {$chunkLabel}, similarity {$similarity})\n{$document->content}";
        }

        return implode("\n\n---\n\n", $contextParts);
    }

    /**
     * Build system prompt for grounded RAG.
     */
    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a helpful AI assistant that answers questions based ONLY on the provided context chunks.
If the context does not contain enough information, clearly say what is missing.
Always cite sources inline using [S1], [S2], etc. matching the context source labels.
Never fabricate citations or facts outside the provided context.
Be concise but complete.
PROMPT;
    }

    /**
     * Build user prompt with question and structured chunk context.
     */
    protected function buildUserPrompt(string $question, string $context, bool $isLowConfidence = false): string
    {
        $confidenceInstruction = $isLowConfidence
            ? "\n- Retrieved chunks have low confidence; include a short uncertainty note before your answer."
            : '';

        return <<<PROMPT
## Question
{$question}

## Context Chunks
{$context}

## Instructions
- Answer strictly from the context chunks.
- Cite supporting chunks inline using [S#] notation.
- If context is partial, explain what is known and what is missing.{$confidenceInstruction}
PROMPT;
    }

    /**
     * Call LLM API.
     */
    protected function callLlm(string $systemPrompt, string $userPrompt): string
    {
        return match ($this->llmProvider) {
            'openai', 'openai-compatible' => $this->callOpenAi($systemPrompt, $userPrompt),
            default => throw new RuntimeException("Unsupported LLM provider [{$this->llmProvider}]. Supported providers: openai, openai-compatible."),
        };
    }

    /**
     * Call OpenAI API.
     */
    protected function callOpenAi(string $systemPrompt, string $userPrompt): string
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->openaiApiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($this->llmBaseUrl.'/chat/completions', [
                'model' => $this->llmModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 1000,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('LLM API call failed: '.$response->body());
        }

        $data = $response->json();

        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Stream response preparation payload (for real-time UI updates).
     */
    public function queryWithStream(string $question, int $maxDocuments = 5): array
    {
        $documents = $this->retrieveDocuments($question, $maxDocuments, $this->currentUserId());
        $context = $this->buildContext($documents);
        $topSimilarity = (float) ($documents->max(fn ($doc) => (float) ($doc->rag_similarity ?? 0.0)) ?? 0.0);
        $isLowConfidence = $documents->isNotEmpty() && $topSimilarity < $this->lowConfidenceSimilarity;

        return [
            'question' => $question,
            'documents' => $documents,
            'context' => $context,
            'system_prompt' => $this->buildSystemPrompt(),
            'user_prompt' => $this->buildUserPrompt($question, $context, $isLowConfidence),
            'retrieval' => [
                'top_similarity' => round($topSimilarity, 4),
                'is_low_confidence' => $isLowConfidence,
            ],
        ];
    }

    /**
     * Get answer quality metrics.
     */
    public function evaluateQuery(string $question): array
    {
        $documents = $this->retrieveDocuments($question, userId: $this->currentUserId());
        $context = $this->buildContext($documents);
        $topSimilarity = (float) ($documents->max(fn ($doc) => (float) ($doc->rag_similarity ?? 0.0)) ?? 0.0);

        return [
            'question_length' => mb_strlen($question),
            'documents_found' => $documents->count(),
            'total_content_length' => mb_strlen($context),
            'has_sufficient_context' => $documents->isNotEmpty(),
            'top_similarity' => round($topSimilarity, 4),
            'is_low_confidence' => $documents->isNotEmpty() && $topSimilarity < $this->lowConfidenceSimilarity,
            'estimated_answer_quality' => $this->estimateQuality($documents),
        ];
    }

    /**
     * Estimate answer quality from retrieved chunk confidence.
     */
    protected function estimateQuality(Collection $documents): string
    {
        if ($documents->isEmpty()) {
            return 'poor';
        }

        $avgSimilarity = (float) $documents->avg(fn ($doc) => (float) ($doc->rag_similarity ?? 0.0));

        return match (true) {
            $avgSimilarity >= 0.85 => 'excellent',
            $avgSimilarity >= 0.70 => 'good',
            $avgSimilarity >= 0.50 => 'fair',
            default => 'poor',
        };
    }

    protected function withSimilarity(mixed $chunk, array $queryEmbedding): mixed
    {
        $similarity = 0.0;

        if (is_array($chunk->embedding ?? null) && ! empty($chunk->embedding)) {
            $similarity = (float) $this->embeddingService->cosineSimilarity($chunk->embedding, $queryEmbedding);
        }

        $chunk->setAttribute('rag_similarity', $similarity);
        $chunk->setAttribute('rag_truncated', false);

        return $chunk;
    }

    protected function applyContextBudget(Collection $chunks, int $maxChunks): Collection
    {
        $selected = collect();
        $usedChars = 0;
        $usedTokens = 0;

        foreach ($chunks as $chunk) {
            if ($selected->count() >= $maxChunks) {
                break;
            }

            $remainingChars = $this->maxContextChars - $usedChars;
            $remainingTokens = $this->maxContextTokens - $usedTokens;

            if ($remainingChars <= 0 || $remainingTokens <= 0) {
                break;
            }

            $budgetedChunk = $this->fitChunkToBudget($chunk, $remainingChars, $remainingTokens);

            if (! $budgetedChunk) {
                continue;
            }

            $chunkChars = (int) ($budgetedChunk->char_count ?? mb_strlen((string) ($budgetedChunk->content ?? '')));
            $chunkTokens = (int) ($budgetedChunk->token_count ?? $this->estimateTokenCount((string) ($budgetedChunk->content ?? '')));

            $selected->push($budgetedChunk);
            $usedChars += $chunkChars;
            $usedTokens += $chunkTokens;
        }

        return $selected->values();
    }

    protected function fitChunkToBudget(mixed $chunk, int $remainingChars, int $remainingTokens): mixed
    {
        $content = trim((string) ($chunk->content ?? ''));

        if ($content === '') {
            return null;
        }

        $maxCharsByTokens = max(1, $remainingTokens * 4);
        $allowedChars = min($remainingChars, $maxCharsByTokens);

        if ($allowedChars <= 0) {
            return null;
        }

        $originalLength = mb_strlen($content);
        $wasTruncated = $originalLength > $allowedChars;

        if ($wasTruncated) {
            $content = rtrim(mb_substr($content, 0, max(1, $allowedChars - 1))).'…';
        }

        $budgeted = clone $chunk;
        $budgeted->setAttribute('content', $content);
        $budgeted->setAttribute('char_count', mb_strlen($content));
        $budgeted->setAttribute('token_count', $this->estimateTokenCount($content));
        $budgeted->setAttribute('rag_truncated', $wasTruncated);

        $excerpt = trim((string) ($chunk->excerpt ?? ''));
        if ($excerpt === '') {
            $excerpt = mb_substr($content, 0, 240);
        }

        $budgeted->setAttribute('excerpt', $excerpt);

        return $budgeted;
    }

    protected function estimateTokenCount(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    protected function currentUserId(): ?int
    {
        $userId = auth()->id();

        return is_int($userId) ? $userId : null;
    }
}
