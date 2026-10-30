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
         * @param string|null $negativeLabel The label of content where nothing was detected (eg; NORMAL), another label is
         *                                   then only predicted when the content is unlikely enough to have it (logistic
         *                                   model); null predicts every label by its own threshold
         * @param string|null $implicitLabel A label (eg; NORMAL) BayesianServer learns the text with later if it predicts
         *                                   only this label (logistic model with --implicit-delay), unless the text is
         *                                   learned in the meantime; null never learns it implicitly
         * @return BayesianClassification The classification result
         * @throws BayesianException On request failure
         */
        public function classify(string $text, ?int $topK = null, ?float $threshold = null, ?string $negativeLabel = null, ?string $implicitLabel = null): BayesianClassification;

        /**
         * Classifies several texts in one request, see classify()
         *
         * @param string[] $texts The texts to classify (1000 at most)
         * @param int|null $topK Maximum number of labels to return; null or <=0 returns all
         * @param float|null $threshold Override for the multi-label decision threshold (0..1); null uses the server default
         * @param string|null $negativeLabel The label of content where nothing was detected, see classify()
         * @param string|null $implicitLabel The label texts are learned with later, see classify()
         * @return BayesianClassification[] The classification of every text, in the order of the texts
         * @throws BayesianException On request failure, also when the server doesn't support batches (HTTP 400)
         */
        public function classifyBatch(array $texts, ?int $topK = null, ?float $threshold = null, ?string $negativeLabel = null, ?string $implicitLabel = null): array;

        /**
         * Submits a document for asynchronous training
         *
         * @param string $text The document text
         * @param string|array $labels A single label string or an array of label strings
         * @param float|null $weight How much the document counts in training (logistic model, default 1), documents
         *                           weighing less than 1 don't calibrate the server's thresholds
         * @return BayesianLearn The learn response
         * @throws BayesianException On request failure
         */
        public function learn(string $text, string|array $labels, ?float $weight = null): BayesianLearn;

        /**
         * Corrects the labels of a text: BayesianServer replaces every earlier training of the text with one training
         * with the given labels (PATCH /). Falls back to learn() when the server does not support corrections
         *
         * @param string $text The document text
         * @param string|array $labels A single label string or an array of label strings
         * @param float|null $weight How much the document counts in training (logistic model, default 1)
         * @return BayesianLearn The learn response
         * @throws BayesianException On request failure
         */
        public function correct(string $text, string|array $labels, ?float $weight = null): BayesianLearn;

        /**
         * Forgets a text: BayesianServer removes every earlier training of it (DELETE /)
         *
         * @param string $text The document text
         * @return BayesianLearn The response, not accepted when the learning queue is full
         * @throws BayesianException On request failure, also when the server can't forget texts (HTTP 405)
         */
        public function forget(string $text): BayesianLearn;

        /**
         * Returns full model diagnostics and server information
         *
         * @return BayesianServer Model and server statistics
         * @throws BayesianException On request failure
         */
        public function getStatus(): BayesianServer;
    }
