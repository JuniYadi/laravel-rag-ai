<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class RagService
{
    protected VectorSearchService $vectorSearchService;

    protected EmbeddingService $embeddingService;

    protected string $llmProvider;

    protected string $llmModel;

    protected string $llmBaseUrl;

    protected string $openaiApiKey;

    public function __construct(
        VectorSearchService $vectorSearchService,
        EmbeddingService $embeddingService
    ) {
        $this->vectorSearchService = $vectorSearchService;
        $this->embeddingService = $embeddingService;
        $this->llmProvider = config('services.llm.provider', 'openai');
        $this->llmModel = config('services.llm.model', 'gpt-4o-mini');
        $this->llmBaseUrl = config('services.llm.base_url', 'https://api.openai.com/v1');
        $this->openaiApiKey = config('services.llm.provider') === 'openai'
            ? config('services.openai.api_key', env('OPENAI_API_KEY'))
            : env('OPENAI_API_KEY');
    }

    /**
     * Query the RAG system with a question
     */
    public function query(string $question, int $maxDocuments = 5): array
    {
        // Step 1: Retrieve relevant documents
        $documents = $this->retrieveDocuments($question, $maxDocuments);

        // Step 2: Generate answer using LLM
        $answer = $this->generateAnswer($question, $documents);

        return [
            'question' => $question,
            'answer' => $answer,
            'sources' => $documents->map(fn ($doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'excerpt' => $doc->excerpt,
                'file_type' => $doc->file_type,
            ]),
            'document_count' => $documents->count(),
        ];
    }

    /**
     * Retrieve relevant documents for the query
     */
    public function retrieveDocuments(string $query, int $limit = 5): Collection
    {
        return $this->vectorSearchService->search($query, $limit);
    }

    /**
     * Generate answer using LLM with retrieved context
     */
    protected function generateAnswer(string $question, Collection $documents): string
    {
        if ($documents->isEmpty()) {
            return "I don't have any relevant documents to answer your question. Please upload some documents first.";
        }

        $context = $this->buildContext($documents);
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($question, $context);

        return $this->callLlm($systemPrompt, $userPrompt);
    }

    /**
     * Build context from retrieved documents
     */
    protected function buildContext(Collection $documents): string
    {
        $contextParts = [];

        foreach ($documents as $index => $document) {
            $contextParts[] = '[Document '.($index + 1).": {$document->title}]\n{$document->content}";
        }

        return implode("\n\n---\n\n", $contextParts);
    }

    /**
     * Build system prompt for RAG
     */
    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a helpful AI assistant that answers questions based on the provided documents.
Your goal is to provide accurate, helpful answers using ONLY the information from the documents.
If the documents don't contain enough information to fully answer the question, say so clearly.
Always cite which document(s) your answer comes from when possible.
Be concise but thorough in your answers.
PROMPT;
    }

    /**
     * Build user prompt with question and context
     */
    protected function buildUserPrompt(string $question, string $context): string
    {
        return <<<PROMPT
## Question
{$question}

## Context Documents
{$context}

## Instructions
Based on the context documents above, please answer the question.
If the answer is not fully covered in the documents, indicate what information is missing.
PROMPT;
    }

    /**
     * Call LLM API
     */
    protected function callLlm(string $systemPrompt, string $userPrompt): string
    {
        if ($this->llmProvider === 'openai') {
            return $this->callOpenAi($systemPrompt, $userPrompt);
        }

        // Fallback or other providers can be added here
        return $this->callOpenAi($systemPrompt, $userPrompt);
    }

    /**
     * Call OpenAI API
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
            throw new \RuntimeException('LLM API call failed: '.$response->body());
        }

        $data = $response->json();

        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Stream response from LLM (for real-time UI updates)
     */
    public function queryWithStream(string $question, int $maxDocuments = 5): array
    {
        $documents = $this->retrieveDocuments($question, $maxDocuments);

        return [
            'question' => $question,
            'documents' => $documents,
            'context' => $this->buildContext($documents),
            'system_prompt' => $this->buildSystemPrompt(),
            'user_prompt' => $this->buildUserPrompt($question, $this->buildContext($documents)),
        ];
    }

    /**
     * Get answer quality metrics
     */
    public function evaluateQuery(string $question): array
    {
        $documents = $this->retrieveDocuments($question);
        $context = $this->buildContext($documents);

        return [
            'question_length' => mb_strlen($question),
            'documents_found' => $documents->count(),
            'total_content_length' => mb_strlen($context),
            'has_sufficient_context' => $documents->isNotEmpty(),
            'estimated_answer_quality' => $this->estimateQuality($documents, $question),
        ];
    }

    /**
     * Estimate answer quality based on retrieved documents
     */
    protected function estimateQuality(Collection $documents, string $question): string
    {
        if ($documents->isEmpty()) {
            return 'poor';
        }

        $avgSimilarity = $documents->avg(function ($doc) use ($question) {
            if (! $doc->embedding) {
                return 0;
            }
            $queryEmbedding = $this->embeddingService->generateEmbedding($question);

            return $this->embeddingService->cosineSimilarity($doc->embedding, $queryEmbedding);
        });

        return match (true) {
            $avgSimilarity >= 0.85 => 'excellent',
            $avgSimilarity >= 0.70 => 'good',
            $avgSimilarity >= 0.50 => 'fair',
            default => 'poor',
        };
    }
}
