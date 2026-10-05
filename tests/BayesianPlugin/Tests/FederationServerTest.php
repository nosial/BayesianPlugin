<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace BayesianPlugin\Tests;

    use BayesianPlugin\Classes\BayesianClient;
    use BayesianPlugin\Classes\Classifier;
    use BayesianPlugin\Tests\Helpers\BayesianServerHelper;
    use BayesianPlugin\Tests\Helpers\ModelAssertions;
    use BayesianPlugin\Tests\Helpers\TrainingData;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\IncidentType;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\ScannedContent;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;
    use Throwable;

    /**
     * Integration tests of the plugin installed in a running FederationLib server (SERVER_ENDPOINT), the server must
     * be running with the plugin installed and enabled and BAYESIAN_SERVER_ENDPOINT must be the BayesianServer the
     * server's plugin uses (make test-env, see docker-compose.yml). Nothing depends on the confidences or on the
     * classification of a single sample, which may change with BayesianServer's implementation.
     */
    class FederationServerTest extends TestCase
    {
        use ModelAssertions;

        private const int LEARN_TIMEOUT = 30;

        private FederationClient $client;
        private BayesianClient $bayesianClient;
        /** @var string[] */
        private array $createdEntities = [];
        /** @var string[] */
        private array $createdEvidence = [];
        /** @var string[] */
        private array $createdReports = [];
        /** @var string[] */
        private array $createdOperators = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(self::getServerEndpoint(), getenv('SERVER_ACCESS_TOKEN') ?: null);

            try
            {
                $this->client->getServerInformation();
            }
            catch(RequestException $e)
            {
                $this->fail(sprintf('FederationLib is not reachable at %s (set SERVER_ENDPOINT), start the test environment with "make test-env": %s', self::getServerEndpoint(), $e->getMessage()));
            }

            // The server's plugin classifies with the same BayesianServer, so the model is ready for the server too
            $this->bayesianClient = BayesianServerHelper::getTrainedClient();
        }

        protected function tearDown(): void
        {
            foreach($this->createdReports as $reportUuid)
            {
                try { $this->client->deleteReport($reportUuid); } catch(Throwable) {}
            }

            foreach($this->createdEvidence as $evidenceUuid)
            {
                try { $this->client->deleteEvidence($evidenceUuid); } catch(Throwable) {}
            }

            foreach($this->createdEntities as $entityUuid)
            {
                try { $this->client->deleteEntity($entityUuid); } catch(Throwable) {}
            }

            foreach($this->createdOperators as $operatorUuid)
            {
                try { $this->client->deleteOperator($operatorUuid); } catch(Throwable) {}
            }

            Logger::unregisterHandlers();
        }

        // ---------------------------------------------------------------------------------------------------------
        // CONTENT_SCAN
        // ---------------------------------------------------------------------------------------------------------

        public function testScanUsesTheClassificationOfBayesianServer(): void
        {
            foreach(ClassificationFlag::cases() as $flag)
            {
                $sample = TrainingData::trainingSamples($flag)[0];
                $expected = $this->classifyDirectly($sample);
                $this->assertNotNull($expected, sprintf('BayesianServer did not classify the %s sample', $flag->value));

                // FederationLib has no classification of its own, the classification can only come from the plugin
                $scanned = $this->client->scanContent($sample);
                $this->assertNotNull($scanned->getClassification(), 'The plugin did not classify the content');
                $this->assertSame($expected->getClassificationFlag(), $scanned->getClassification()->getClassificationFlag());
                $this->assertGreaterThan(0.0, $scanned->getClassification()->getConfidence());
                $this->assertLessThanOrEqual(1.0, $scanned->getClassification()->getConfidence());
                $this->assertNotEmpty($scanned->getClassification()->getDetectedLanguage());

                // The classification is applied by FederationLib's CLASSIFICATION_* scanning rules
                $this->assertNotEquals(0.0, $scanned->getScanResults()['CLASSIFICATION_' . $expected->getClassificationFlag()->value]);
            }
        }

        public function testScanDistinguishesTrainedContent(): void
        {
            $this->assertDistinguishesFlags(
                fn(string $sample) => $this->client->scanContent($sample)->getClassification()?->getClassificationFlag(),
                TrainingData::trainingSamples(...),
                'Trained content scanned by FederationLib'
            );
        }

        public function testScanDistinguishesUnseenContent(): void
        {
            $this->assertDistinguishesFlags(
                fn(string $sample) => $this->client->scanContent($sample)->getClassification()?->getClassificationFlag(),
                TrainingData::testSamples(...),
                'Unseen content scanned by FederationLib'
            );
        }

        public function testScanClassifiesEveryEvidenceItemWithText(): void
        {
            $samples = [
                TrainingData::trainingSamples(ClassificationFlag::NORMAL)[1],
                TrainingData::trainingSamples(ClassificationFlag::SUSPICIOUS)[1],
                TrainingData::trainingSamples(ClassificationFlag::MALICIOUS)[1],
            ];

            $expected = array_map(fn(string $sample) => $this->classifyDirectly($sample), $samples);
            $this->assertNotContains(null, $expected, 'BayesianServer did not classify every sample');

            $scanned = $this->client->scanContent([
                new ContentInput($samples[0]),
                new ContentInput(null, 'Evidence without text content'),
                new ContentInput($samples[1]),
                new ContentInput($samples[2]),
            ]);

            // The server only responds with the aggregate classification, the worst classification of the evidence
            // items, which is only right if every item with text content was classified
            $this->assertNotNull($scanned->getClassification(), 'The plugin did not classify the content');
            $this->assertSame(new ScannedContent([], null, $expected)->getClassification()->getClassificationFlag(), $scanned->getClassification()->getClassificationFlag());
        }

        // ---------------------------------------------------------------------------------------------------------
        // RECORD_CHANGE (EVIDENCE_CLASSIFIED)
        // ---------------------------------------------------------------------------------------------------------

        public function testEvidenceSubmittedWithAClassificationTrainsBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $before = $this->getLearnRequests();

            $this->createdEvidence[] = $this->client->submitEvidence($entityUuid, $this->uniqueText(ClassificationFlag::MALICIOUS), classification: ClassificationFlag::MALICIOUS);

            $this->waitForLearnRequests($before + 1);
        }

        public function testEvidenceWithoutAClassificationDoesNotTrainBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $before = $this->getLearnRequests();

            $this->createdEvidence[] = $this->client->submitEvidence($entityUuid, $this->uniqueText(ClassificationFlag::NORMAL));

            // The learn request is made during the request, nothing can arrive after the response
            $this->assertSame($before, $this->getLearnRequests());
        }

        public function testClassifyingEvidenceTrainsBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $evidenceUuid = $this->client->submitEvidence($entityUuid, $this->uniqueText(ClassificationFlag::SUSPICIOUS));
            $this->createdEvidence[] = $evidenceUuid;
            $before = $this->getLearnRequests();

            $this->client->classifyEvidence($evidenceUuid, ClassificationFlag::SUSPICIOUS);

            $this->waitForLearnRequests($before + 1);
        }

        public function testClosingAReportWithAClassificationTrainsBayesianServer(): void
        {
            $entityUuid = $this->createEntity();
            $submission = $this->client->submitReport($entityUuid, new ContentInput($this->uniqueText(ClassificationFlag::MALICIOUS)), IncidentType::SPAM);
            $this->createdReports[] = $submission->getReport()->getUuid();
            foreach($submission->getEvidence() as $evidence)
            {
                $this->createdEvidence[] = $evidence->getUuid();
            }

            // Only the operator assigned to the report can close it
            $this->client->assignOperatorToReport($submission->getReport()->getUuid(), $this->client->getSelf()->getUuid());

            $before = $this->getLearnRequests();
            $this->client->closeReport($submission->getReport()->getUuid(), ClassificationFlag::MALICIOUS);

            $this->waitForLearnRequests($before + count($submission->getEvidence()));
        }

        // ---------------------------------------------------------------------------------------------------------
        // BayesianServer proxy (/bayesian/*)
        // ---------------------------------------------------------------------------------------------------------

        public function testProxyRequiresAuthentication(): void
        {
            $response = self::httpRequest('GET', self::getServerEndpoint() . '/bayesian/health', null, null);
            $this->assertSame(401, $response['code']);
        }

        public function testProxyRequiresTheRootOperator(): void
        {
            // Even an operator with every permission is not the root operator
            $operator = $this->client->createOperator(substr(uniqid('bayesianproxy'), 0, 32));
            $this->createdOperators[] = $operator->getUuid();
            $this->client->setManagementPermissions($operator->getUuid(), true);
            $this->client->setOperatorPermissions($operator->getUuid(), true);
            $this->client->setClientPermissions($operator->getUuid(), true);

            $response = self::httpRequest('GET', self::getServerEndpoint() . '/bayesian/health', null, $operator->getAccessToken());
            $this->assertSame(403, $response['code']);
            $this->assertStringNotContainsString('"status":true', $response['body']);
        }

        public function testProxyHealth(): void
        {
            $response = $this->proxyRequest('GET', '/bayesian/health');

            $this->assertSame(200, $response['code'], $response['body']);
            $this->assertStringStartsWith('application/json', (string)$response['content_type']);
            $this->assertSame(true, json_decode($response['body'], true)['status'] ?? null);
        }

        public function testProxyStatus(): void
        {
            foreach(['/bayesian/', '/bayesian'] as $path)
            {
                $response = $this->proxyRequest('GET', $path);
                $this->assertSame(200, $response['code'], $response['body']);

                // BayesianServer's diagnostics, not one of FederationLib's responses
                $status = json_decode($response['body'], true);
                $this->assertIsArray($status);
                $this->assertArrayNotHasKey('success', $status);
                $this->assertArrayHasKey('model', $status);
                $this->assertArrayHasKey('learning', $status);
            }
        }

        public function testProxyClassification(): void
        {
            $response = $this->proxyRequest('POST', '/bayesian/', json_encode(['text' => TrainingData::trainingSamples(ClassificationFlag::MALICIOUS)[0], 'top_k' => 3]));
            $this->assertSame(200, $response['code'], $response['body']);

            // BayesianServer's classification as-is, the same one the plugin's client parses
            $classification = json_decode($response['body'], true);
            $this->assertIsArray($classification);
            $this->assertContains($classification['top_label'] ?? null, array_map(fn(ClassificationFlag $flag) => $flag->value, ClassificationFlag::cases()));
            $this->assertNotEmpty($classification['labels'] ?? null);
        }

        public function testProxyTraining(): void
        {
            $before = $this->getLearnRequests();

            $response = $this->proxyRequest('PUSH', '/bayesian/', json_encode(['text' => $this->uniqueText(ClassificationFlag::NORMAL), 'label' => ClassificationFlag::NORMAL->value]));

            $this->assertSame(202, $response['code'], $response['body']);
            $this->assertTrue(json_decode($response['body'], true)['accepted'] ?? false);
            $this->waitForLearnRequests($before + 1);
        }

        public function testProxyQueryString(): void
        {
            // BayesianServer responds with the limit and offset it applied, the defaults are 100 and 0
            $response = $this->proxyRequest('GET', '/bayesian/analytics?limit=7&offset=3');
            $this->assertSame(200, $response['code'], $response['body']);

            $analytics = json_decode($response['body'], true);
            $this->assertSame(7, $analytics['limit'] ?? null);
            $this->assertSame(3, $analytics['offset'] ?? null);
        }

        public function testProxyPassesErrorsThrough(): void
        {
            // BayesianServer's own errors, for the path without the /bayesian prefix
            $response = $this->proxyRequest('GET', '/bayesian/no-such-route');
            $this->assertSame(404, $response['code']);
            $this->assertSame(['error' => 'no such route: /no-such-route', 'status' => 404], json_decode($response['body'], true));

            $response = $this->proxyRequest('DELETE', '/bayesian/health');
            $this->assertSame(405, $response['code']);
            $this->assertStringContainsString('method DELETE not allowed for /health', json_decode($response['body'], true)['error'] ?? '');
        }

        // ---------------------------------------------------------------------------------------------------------
        // Helpers
        // ---------------------------------------------------------------------------------------------------------

        private static function getServerEndpoint(): string
        {
            return rtrim(getenv('SERVER_ENDPOINT') ?: 'http://172.17.0.1:7000', '/');
        }

        /**
         * Sends a request to FederationLib as the root operator (SERVER_ACCESS_TOKEN)
         *
         * @return array{code: int, content_type: ?string, body: string}
         */
        private function proxyRequest(string $method, string $pathAndQuery, ?string $body=null): array
        {
            return self::httpRequest($method, self::getServerEndpoint() . $pathAndQuery, $body, getenv('SERVER_ACCESS_TOKEN') ?: null);
        }

        /**
         * @return array{code: int, content_type: ?string, body: string}
         */
        private static function httpRequest(string $method, string $url, ?string $body, ?string $accessToken): array
        {
            $headers = [];
            if($body !== null)
            {
                $headers[] = 'Content-Type: application/json';
            }
            if($accessToken !== null)
            {
                $headers[] = 'Authorization: Bearer ' . $accessToken;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            if($body !== null)
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            if($response === false)
            {
                throw new \RuntimeException(sprintf('Request [%s] %s failed: %s', $method, $url, curl_error($ch)));
            }

            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            return [
                'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'content_type' => is_string($contentType) ? $contentType : null,
                'body' => $response,
            ];
        }

        private function createEntity(): string
        {
            $entityUuid = $this->client->pushEntity(uniqid('bayesianplugin') . '.example.com');
            $this->createdEntities[] = $entityUuid;
            return $entityUuid;
        }

        /**
         * Returns a training sample made unique, so BayesianServer never rejects it as a known document
         */
        private function uniqueText(ClassificationFlag $flag): string
        {
            return TrainingData::trainingSamples($flag)[4] . ' ' . uniqid('ref');
        }

        /**
         * Classifies content with BayesianServer directly, the way the server's plugin classifies it with its default
         * configuration
         */
        private function classifyDirectly(string $content): ?ContentClassification
        {
            return Classifier::classify($this->bayesianClient, $content, null, null, true);
        }

        private function getLearnRequests(): int
        {
            return BayesianServerHelper::getLearnRequests($this->bayesianClient);
        }

        private function waitForLearnRequests(int $expected): void
        {
            $deadline = time() + self::LEARN_TIMEOUT;
            while(time() < $deadline)
            {
                if($this->getLearnRequests() >= $expected)
                {
                    $this->assertGreaterThanOrEqual($expected, $this->getLearnRequests());
                    BayesianServerHelper::waitForLearning($this->bayesianClient);
                    return;
                }

                usleep(250000);
            }

            $this->fail(sprintf('BayesianServer received %d learn requests, expected at least %d, the plugin did not train it', $this->getLearnRequests(), $expected));
        }
    }
