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
                'untrained model' => [FakeBayesianClient::model([
                    FakeBayesianClient::label('NORMAL', 50), FakeBayesianClient::label('SUSPICIOUS', 50), FakeBayesianClient::label('MALICIOUS', 50),
                ], vocabularySize: 0)],
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
            $this->assertSame([['text' => 'hello', 'top_k' => 2, 'threshold' => 0.4, 'negative_label' => 'NORMAL', 'implicit_label' => null]], $client->classifyCalls);
        }

        public function testClassifySkipsMostlyUnknownTokens(): void
        {
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('NORMAL', 0.9, knownTokens: 2, unknownTokens: 5);

            $this->assertNull(Classifier::classify($client, 'hello', null, null, true));
            $this->assertNotNull(Classifier::classify($client, 'hello', null, null, false));

            // The logistic model also scores the characters of unknown words, the gate doesn't apply to it
            $logistic = FakeBayesianClient::classification('NORMAL', 0.9, knownTokens: 2, unknownTokens: 5);
            $logistic['scoring_method'] = Classifier::LOGISTIC_SCORING_METHOD;
            $client->classifications['*'] = $logistic;
            $this->assertNotNull(Classifier::classify($client, 'hello', null, null, true));
        }

        public function testClassifySkipsUnknownLabel(): void
        {
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('SPAM', 0.9);
            $this->assertNull(Classifier::classify($client, 'hello', null, null, true));
        }

        public function testSpamBelowTheMinimumConfidenceIsDropped(): void
        {
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('MALICIOUS', 0.6);
            $this->assertNull(Classifier::classify($client, 'hello', null, null, true, 0.9));
            $this->assertNotNull(Classifier::classify($client, 'hello', null, null, true, 0.5));

            // NORMAL classifications are never dropped for their confidence
            $client->classifications['*'] = FakeBayesianClient::classification('NORMAL', 0.4);
            $this->assertSame(ClassificationFlag::NORMAL, Classifier::classify($client, 'hello', null, null, true, 0.9)->getClassificationFlag());
        }

        public function testSpamConfidenceIsTheProbabilityOfNotBeingNormal(): void
        {
            // MALICIOUS 0.5 and SUSPICIOUS 0.45: the content is not NORMAL with a probability of 0.95
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('MALICIOUS', 0.5,
                posteriors: ['MALICIOUS' => 0.5, 'SUSPICIOUS' => 0.45, 'NORMAL' => 0.05]);

            $classification = Classifier::classify($client, 'hello', null, null, true, 0.9);
            $this->assertSame(ClassificationFlag::MALICIOUS, $classification->getClassificationFlag());
            $this->assertEqualsWithDelta(0.95, $classification->getConfidence(), 1e-9);
        }

        public function testSpamTheServerDoesNotPredictIsDropped(): void
        {
            // The top label is below the server's (calibrated) decision threshold, the server predicts NORMAL
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('SUSPICIOUS', 0.97, predictedLabels: ['NORMAL']);
            $this->assertNull(Classifier::classify($client, 'hello', null, null, true));
        }

        public function testSpamPredictedOverAMoreProbableNormalIsClassified(): void
        {
            // NORMAL is the most probable label, but the content is unlikely enough to be NORMAL for the server to
            // predict SUSPICIOUS (the SUSPICIOUS and MALICIOUS probabilities together outweigh NORMAL)
            $client = new FakeBayesianClient();
            $client->classifications['*'] = FakeBayesianClient::classification('NORMAL', 0.4, predictedLabels: ['SUSPICIOUS'],
                posteriors: ['NORMAL' => 0.4, 'SUSPICIOUS' => 0.35, 'MALICIOUS' => 0.25]);

            $classification = Classifier::classify($client, 'hello', null, null, true);
            $this->assertSame(ClassificationFlag::SUSPICIOUS, $classification->getClassificationFlag());
            $this->assertEqualsWithDelta(0.6, $classification->getConfidence(), 1e-9);

            // The minimum confidence still applies to the probability of not being NORMAL
            $this->assertNull(Classifier::classify($client, 'hello', null, null, true, 0.7));
        }

        public function testConfirmedSuspiciousSpamIsUpgradedToMalicious(): void
        {
            $suspicious = \BayesianPlugin\Objects\BayesianClassification::fromArray(FakeBayesianClient::classification('SUSPICIOUS', 0.95,
                posteriors: ['SUSPICIOUS' => 0.95, 'MALICIOUS' => 0.03, 'NORMAL' => 0.02]));

            // spam_severity defaults to upgrade: the model decides, a confirmation upgrades SUSPICIOUS spam
            $this->assertSame(ClassificationFlag::SUSPICIOUS, Classifier::interpret($suspicious, true, 0.5, false)->getClassificationFlag());
            $this->assertSame(ClassificationFlag::MALICIOUS, Classifier::interpret($suspicious, true, 0.5, true)->getClassificationFlag());
            $this->assertSame(ClassificationFlag::SUSPICIOUS, Classifier::interpret($suspicious, true, 0.5)->getClassificationFlag());

            // NORMAL content is never changed by a confirmation
            $normal = \BayesianPlugin\Objects\BayesianClassification::fromArray(FakeBayesianClient::classification('NORMAL', 0.9));
            $this->assertSame(ClassificationFlag::NORMAL, Classifier::interpret($normal, true, 0.5, true)->getClassificationFlag());
        }

        public function testContactRoutesAreRecognized(): void
        {
            foreach(['join https://x.io now', 'dm @girlsbot', 'call +44 7700 900123', 'see t.me/+abc'] as $text)
            {
                $this->assertTrue(\BayesianPlugin\Handlers\ContentScanHandler::hasContactRoute($text), $text);
            }

            foreach(['meet at 5 pm', 'email me a@b.co', 'see you in 2025'] as $text)
            {
                $this->assertFalse(\BayesianPlugin\Handlers\ContentScanHandler::hasContactRoute($text), $text);
            }
        }

        public static function contextWordsProvider(): array
        {
            return [
                'telegram link without scheme' => ['t.me/blahblahblah', 0],
                'telegram invite' => ['https://t.me/+abc', 0],
                'domain with a path and query' => ['example.com/path?x=1', 0],
                'handle' => ['@somehandle', 0],
                'phone number' => ['+1 650 253 0000', 0],
                'email address' => ['someone@example.com', 0],
                'link and emoji' => ['t.me/x 🔥🔥', 0],
                'several links' => ["t.me/a\nhttps://example.com/b www.example.org", 0],
                'numbers only' => ['t.me/x 100 500', 0],
                'words around a link' => ['check this out t.me/x', 3],
                'spam around a link' => ['Earn $500 daily, DM me t.me/x', 4],
                'plain text' => ['meet at the station', 4],
                'chinese with a link' => ['加入我们的群组 t.me/x', 4],
            ];
        }

        #[DataProvider('contextWordsProvider')]
        public function testContextWords(string $text, int $expected): void
        {
            $this->assertSame($expected, \BayesianPlugin\Classes\TextContext::countContextWords($text));
        }

        public function testEnoughContext(): void
        {
            // Content that only points somewhere is left to blacklists, reputation and other plugins
            $this->assertFalse(\BayesianPlugin\Classes\TextContext::hasEnoughContext('t.me/blahblahblah'));
            $this->assertFalse(\BayesianPlugin\Classes\TextContext::hasEnoughContext('join t.me/blahblahblah'));
            $this->assertTrue(\BayesianPlugin\Classes\TextContext::hasEnoughContext('free crypto signals, join t.me/blahblahblah'));

            // A minimum of 0 classifies every text
            $this->assertTrue(\BayesianPlugin\Classes\TextContext::hasEnoughContext('t.me/blahblahblah', 0));
            $this->assertFalse(\BayesianPlugin\Classes\Learner::isLearnable('t.me/blahblahblah'));
            $this->assertTrue(\BayesianPlugin\Classes\Learner::isLearnable('free crypto signals, join t.me/blahblahblah'));
        }

        public function testClassifyThrowsOnFailure(): void
        {
            $client = new FakeBayesianClient();
            $client->failClassify = ['hello'];

            $this->expectException(BayesianException::class);
            Classifier::classify($client, 'hello', null, null, true);
        }
    }
