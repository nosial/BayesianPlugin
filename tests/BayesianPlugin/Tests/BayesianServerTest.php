<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace BayesianPlugin\Tests;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Classes\BayesianClient;
    use BayesianPlugin\Classes\Classifier;
    use BayesianPlugin\Handlers\ContentScanHandler;
    use BayesianPlugin\Handlers\EvidenceClassifiedHandler;
    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Objects\BayesianAnalytics;
    use BayesianPlugin\Tests\Helpers\BayesianServerHelper;
    use BayesianPlugin\Tests\Helpers\ModelAssertions;
    use BayesianPlugin\Tests\Helpers\TrainingData;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin\RecordChange;
    use FederationLib\Objects\ScannedContent;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    /**
     * Tests the plugin against a running BayesianServer (BAYESIAN_SERVER_ENDPOINT), the server is trained with the
     * samples of TrainingData, use a dedicated BayesianServer instance for testing. Nothing depends on the confidences
     * or on the classification of a single sample, which may change with BayesianServer's implementation.
     */
    class BayesianServerTest extends TestCase
    {
        use ModelAssertions;

        private BayesianClient $client;

        protected function setUp(): void
        {
            $this->client = BayesianServerHelper::getTrainedClient();
            BayesianPlugin::setClient($this->client);
        }

        protected function tearDown(): void
        {
            BayesianPlugin::setClient(null);
            Logger::unregisterHandlers();
        }

        // ---------------------------------------------------------------------------------------------------------
        // BayesianClient
        // ---------------------------------------------------------------------------------------------------------

        public function testHealth(): void
        {
            $this->assertTrue($this->client->health());
        }

        public function testStatus(): void
        {
            $model = $this->client->getStatus()->getModel();

            $labels = array_map(fn($labelStatistic) => $labelStatistic->getLabel(), $model->getLabels());
            foreach(ClassificationFlag::cases() as $flag)
            {
                $this->assertContains($flag->value, $labels);
            }

            $this->assertGreaterThanOrEqual(TrainingData::TRAINING_SAMPLES * count(ClassificationFlag::cases()), $model->getTotalDocuments());
            $this->assertTrue(Classifier::isModelReady($model, TrainingData::TRAINING_SAMPLES));
        }

        public function testClassify(): void
        {
            $classification = $this->client->classify(TrainingData::trainingSamples(ClassificationFlag::MALICIOUS)[0], 3, 0.0);

            $this->assertContains($classification->getTopLabel(), array_map(fn(ClassificationFlag $flag) => $flag->value, ClassificationFlag::cases()));
            $this->assertGreaterThan(0.0, $classification->getTopProbability());
            $this->assertLessThanOrEqual(1.0, $classification->getTopProbability());
            $this->assertGreaterThan(0, $classification->getKnownTokens());
            $this->assertNotEmpty($classification->getLanguageCode());
        }

        public function testAnalytics(): void
        {
            $this->assertInstanceOf(BayesianAnalytics::class, $this->client->queryAnalytics(['limit' => 10]));
        }

        public function testUnreachableServer(): void
        {
            $this->expectException(BayesianException::class);
            new BayesianClient('http://127.0.0.1:1')->health();
        }

        // ---------------------------------------------------------------------------------------------------------
        // Classifier
        // ---------------------------------------------------------------------------------------------------------

        public function testClassifierDistinguishesTrainedContent(): void
        {
            $this->assertDistinguishesFlags(
                fn(string $sample) => Classifier::classify($this->client, $sample, null, null, true)?->getClassificationFlag(),
                TrainingData::trainingSamples(...),
                'Trained content'
            );
        }

        public function testClassifierDistinguishesUnseenContent(): void
        {
            // Unseen content has tokens the model doesn't know, it is classified regardless
            $this->assertDistinguishesFlags(
                fn(string $sample) => Classifier::classify($this->client, $sample, null, null, false)?->getClassificationFlag(),
                TrainingData::testSamples(...),
                'Unseen content'
            );
        }

        public function testClassifierConfidenceIsAProbability(): void
        {
            foreach(ClassificationFlag::cases() as $flag)
            {
                $classification = Classifier::classify($this->client, TrainingData::trainingSamples($flag)[0], null, null, false);
                $this->assertNotNull($classification);
                $this->assertGreaterThan(0.0, $classification->getConfidence());
                $this->assertLessThanOrEqual(1.0, $classification->getConfidence());
            }
        }

        // ---------------------------------------------------------------------------------------------------------
        // Event handlers
        // ---------------------------------------------------------------------------------------------------------

        public function testContentScanHandler(): void
        {
            $normalSample = TrainingData::trainingSamples(ClassificationFlag::NORMAL)[1];
            $maliciousSample = TrainingData::trainingSamples(ClassificationFlag::MALICIOUS)[1];
            $contentScan = new ContentScan([
                new ContentInput($normalSample),
                new ContentInput(null, 'Evidence without text content'),
                new ContentInput($maliciousSample),
            ], null, null, []);

            ContentScanHandler::handleContentScan($contentScan);

            // Every evidence item with text content is classified the same way as by classifying it directly
            $expected = [
                0 => Classifier::classify($this->client, $normalSample, null, null, true),
                2 => Classifier::classify($this->client, $maliciousSample, null, null, true),
            ];
            foreach($expected as $evidenceIndex => $classification)
            {
                $this->assertNotNull($classification);
                $this->assertCount(1, $contentScan->getEvidenceClassifications($evidenceIndex));
                $this->assertSame($classification->getClassificationFlag(), $contentScan->getEvidenceClassifications($evidenceIndex)[0]->getClassificationFlag());
            }
            $this->assertSame([], $contentScan->getEvidenceClassifications(1));

            // The scan results use the worst classification
            $scannedContent = new ScannedContent([], null, $contentScan->getAddedClassifications(), $contentScan->getScanResults());
            $this->assertSame(new ScannedContent([], null, array_values($expected))->getClassification()->getClassificationFlag(), $scannedContent->getClassification()->getClassificationFlag());
        }

        public function testContentScanHandlerWithoutBayesianServer(): void
        {
            BayesianPlugin::setClient(new BayesianClient('http://127.0.0.1:1'));

            $contentScan = new ContentScan([new ContentInput(TrainingData::trainingSamples(ClassificationFlag::MALICIOUS)[0])], null, null, []);
            ContentScanHandler::handleContentScan($contentScan);

            // An unreachable BayesianServer never fails the scan, the content is not classified
            $this->assertSame([], $contentScan->getAddedClassifications());
        }

        public function testEvidenceClassifiedHandlerTrainsBayesianServer(): void
        {
            $before = BayesianServerHelper::getLearnRequests($this->client);

            EvidenceClassifiedHandler::handleRecordChange(new RecordChange(RecordChangeType::EVIDENCE_CLASSIFIED, '01890a5d-ac96-774b-bcce-b302099a8057', new EvidenceRecord([
                'uuid' => '01890a5d-ac96-774b-bcce-b302099a8057',
                'entity' => '01890a5d-ac96-774b-bcce-b302099a8058',
                'operator' => '01890a5d-ac96-774b-bcce-b302099a8059',
                'confidential' => false,
                'text_content' => 'Your account has been compromised, confirm your password here ' . uniqid(),
                'classification_flag' => ClassificationFlag::MALICIOUS->value,
            ])));

            // The handler made exactly one learn request, whether BayesianServer keeps the document is up to its model
            $this->assertSame($before + 1, BayesianServerHelper::getLearnRequests($this->client));
            BayesianServerHelper::waitForLearning($this->client);
        }
    }
