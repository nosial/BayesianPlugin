<?php

    namespace BayesianPlugin\Interfaces;

    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Objects\BayesianClassification;
    use BayesianPlugin\Objects\BayesianLearn;
    use BayesianPlugin\Objects\BayesianServer;

    interface BayesianClientInterface
    {
        /**
         * Classifies text and returns per-label scores
         *
         * @param string $text The text to classify
         * @param int|null $topK Maximum number of labels to return; null or <=0 returns all
         * @param float|null $threshold Override for the multi-label decision threshold (0..1); null uses the server default
         * @return BayesianClassification The classification result
         * @throws BayesianException On request failure
         */
        public function classify(string $text, ?int $topK = null, ?float $threshold = null): BayesianClassification;

        /**
         * Submits a document for asynchronous training
         *
         * @param string $text The document text
         * @param string|array $labels A single label string or an array of label strings
         * @return BayesianLearn The learn response
         * @throws BayesianException On request failure
         */
        public function learn(string $text, string|array $labels): BayesianLearn;

        /**
         * Returns full model diagnostics and server information
         *
         * @return BayesianServer Model and server statistics
         * @throws BayesianException On request failure
         */
        public function getStatus(): BayesianServer;
    }
