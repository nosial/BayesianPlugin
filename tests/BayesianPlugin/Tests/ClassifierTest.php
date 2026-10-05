<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace BayesianPlugin\Tests;

    use BayesianPlugin\Classes\Classifier;
    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Objects\BayesianServer\ModelStatistics;
    use BayesianPlugin\Tests\Helpers\FakeBayesianClient;
    use FederationLib\Enums\ClassificationFlag;
    use LogLib2\Logger;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    class ClassifierTest extends TestCase
    {
        protected function tearDown(): void
        {
            Logger::unregisterHandlers();
        }

        public function testReadyModel(): void
        {
            $this->assertTrue(Classifier::isModelReady(ModelStatistics::fromArray(FakeBayesianClient::readyModel()), 10));
        }

        public static function notReadyModelProvider(): array
        {
            return [
                'not enough documents' => [FakeBayesianClient::model([
                    FakeBayesianClient::label('NORMAL', 3), FakeBayesianClient::label('SUSPICIOUS', 3), FakeBayesianClient::label('MALICIOUS', 3),
                ])],
                'label without enough documents' => [FakeBayesianClient::model([
                    FakeBayesianClient::label('NORMAL', 50), FakeBayesianClient::label('SUSPICIOUS', 9), FakeBayesianClient::label('MALICIOUS', 50),
                ])],
                'unknown label' => [FakeBayesianClient::model([
                    FakeBayesianClient::label('NORMAL', 50), FakeBayesianClient::label('SPAM', 50), FakeBayesianClient::label('MALICIOUS', 50),
                ])],
                'missing label' => [FakeBayesianClient::model([
                    FakeBayesianClient::label('NORMAL', 50), FakeBayesianClient::label('MALICIOUS', 50),
                ])],
                'empty model' => [FakeBayesianClient::model([])],
            ];
        }

        #[DataProvider('notReadyModelProvider')]
        public function testModelNotReady(array $model): void
        {
            $this->assertFalse(Classifier::isModelReady(ModelStatistics::fromArray($model), 10));
        }

        public function testMinimumDocumentsIsConfigurable(): void
        {
            $model = ModelStatistics::fromArray(FakeBayesianClient::readyModel(5));
            $this->assertFalse(Classifier::isModelReady($model, 10));
            $this->assertTrue(Classifier::isModelReady($model, 5));
        }

        public function testClassify(): void
        {
            $client = new FakeBayesianClient();
            $client->classifications['hello'] = FakeBayesianClient::classification('MALICIOUS', 0.87, languageCode: 'de');

            $classification = Classifier::classify($client, 'hello', 0.4, 2, true);
            $this->assertNotNull($classification);
            $this->assertSame(ClassificationFlag::MALICIOUS, $classification->getClassificationFlag());
            $this->assertSame(0.87, $classification->getConfidence());
            $this->assertSame('de', $classification->getDetectedLanguage());
            $this->assertSame([['text' => 'hello', 'top_k' => 2, 'threshold' => 0.4]], $client->classifyCalls);
        }

        public function testClassifySkipsMostlyUnknownTokens(): void
        {
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('NORMAL', 0.9, knownTokens: 2, unknownTokens: 5);

            $this->assertNull(Classifier::classify($client, 'hello', null, null, true));
            $this->assertNotNull(Classifier::classify($client, 'hello', null, null, false));
        }

        public function testClassifySkipsUnknownLabel(): void
        {
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('SPAM', 0.9);
            $this->assertNull(Classifier::classify($client, 'hello', null, null, true));
        }

        public function testClassifyThrowsOnFailure(): void
        {
            $client = new FakeBayesianClient();
            $client->failClassify = ['hello'];

            $this->expectException(BayesianException::class);
            Classifier::classify($client, 'hello', null, null, true);
        }
    }
