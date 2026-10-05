<?php

    namespace BayesianPlugin\Classes;

    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Interfaces\BayesianClientInterface;
    use BayesianPlugin\Objects\BayesianServer\ModelStatistics;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Objects\ScannedContent\ContentClassification;

    class Classifier
    {
        /**
         * Returns True if the model has enough training data to reasonably classify content, the reason is logged
         * otherwise
         *
         * @param ModelStatistics $model The model statistics of BayesianServer
         * @param int $minimumDocuments The minimum number of training documents of the model and of every label
         * @return bool True if the model is ready to classify content
         */
        public static function isModelReady(ModelStatistics $model, int $minimumDocuments): bool
        {
            // If we have too few training documents, we skip the classification
            if($model->getTotalDocuments() < $minimumDocuments)
            {
                Logger::log()->warning('Skipping classification, not enough training documents');
                return false;
            }

            // Verify that we have all labels before running a classification call
            foreach($model->getLabels() as $labelStatistic)
            {
                // Avoid classifying on malformed models, could lead to massive incorrect predictions
                if(ClassificationFlag::tryFrom($labelStatistic->getLabel()) === null)
                {
                    Logger::log()->error('Malformed Bayesian model, unknown label: ' . $labelStatistic->getLabel() . '. A new model needs to be created');
                    return false;
                }

                // Allow for labels to have enough training documents to reasonably classify
                if($labelStatistic->getDocumentCount() < $minimumDocuments)
                {
                    Logger::log()->warning('Skipping classification, not enough training documents for ' . $labelStatistic->getLabel());
                    return false;
                }
            }

            // Avoid classification if we didn't identify all labels yet
            if($model->getLabelCount() !== count(ClassificationFlag::cases()))
            {
                Logger::log()->warning('Skipping classification, not enough training data');
                return false;
            }

            return true;
        }

        /**
         * Classifies the content, the model must be ready (see isModelReady())
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @param string $content The content to classify
         * @param float|null $threshold Optional. Confidence threshold
         * @param int|null $topK Optional. The number of choices to limit to
         * @param bool $classifyKnownTokens True to skip the classification if the majority of the tokens are unknown
         * @return ContentClassification|null The classification, null if the content can not be classified
         * @throws BayesianException If the request to BayesianServer failed
         */
        public static function classify(BayesianClientInterface $client, string $content, ?float $threshold, ?int $topK, bool $classifyKnownTokens): ?ContentClassification
        {
            $bayesianClassification = $client->classify($content, $topK, $threshold);

            // If we want to only classify content for known tokens
            if($classifyKnownTokens)
            {
                // Return null if the number of unknown tokens is greater than the recognized tokens
                if($bayesianClassification->getUnknownTokenCount() > $bayesianClassification->getKnownTokens())
                {
                    Logger::log()->warning('Skipping classification, too many unknown tokens');
                    return null;
                }
            }

            $classificationFlag = ClassificationFlag::tryFrom($bayesianClassification->getTopLabel());
            if($classificationFlag === null)
            {
                Logger::log()->error('Skipping classification, unknown label: ' . $bayesianClassification->getTopLabel());
                return null;
            }

            // Use the classifier's probability for the top label, not the server's 'confidence' field which is the
            // language detection confidence (always 1.0 for the top language) and says nothing about the label
            return new ContentClassification(
                $classificationFlag,
                max(0.0, min(1.0, $bayesianClassification->getTopProbability())),
                $bayesianClassification->getLanguageCode()
            );
        }
    }
