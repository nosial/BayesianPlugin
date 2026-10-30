<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace BayesianPlugin\Tests;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Handlers\ContentScanHandler;
    use BayesianPlugin\Handlers\EvidenceClassifiedHandler;
    use BayesianPlugin\Handlers\EvidenceDeletedHandler;
    use BayesianPlugin\Tests\Helpers\FakeBayesianClient;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\Plugin\RecordChange;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    class EventHandlersTest extends TestCase
    {
        private FakeBayesianClient $client;

        protected function setUp(): void
        {
            $this->client = new FakeBayesianClient();
            BayesianPlugin::setClient($this->client);
            ContentScanHandler::clearReadinessCache();
        }

        protected function tearDown(): void
        {
            BayesianPlugin::setClient(null);
            Logger::unregisterHandlers();
        }

        // ---------------------------------------------------------------------------------------------------------
        // CONTENT_SCAN
        // ---------------------------------------------------------------------------------------------------------

        public function testContentScanClassifiesEveryEvidenceItemWithText(): void
        {
            $this->client->classifications['first'] = FakeBayesianClient::classification('NORMAL', 0.8);
            $this->client->classifications['second'] = FakeBayesianClient::classification('MALICIOUS', 0.95, languageCode: 'fr');

            $contentScan = new ContentScan([new ContentInput('first'), new ContentInput(null, 'no text'), new ContentInput('second')], null, null, [], null, 3, 0.25);
            ContentScanHandler::handleContentScan($contentScan);

            $this->assertCount(2, $contentScan->getAddedClassifications());
            $this->assertSame(ClassificationFlag::NORMAL, $contentScan->getEvidenceClassifications(0)[0]->getClassificationFlag());
            $this->assertSame([], $contentScan->getEvidenceClassifications(1));
            $this->assertSame(ClassificationFlag::MALICIOUS, $contentScan->getEvidenceClassifications(2)[0]->getClassificationFlag());
            $this->assertSame(0.95, $contentScan->getEvidenceClassifications(2)[0]->getConfidence());
            $this->assertSame('fr', $contentScan->getEvidenceClassifications(2)[0]->getDetectedLanguage());

            // Every text is classified in one request (with NORMAL learned implicitly), the model is only checked once
            $this->assertSame([['texts' => ['first', 'second'], 'implicit_label' => 'NORMAL']], $this->client->batchCalls);
            $this->assertSame([], $this->client->classifyCalls);
            $this->assertSame(1, $this->client->statusCalls);

            // The readiness of the model is cached between scans when APCu is available
            ContentScanHandler::handleContentScan(new ContentScan([new ContentInput('first')], null, null, []));
            $this->assertSame(function_exists('apcu_enabled') && apcu_enabled() ? 1 : 2, $this->client->statusCalls);

            // Classifying never adds scanning rules of its own, the classification rules are applied by FederationLib
            $this->assertSame([], $contentScan->getScanResults());
        }

        public function testContentScanSkipsWhenTheModelIsNotReady(): void
        {
            $this->client->model = FakeBayesianClient::readyModel(5);
            $this->client->classifications['*'] = FakeBayesianClient::classification('MALICIOUS', 0.9);

            $contentScan = new ContentScan([new ContentInput('first'), new ContentInput('second')], null, null, []);
            ContentScanHandler::handleContentScan($contentScan);

            $this->assertSame([], $contentScan->getAddedClassifications());
            $this->assertSame([], $this->client->classifyCalls);
            $this->assertSame([], $this->client->batchCalls);
        }

        public function testContentScanContinuesWhenAClassificationFails(): void
        {
            $this->client->classifications['*'] = FakeBayesianClient::classification('SUSPICIOUS', 0.95);
            $this->client->failClassify = ['first'];

            $contentScan = new ContentScan([new ContentInput('first'), new ContentInput('second')], null, null, []);
            ContentScanHandler::handleContentScan($contentScan);

            // The failed batch is classified text by text, only the failing text is not classified
            $this->assertSame([], $contentScan->getEvidenceClassifications(0));
            $this->assertCount(1, $contentScan->getEvidenceClassifications(1));
            $this->assertCount(2, $this->client->classifyCalls);
        }

        public function testContentScanFallsBackForServersWithoutBatches(): void
        {
            $this->client->classifications['*'] = FakeBayesianClient::classification('MALICIOUS', 0.95);
            $this->client->failBatch = true;

            $contentScan = new ContentScan([new ContentInput('first'), new ContentInput('second')], null, null, []);
            ContentScanHandler::handleContentScan($contentScan);

            $this->assertCount(2, $contentScan->getAddedClassifications());
            $this->assertSame(['first', 'second'], array_column($this->client->classifyCalls, 'text'));
        }

        public function testContentScanWithoutBayesianServer(): void
        {
            $this->client->failStatus = true;

            $contentScan = new ContentScan([new ContentInput('first'), new ContentInput('second')], null, null, []);
            ContentScanHandler::handleContentScan($contentScan);

            // An unavailable BayesianServer never rejects the scan, the content is simply not classified
            $this->assertSame([], $contentScan->getAddedClassifications());
        }

        // ---------------------------------------------------------------------------------------------------------
        // RECORD_CHANGE (EVIDENCE_CLASSIFIED)
        // ---------------------------------------------------------------------------------------------------------

        public function testEvidenceClassifiedTrainsBayesianServer(): void
        {
            EvidenceClassifiedHandler::handleRecordChange($this->createChange('spam spam spam', ClassificationFlag::MALICIOUS));
            $this->assertSame([['text' => 'spam spam spam', 'labels' => 'MALICIOUS', 'weight' => null]], $this->client->correctCalls);
            $this->assertSame([], $this->client->learnCalls, 'classifications are learned as corrections, the latest one wins');
        }

        public function testEvidenceClassifiedWithoutTextContent(): void
        {
            EvidenceClassifiedHandler::handleRecordChange($this->createChange(null, ClassificationFlag::NORMAL));
            $this->assertSame([], $this->client->correctCalls);
        }

        public function testEvidenceClassifiedIgnoresLearnFailures(): void
        {
            $this->client->failLearn = true;
            EvidenceClassifiedHandler::handleRecordChange($this->createChange('hello', ClassificationFlag::NORMAL));
            $this->assertCount(1, $this->client->correctCalls);
        }

        public function testOtherRecordChangesAreIgnored(): void
        {
            // Only reachable when the handler is configured without its filter
            EvidenceClassifiedHandler::handleRecordChange($this->createChange('hello', ClassificationFlag::NORMAL, RecordChangeType::EVIDENCE_CREATED));
            EvidenceClassifiedHandler::handleRecordChange($this->createChange('hello', null));
            $this->assertSame([], $this->client->learnCalls);
        }

        // ---------------------------------------------------------------------------------------------------------
        // RECORD_CHANGE (EVIDENCE_DELETED)
        // ---------------------------------------------------------------------------------------------------------

        public function testDeletedEvidenceWithoutTextOrClassificationIsNotForgotten(): void
        {
            EvidenceDeletedHandler::handleRecordChange($this->createChange(null, ClassificationFlag::MALICIOUS, RecordChangeType::EVIDENCE_DELETED));
            EvidenceDeletedHandler::handleRecordChange($this->createChange('never learned', null, RecordChangeType::EVIDENCE_DELETED));
            EvidenceDeletedHandler::handleRecordChange(new RecordChange(RecordChangeType::EVIDENCE_DELETED, '01890a5d-ac96-774b-bcce-b302099a8057'));
            $this->assertSame([], $this->client->forgetCalls);
            $this->assertSame([], $this->client->correctCalls);
        }

        private function createChange(?string $textContent, ?ClassificationFlag $classification, RecordChangeType $type=RecordChangeType::EVIDENCE_CLASSIFIED): RecordChange
        {
            return new RecordChange($type, '01890a5d-ac96-774b-bcce-b302099a8057', new EvidenceRecord([
                'uuid' => '01890a5d-ac96-774b-bcce-b302099a8057',
                'entity' => '01890a5d-ac96-774b-bcce-b302099a8058',
                'operator' => '01890a5d-ac96-774b-bcce-b302099a8059',
                'confidential' => false,
                'text_content' => $textContent,
                'classification_flag' => $classification?->value,
            ]));
        }
    }
