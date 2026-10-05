<?php

    namespace BayesianPlugin\Tests\Helpers;

    use BayesianPlugin\Classes\BayesianClient;
    use BayesianPlugin\Classes\Classifier;
    use BayesianPlugin\Exceptions\BayesianException;
    use FederationLib\Enums\ClassificationFlag;
    use PHPUnit\Framework\Assert;

    /**
     * Provides the BayesianServer the tests run against, the one bundled with the test environment's FederationLib
     * server, reached through the plugin's /bayesian proxy as the root operator (SERVER_ENDPOINT, SERVER_ACCESS_TOKEN),
     * and trains it once per test run
     */
    class BayesianServerHelper
    {
        private const int LEARNING_TIMEOUT = 120;

        private static bool $trained = false;

        /**
         * Returns the endpoint of the BayesianServer the tests run against, the plugin's proxy
         *
         * @return string The endpoint, eg; http://172.17.0.1:7000/bayesian
         */
        public static function getEndpoint(): string
        {
            return rtrim(getenv('SERVER_ENDPOINT') ?: 'http://172.17.0.1:7000', '/') . '/bayesian';
        }

        /**
         * Returns the headers that authenticate requests to the proxy as the root operator
         *
         * @return string[] The headers
         */
        public static function getHeaders(): array
        {
            $accessToken = getenv('SERVER_ACCESS_TOKEN');
            return $accessToken ? ['Authorization: Bearer ' . $accessToken] : [];
        }

        /**
         * Returns a client for the BayesianServer the tests run against, failing the test if it is unreachable
         *
         * @return BayesianClient The client
         */
        public static function getClient(): BayesianClient
        {
            $client = new BayesianClient(self::getEndpoint(), self::getHeaders());

            try
            {
                if(!$client->health())
                {
                    Assert::fail(sprintf('BayesianServer at %s is not healthy', self::getEndpoint()));
                }
            }
            catch(BayesianException $e)
            {
                Assert::fail(sprintf('BayesianServer is not reachable at %s, start the test environment with "make test-env" (the plugin\'s proxy must be enabled with BAYESIAN_PLUGIN_PROXY and SERVER_ACCESS_TOKEN must be the root operator\'s): %s', self::getEndpoint(), $e->getMessage()));
            }

            return $client;
        }

        /**
         * Trains the BayesianServer with the training samples of every flag, once per test run, and waits until the
         * model is ready to classify content
         *
         * @return BayesianClient The client of the trained BayesianServer
         */
        public static function getTrainedClient(): BayesianClient
        {
            $client = self::getClient();
            if(self::$trained)
            {
                return $client;
            }

            // A server that is reused between test runs may already know (and reject) the samples, what matters is that
            // the model is ready once the training is processed
            foreach(ClassificationFlag::cases() as $flag)
            {
                foreach(TrainingData::trainingSamples($flag) as $sample)
                {
                    $client->learn($sample, $flag->value);
                }
            }

            self::waitForLearning($client);
            Assert::assertTrue(Classifier::isModelReady($client->getStatus()->getModel(), TrainingData::TRAINING_SAMPLES), 'The model is not ready after training');

            self::$trained = true;
            return $client;
        }

        /**
         * Waits until BayesianServer processed every pending training document, learning is asynchronous
         *
         * @param BayesianClient $client The client
         * @return void
         */
        public static function waitForLearning(BayesianClient $client): void
        {
            $deadline = time() + self::LEARNING_TIMEOUT;
            $idlePolls = 0;

            while(time() < $deadline)
            {
                // Require two consecutive idle polls, a document may be in progress while the queue is empty
                $idlePolls = $client->getStatus()->getLearning()->getPending() === 0 ? $idlePolls + 1 : 0;
                if($idlePolls >= 2)
                {
                    return;
                }

                usleep(250000);
            }

            Assert::fail(sprintf('BayesianServer did not finish learning within %d seconds', self::LEARNING_TIMEOUT));
        }

        /**
         * Returns the number of learn requests BayesianServer received, including the ones it rejected (eg; a known
         * document), so a learn request is counted regardless of what the model does with it
         *
         * @param BayesianClient $client The client
         * @return int The number of learn requests
         */
        public static function getLearnRequests(BayesianClient $client): int
        {
            $learning = $client->getStatus()->getLearning();
            return $learning->getSubmitted() + $learning->getRejected() + $learning->getRejectedMaxDocs();
        }
    }
