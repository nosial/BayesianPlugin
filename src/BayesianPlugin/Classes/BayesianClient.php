<?php

    namespace BayesianPlugin\Classes;

    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Interfaces\BayesianClientInterface;
    use BayesianPlugin\Objects\BayesianAnalytics;
    use BayesianPlugin\Objects\BayesianClassification;
    use BayesianPlugin\Objects\BayesianLearn;
    use BayesianPlugin\Objects\BayesianServer;
    use CurlHandle;
    use InvalidArgumentException;
    use Throwable;

    class BayesianClient implements BayesianClientInterface
    {
        private string $endpoint;
        /** @var string[] */
        private array $headers;

        /**
         * BayesianClient Constructor
         *
         * @param string|null $endpoint Optional. The endpoint URL, defaults to the plugin's configuration
         * @param string[] $headers Optional. Additional HTTP headers sent with every request, eg; an Authorization header
         *                          when BayesianServer is reached through FederationLib's /bayesian proxy
         * @throws InvalidArgumentException If the endpoint is not a valid URL
         */
        public function __construct(?string $endpoint=null, array $headers=[])
        {
            $endpoint ??= Configuration::getEndpoint();

            $parsedUrl = parse_url($endpoint);
            if(empty($endpoint) || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host']))
            {
                throw new InvalidArgumentException("Endpoint must be a valid URL");
            }

            $this->endpoint = rtrim($endpoint, '/');
            $this->headers = array_values($headers);
        }

        /**
         * Classifies text and returns per-label scores.
         *
         * @param string $text The text to classify (required)
         * @param int|null $topK Maximum number of labels to return; null or <=0 returns all
         * @param float|null $threshold Override for the multi-label decision threshold (0..1); null uses server default
         * @param string|null $negativeLabel The label of content where nothing was detected (eg; NORMAL), another label is
         *                                   then only predicted when the content is unlikely enough to have it (logistic
         *                                   model); null predicts every label by its own threshold
         * @param string|null $implicitLabel A label (eg; NORMAL) BayesianServer learns the text with later if it predicts
         *                                   only this label (logistic model with --implicit-delay)
         * @return BayesianClassification The classification result
         * @throws BayesianException On request failure
         */
        public function classify(string $text, ?int $topK = null, ?float $threshold = null, ?string $negativeLabel = null, ?string $implicitLabel = null): BayesianClassification
        {
            $data = self::classificationParameters(['text' => $text], $topK, $threshold, $negativeLabel, $implicitLabel);
            $response = $this->request('POST', '/', $data, [200], 'Classification failed');
            return $this->parse(fn() => BayesianClassification::fromArray($response), 'Classification failed');
        }

        /**
         * @inheritDoc
         */
        public function classifyBatch(array $texts, ?int $topK = null, ?float $threshold = null, ?string $negativeLabel = null, ?string $implicitLabel = null): array
        {
            $data = self::classificationParameters(['texts' => array_values($texts)], $topK, $threshold, $negativeLabel, $implicitLabel);
            $response = $this->request('POST', '/', $data, [200], 'Batch classification failed');
            return $this->parse(function() use ($response, $texts)
            {
                $results = $response['results'] ?? null;
                if(!is_array($results) || count($results) !== count($texts))
                {
                    throw new \InvalidArgumentException('The response does not hold a classification of every text');
                }

                return array_map(fn(array $result) => BayesianClassification::fromArray($result), array_values($results));
            }, 'Batch classification failed');
        }

        /**
         * Adds the optional classification parameters to a request body
         *
         * @param array $data The request body
         * @param int|null $topK Maximum number of labels to return
         * @param float|null $threshold The decision threshold
         * @param string|null $negativeLabel The negative label
         * @param string|null $implicitLabel The implicit label
         * @return array The request body with the parameters that are set
         */
        private static function classificationParameters(array $data, ?int $topK, ?float $threshold, ?string $negativeLabel, ?string $implicitLabel): array
        {

            if($topK !== null)
            {
                $data['top_k'] = $topK;
            }

            if($threshold !== null)
            {
                $data['threshold'] = $threshold;
            }

            if($negativeLabel !== null)
            {
                $data['negative_label'] = $negativeLabel;
            }

            if($implicitLabel !== null)
            {
                $data['implicit_label'] = $implicitLabel;
            }

            return $data;
        }

        /**
         * Submits one or more documents for asynchronous training.
         *
         * @param string $text The document text
         * @param string|array $labels A single label string or an array of label strings
         * @param float|null $weight How much the document counts in training (logistic model, default 1)
         * @return BayesianLearn The learn response
         * @throws BayesianException On request failure
         */
        public function learn(string $text, string|array $labels, ?float $weight = null): BayesianLearn
        {
            if(is_string($labels))
            {
                $labels = [$labels];
            }

            $data = [
                'text' => $text,
                'labels' => $labels
            ];

            if($weight !== null)
            {
                $data['weight'] = $weight;
            }

            $response = $this->request('PUSH', '/', $data, [202, 503], 'Learn failed');
            return $this->parse(fn() => BayesianLearn::fromArray($response), 'Learn failed');
        }

        /**
         * @inheritDoc
         */
        public function correct(string $text, string|array $labels, ?float $weight = null): BayesianLearn
        {
            $data = [
                'text' => $text,
                'labels' => is_string($labels) ? [$labels] : $labels
            ];

            if($weight !== null)
            {
                $data['weight'] = $weight;
            }

            try
            {
                $response = $this->request('PATCH', '/', $data, [202, 503], 'Correction failed');
            }
            catch(BayesianException $e)
            {
                // Servers without corrections (older versions or --corrections false) answer 405
                if($e->getCode() !== 405)
                {
                    throw $e;
                }

                return $this->learn($text, $labels, $weight);
            }

            return $this->parse(fn() => BayesianLearn::fromArray($response), 'Correction failed');
        }

        /**
         * @inheritDoc
         */
        public function forget(string $text): BayesianLearn
        {
            $response = $this->request('DELETE', '/', ['text' => $text], [202, 503], 'Forget failed');
            return $this->parse(fn() => BayesianLearn::fromArray($response), 'Forget failed');
        }

        /**
         * Submits a batch of documents for asynchronous training.
         *
         * @param array $documents Array of documents, each with 'text' and 'labels' keys
         * @return BayesianLearn The learn response
         * @throws BayesianException On request failure
         */
        public function learnBatch(array $documents): BayesianLearn
        {
            $normalized = [];

            foreach($documents as $doc)
            {
                $entry = ['text' => $doc['text']];

                if(isset($doc['label']))
                {
                    $entry['label'] = $doc['label'];
                }

                if(isset($doc['labels']))
                {
                    $entry['labels'] = $doc['labels'];
                }

                $normalized[] = $entry;
            }

            $data = ['documents' => $normalized];
            $response = $this->request('PUSH', '/', $data, [202, 503], 'Batch learn failed');

            return $this->parse(fn() => BayesianLearn::fromArray($response), 'Batch learn failed');
        }

        /**
         * Returns full model diagnostics and server information.
         *
         * @return BayesianServer Model and server statistics
         * @throws BayesianException On request failure
         */
        public function getStatus(): BayesianServer
        {
            $response = $this->request('GET', '/', null, [200], 'Failed to get server status');

            return $this->parse(fn() => BayesianServer::fromArray($response), 'Failed to get server status');
        }

        /**
         * Lightweight liveness probe.
         *
         * @return bool True if the server is healthy
         * @throws BayesianException On request failure
         */
        public function health(): bool
        {
            $response = $this->request('GET', '/health', null, [200], 'Health check failed');

            return isset($response['status']) && $response['status'] === true;
        }

        /**
         * Queries the analytics history using a JSON body (POST).
         *
         * @param array $filters Optional filters: type, language, label, from, to, success, limit, offset, sort
         * @return BayesianAnalytics Filtered analytics entries
         * @throws BayesianException On request failure
         */
        public function queryAnalytics(array $filters = []): BayesianAnalytics
        {
            $allowed = ['type', 'language', 'label', 'from', 'to', 'success', 'limit', 'offset', 'sort'];
            $params = array_intersect_key($filters, array_flip($allowed));
            $response = $this->request('POST', '/analytics', $params, [200], 'Failed to query analytics');

            return $this->parse(fn() => BayesianAnalytics::fromArray($response), 'Failed to query analytics');
        }

        /**
         * Makes an HTTP request to the BayesianServer.
         *
         * @param string $method HTTP method
         * @param string $path API path
         * @param array|null $data Request data
         * @param array $expectedStatusCodes Expected successful HTTP status codes
         * @param string $errorMessage Custom error message prefix
         * @return array Decoded response array
         * @throws BayesianException On request failure
         */
        private function request(string $method, string $path, ?array $data = null, array $expectedStatusCodes = [200], string $errorMessage = 'Request failed'): array
        {
            $path = ltrim($path, '/');

            if(strtoupper($method) === 'GET' && !empty($data))
            {
                $queryString = http_build_query($data);
                $path .= '?' . $queryString;
            }

            Logger::log()->debug(sprintf("%s Request to %s", $method, $this->buildUrl($path)));

            $ch = $this->buildCurl($path);

            switch(strtoupper($method))
            {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, true);
                    if($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                    break;

                case 'PUT':
                case 'PUSH':
                case 'PATCH':
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                    if($data)
                    {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    }
                    break;

                case 'GET':
                default:
                    break;
            }

            $response = curl_exec($ch);

            if(curl_errno($ch))
            {
                $curlError = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                throw new BayesianException($errorMessage . ': ' . $curlError, $httpCode);
            }

            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if(!in_array($responseCode, $expectedStatusCodes))
            {
                throw new BayesianException(
                    $errorMessage . ' received response code: ' . $responseCode,
                    $responseCode
                );
            }

            return $this->decodeResponse($response, $responseCode, $errorMessage);
        }

        /**
         * Decodes the JSON response from the BayesianServer.
         *
         * The BayesianServer returns data directly on success,
         * or {"error": "...", "status": <code>} on failure.
         *
         * @param string $response Raw JSON response
         * @param int $responseCode HTTP status code
         * @param string $errorMessage Error message prefix
         * @return array Decoded response data
         * @throws BayesianException On decode failure or server error
         */
        private function decodeResponse(string $response, int $responseCode, string $errorMessage): array
        {
            $decoded = json_decode($response, true);

            if(json_last_error() !== JSON_ERROR_NONE || !is_array($decoded))
            {
                throw new BayesianException($errorMessage . ': Failed to decode response: ' . json_last_error_msg(), $responseCode);
            }

            // Check for BayesianServer error format: {"error": "...", "status": <code>}
            if(isset($decoded['error']))
            {
                throw new BayesianException($errorMessage . ': ' . $decoded['error'], (int)($decoded['status'] ?? $responseCode));
            }

            return $decoded;
        }

        /**
         * Parses a decoded response into an object, a malformed response is thrown as a BayesianException
         *
         * @template T
         * @param callable(): T $parser The parser
         * @param string $errorMessage Error message prefix
         * @return T The parsed object
         * @throws BayesianException If the response could not be parsed
         */
        private function parse(callable $parser, string $errorMessage): mixed
        {
            try
            {
                return $parser();
            }
            catch(Throwable $e)
            {
                throw new BayesianException($errorMessage . ': Unexpected response: ' . $e->getMessage(), 0, $e);
            }
        }

        /**
         * Builds a cURL handle for the given path.
         *
         * @param string $path The API path
         * @return CurlHandle The constructed CurlHandle
         * @throws BayesianException On request failure
         */
        private function buildCurl(string $path): CurlHandle
        {
            $ch = curl_init($this->buildUrl($path));

            if($ch === false)
            {
                throw new BayesianException('Failed to initialize cURL handle for Bayesian server', 503);
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                ...$this->headers
            ]);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            return $ch;
        }

        /**
         * Builds the full URL for the given path.
         *
         * @param string $path The API path
         * @return string The full URL
         */
        private function buildUrl(string $path): string
        {
            return $this->endpoint . '/' . $path;
        }
    }
