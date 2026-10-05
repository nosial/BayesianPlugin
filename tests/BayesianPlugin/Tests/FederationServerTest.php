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
        // Helpers
        // ---------------------------------------------------------------------------------------------------------

        private static function getServerEndpoint(): string
        {
            return getenv('SERVER_ENDPOINT') ?: 'http://172.17.0.1:7000';
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
