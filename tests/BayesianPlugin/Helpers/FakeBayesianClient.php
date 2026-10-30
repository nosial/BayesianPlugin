<?php

    namespace BayesianPlugin\Tests\Helpers;

    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Interfaces\BayesianClientInterface;
    use BayesianPlugin\Objects\BayesianClassification;
    use BayesianPlugin\Objects\BayesianLearn;
    use BayesianPlugin\Objects\BayesianServer;

    /**
     * A BayesianServer client that never makes a request, the responses are configured by the test
     */
    class FakeBayesianClient implements BayesianClientInterface
    {
        /** @var array<string, array> Classification responses by text, `*` matches any text */
        public array $classifications = [];
        public ?array $model = null;
        public bool $failStatus = false;
        /** @var string[] Texts for which classify() fails */
        public array $failClassify = [];
        public bool $failLearn = false;
        /** @var array<int, array{text: string, top_k: int|null, threshold: float|null}> */
        public array $classifyCalls = [];
        /** @var array<int, array{text: string, labels: string|array}> */
        public array $learnCalls = [];
        /** @var array<int, array{text: string, labels: string|array, weight: float|null}> */
        public array $correctCalls = [];
        /** @var string[] Texts forgotten with forget() */
        public array $forgetCalls = [];
        /** @var array<int, array{texts: string[], implicit_label: string|null}> */
        public array $batchCalls = [];
        /** True to fail classifyBatch() like a server without batches (HTTP 400) */
        public bool $failBatch = false;
        public int $statusCalls = 0;

        /**
         * Returns a model that is ready to classify content
         */
        public static function readyModel(int $documentsPerLabel=10): array
        {
            return self::model(array_map(fn(string $label) => self::label($label, $documentsPerLabel), ['NORMAL', 'SUSPICIOUS', 'MALICIOUS']));
        }

        /**
         * Returns model statistics with the given label statistics
         */
        public static function model(array $labels, ?int $totalDocuments=null, ?int $labelCount=null, int $vocabularySize=100): array
        {
            return [
                'total_documents' => $totalDocuments ?? array_sum(array_column($labels, 'document_count')),
                'label_count' => $labelCount ?? count($labels),
                'vocabulary_size' => $vocabularySize,
                'total_token_occurrences' => 1000,
                'total_document_tokens' => 1000,
                'smoothing_alpha' => 1.0,
                'average_document_length' => 10.0,
                'average_tokens_per_label' => 100.0,
                'token_density' => 1.0,
                'model_version' => 1,
                'labels' => $labels,
            ];
        }

        /**
         * Returns the statistics of a label
         */
        public static function label(string $label, int $documentCount): array
        {
            return [
                'label' => $label,
                'document_count' => $documentCount,
                'total_tokens' => 100,
                'distinct_tokens' => 50,
                'document_fraction' => 0.33,
                'avg_token_frequency' => 2.0,
            ];
        }

        /**
         * Returns a classification response
         */
        public static function classification(string $topLabel, float $topProbability, int $knownTokens=10, int $unknownTokens=0, string $languageCode='en', ?array $predictedLabels=null, array $posteriors=[]): array
        {
            return [
                'predicted_labels' => $predictedLabels ?? [$topLabel],
                'top_label' => $topLabel,
                'top_probability' => $topProbability,
                'known_tokens' => $knownTokens,
                'unknown_token_count' => $unknownTokens,
                'total_tokens' => $knownTokens + $unknownTokens,
                'language_code' => $languageCode,
                'confidence' => 1.0,
                'labels' => array_map(fn(string $label, float $posterior) => ['label' => $label, 'posterior' => $posterior, 'probability' => $posterior, 'log_score' => 0.0], array_keys($posteriors), array_values($posteriors)),
            ];
        }

        /**
         * @inheritDoc
         */
        public function classify(string $text, ?int $topK = null, ?float $threshold = null, ?string $negativeLabel = null, ?string $implicitLabel = null): BayesianClassification
        {
            $this->classifyCalls[] = ['text' => $text, 'top_k' => $topK, 'threshold' => $threshold, 'negative_label' => $negativeLabel, 'implicit_label' => $implicitLabel];
            if(in_array($text, $this->failClassify, true))
            {
                throw new BayesianException('Classification failed: connection refused');
            }

            $response = $this->classifications[$text] ?? $this->classifications['*'] ?? null;
            if($response === null)
            {
                throw new BayesianException('No classification configured for: ' . $text);
            }

            return BayesianClassification::fromArray($response);
        }

        /**
         * @inheritDoc
         */
        public function classifyBatch(array $texts, ?int $topK = null, ?float $threshold = null, ?string $negativeLabel = null, ?string $implicitLabel = null): array
        {
            $this->batchCalls[] = ['texts' => $texts, 'implicit_label' => $implicitLabel];
            if($this->failBatch)
            {
                throw new BayesianException('Batch classification failed: HTTP 400', 400);
            }

            $results = [];
            foreach($texts as $text)
            {
                if(in_array($text, $this->failClassify, true))
                {
                    throw new BayesianException('Batch classification failed: connection refused');
                }

                $response = $this->classifications[$text] ?? $this->classifications['*'] ?? null;
                if($response === null)
                {
                    throw new BayesianException('No classification configured for: ' . $text);
                }

                $results[] = BayesianClassification::fromArray($response);
            }

            return $results;
        }

        /**
         * @inheritDoc
         */
        public function learn(string $text, string|array $labels, ?float $weight = null): BayesianLearn
        {
            $this->learnCalls[] = ['text' => $text, 'labels' => $labels, 'weight' => $weight];
            if($this->failLearn)
            {
                throw new BayesianException('Learn failed: connection refused');
            }

            return BayesianLearn::fromArray(['accepted' => true, 'submitted' => 1, 'rejected' => 0, 'pending' => 0, 'current_docs' => 0, 'max_docs' => 0, 'rejected_max_docs' => 0]);
        }

        /**
         * @inheritDoc
         */
        public function correct(string $text, string|array $labels, ?float $weight = null): BayesianLearn
        {
            $this->correctCalls[] = ['text' => $text, 'labels' => $labels, 'weight' => $weight];
            if($this->failLearn)
            {
                throw new BayesianException('Correction failed: connection refused');
            }

            return BayesianLearn::fromArray(['accepted' => true, 'submitted' => 1, 'rejected' => 0, 'pending' => 0, 'current_docs' => 0, 'max_docs' => 0, 'rejected_max_docs' => 0]);
        }

        /**
         * @inheritDoc
         */
        public function forget(string $text): BayesianLearn
        {
            $this->forgetCalls[] = $text;
            if($this->failLearn)
            {
                throw new BayesianException('Forget failed: connection refused');
            }

            return BayesianLearn::fromArray(['accepted' => true, 'submitted' => 1, 'rejected' => 0, 'pending' => 0, 'current_docs' => 0, 'max_docs' => 0, 'rejected_max_docs' => 0]);
        }

        /**
         * @inheritDoc
         */
        public function getStatus(): BayesianServer
        {
            $this->statusCalls++;
            if($this->failStatus)
            {
                throw new BayesianException('Failed to get server status: connection refused');
            }

            return BayesianServer::fromArray([
                'uptime_seconds' => 1,
                'model' => $this->model ?? self::readyModel(),
                'learning' => ['pending' => 0, 'capacity' => 1, 'workers' => 1, 'submitted' => 0, 'processed' => 0, 'failed' => 0, 'rejected' => 0, 'rejected_max_docs' => 0, 'max_docs' => 0, 'current_docs' => 0],
                'server' => ['default_threshold' => 0.5, 'smoothing_alpha' => 1.0, 'cjk_bigrams' => false, 'min_token_length' => 1, 'max_token_length' => 64, 'current_memory_bytes' => 0, 'available_memory_bytes' => 0, 'model_memory_bytes' => 0, 'model_memory_limit_bytes' => 0, 'read_only' => false, 'mml' => false, 'mml_confidence_threshold' => 0.0],
            ]);
        }
    }
