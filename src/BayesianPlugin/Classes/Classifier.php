<?php

    namespace BayesianPlugin\Classes;

    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Interfaces\BayesianClientInterface;
    use BayesianPlugin\Objects\BayesianClassification;
    use BayesianPlugin\Objects\BayesianServer\ModelStatistics;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Objects\ScannedContent\ContentClassification;

    class Classifier
    {
        /**
         * Tells SUSPICIOUS and MALICIOUS spam apart as configured by spam_severity
         *
         * @param ClassificationFlag $flag The flag predicted by the model, SUSPICIOUS or MALICIOUS
         * @param bool|null $maliciousConfirmed Whether signals outside the text confirm MALICIOUS, null if unknown
         * @return ClassificationFlag The flag to report
         */
        public static function applySeverity(ClassificationFlag $flag, ?bool $maliciousConfirmed): ClassificationFlag
        {
            if($maliciousConfirmed === null)
            {
                return $flag;
            }

            return match(Configuration::getSpamSeverity())
            {
                'confirm' => $maliciousConfirmed ? ClassificationFlag::MALICIOUS : ClassificationFlag::SUSPICIOUS,
                'upgrade' => $maliciousConfirmed ? ClassificationFlag::MALICIOUS : $flag,
                default => $flag
            };
        }

        /** The scoring method of BayesianServer's logistic model */
        public const string LOGISTIC_SCORING_METHOD = 'logistic_regression';

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

            // The logistic model learns documents from its next background retrain on, until then it has no weights
            if($model->getVocabularySize() === 0)
            {
                Logger::log()->warning('Skipping classification, the model is not trained yet');
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
         * @param float $minimumConfidence The minimum probability that the content is not NORMAL for a SUSPICIOUS or
         *                                 MALICIOUS classification, below it no classification is returned
         * @return ContentClassification|null The classification, null if the content can not be classified
         * @throws BayesianException If the request to BayesianServer failed
         */
        public static function classify(BayesianClientInterface $client, string $content, ?float $threshold, ?int $topK, bool $classifyKnownTokens, float $minimumConfidence=0.0): ?ContentClassification
        {
            return self::interpret($client->classify($content, $topK, $threshold, ClassificationFlag::NORMAL->value),
                $classifyKnownTokens, $minimumConfidence);
        }

        /**
         * Turns BayesianServer's classification of content into the classification reported for it
         *
         * @param BayesianClassification $bayesianClassification The classification of BayesianServer
         * @param bool $classifyKnownTokens True to skip the classification if the majority of the words are unknown
         *                                  (Naive Bayes models only, the logistic model also scores the characters of
         *                                  unknown words, which is how it recognizes new spelling variants)
         * @param float $minimumConfidence The minimum probability that the content is not NORMAL for a SUSPICIOUS or
         *                                 MALICIOUS classification, below it no classification is returned
         * @param bool|null $maliciousConfirmed Whether signals outside the text confirm that spam is MALICIOUS (a
         *                                      contact route in the content, a blacklisted or badly reputed author or
         *                                      named entity), null if unknown. Applied as configured by spam_severity
         * @return ContentClassification|null The classification, null if the content can not be classified
         */
        public static function interpret(BayesianClassification $bayesianClassification, bool $classifyKnownTokens, float $minimumConfidence=0.0, ?bool $maliciousConfirmed=null): ?ContentClassification
        {
            // If we want to only classify content for known tokens
            if($classifyKnownTokens && $bayesianClassification->getScoringMethod() !== self::LOGISTIC_SCORING_METHOD)
            {
                // Return null if the number of unknown tokens is greater than the recognized tokens
                if($bayesianClassification->getUnknownTokenCount() > $bayesianClassification->getKnownTokens())
                {
                    Logger::log()->warning('Skipping classification, too many unknown tokens');
                    return null;
                }
            }

            $label = $bayesianClassification->getTopLabel();
            $predictedLabels = $bayesianClassification->getPredictedLabels();
            if($label === ClassificationFlag::NORMAL->value && !empty($predictedLabels) && !in_array($label, $predictedLabels, true))
            {
                $label = $predictedLabels[0];
            }

            $classificationFlag = ClassificationFlag::tryFrom($label);
            if($classificationFlag === null)
            {
                Logger::log()->error('Skipping classification, unknown label: ' . $label);
                return null;
            }

            // Use the classifier's probability for the top label, not the server's 'confidence' field which is the
            // language detection confidence (always 1.0 for the top language) and says nothing about the label
            $confidence = $bayesianClassification->getTopProbability();
            if($classificationFlag !== ClassificationFlag::NORMAL)
            {
                if(!in_array($label, $predictedLabels, true))
                {
                    Logger::log()->debug('Skipping classification, ' . $label . ' is below the decision threshold');
                    return null;
                }

                // How likely the content is not NORMAL, the SUSPICIOUS and MALICIOUS probabilities together
                foreach($bayesianClassification->getLabels() as $labelProbability)
                {
                    if($labelProbability->getLabel() === ClassificationFlag::NORMAL->value)
                    {
                        $confidence = 1.0 - $labelProbability->getPosterior();
                        break;
                    }
                }

                if($confidence < $minimumConfidence)
                {
                    Logger::log()->debug(sprintf('Skipping classification, %s with a confidence of %.3f is below %.3f',
                        $label, $confidence, $minimumConfidence));
                    return null;
                }

                $classificationFlag = self::applySeverity($classificationFlag, $maliciousConfirmed);
            }

            return new ContentClassification(
                $classificationFlag,
                max(0.0, min(1.0, $confidence)),
                $bayesianClassification->getLanguageCode()
            );
        }
    }
