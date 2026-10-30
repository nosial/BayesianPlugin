<?php

    namespace BayesianPlugin\Handlers;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Classes\Classifier;
    use BayesianPlugin\Classes\Configuration;
    use BayesianPlugin\Classes\Logger;
    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Interfaces\BayesianClientInterface;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Objects\Plugin\ContentScan;

    class ContentScanHandler implements ContentScanEventHandlerInterface
    {
        private const string CONTACT_ROUTE_PATTERN = '/(https?:\/\/|www\.|\bt\.me\/|(?<![\w.])@[a-z0-9_]{4,}|\+?\d[\d\s().-]{7,}\d)/iu';
        private const string READY_CACHE_KEY = 'bayesian_plugin_model_ready';
        private const int READY_CACHE_TTL = 30;

        /**
         * @inheritDoc
         */
        public static function handleContentScan(ContentScan $contentScan): void
        {
            $texts = [];
            foreach($contentScan->getEvidence() as $evidenceIndex => $evidence)
            {
                $textContent = $evidence->getTextContent();
                if($textContent !== null && strlen($textContent) > 0)
                {
                    $texts[$evidenceIndex] = $textContent;
                }
            }

            if(count($texts) === 0)
            {
                return;
            }

            $client = BayesianPlugin::getClient();
            try
            {
                if(!self::isModelReady($client))
                {
                    return;
                }

                $classifications = self::classifyAll($client, array_values($texts), $contentScan->getThreshold(), $contentScan->getTopK());
            }
            catch(BayesianException $e)
            {
                Logger::log()->error('Classification Error: ' . $e->getMessage(), $e);
                return;
            }

            $maliciousConfirmed = self::isMaliciousConfirmed($contentScan);
            foreach(array_keys($texts) as $position => $evidenceIndex)
            {
                if($classifications[$position] === null)
                {
                    continue;
                }

                $classification = Classifier::interpret($classifications[$position], Configuration::classifyKnownTokens(),
                    Configuration::getMinimumConfidence(), $maliciousConfirmed || self::hasContactRoute($texts[$evidenceIndex]));
                if($classification !== null)
                {
                    $contentScan->addClassification($classification, $evidenceIndex);
                }
            }
        }

        /**
         * Classifies the texts in one request. If the batch fails (eg; a server before batches existed), every text is
         * classified with its own request instead, a text whose request fails is not classified.
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @param string[] $texts The texts
         * @param float|null $threshold The decision threshold
         * @param int|null $topK The number of labels to return
         * @return array The classifications in the order of the texts, null for a text that could not be classified
         * @throws BayesianException If a single text could not be classified
         */
        private static function classifyAll(BayesianClientInterface $client, array $texts, ?float $threshold, ?int $topK): array
        {
            $negativeLabel = ClassificationFlag::NORMAL->value;
            $implicitLabel = Configuration::isImplicitLearningEnabled() ? ClassificationFlag::NORMAL->value : null;
            if(count($texts) === 1)
            {
                return [$client->classify($texts[0], $topK, $threshold, $negativeLabel, $implicitLabel)];
            }

            try
            {
                return $client->classifyBatch($texts, $topK, $threshold, $negativeLabel, $implicitLabel);
            }
            catch(BayesianException $e)
            {
                Logger::log()->warning('Batch classification failed, classifying every text on its own: ' . $e->getMessage());
            }

            $classifications = [];
            foreach($texts as $text)
            {
                try
                {
                    $classifications[] = $client->classify($text, $topK, $threshold, $negativeLabel, $implicitLabel);
                }
                catch(BayesianException $e)
                {
                    Logger::log()->error('Classification Error: ' . $e->getMessage(), $e);
                    $classifications[] = null;
                }
            }

            return $classifications;
        }

        /**
         * Returns True if signals outside the text confirm that spam in the scan is MALICIOUS, spam that points
         * somewhere: the content has a contact route (a named entity that isn't whitelisted, eg; a link or a handle),
         * or the author or a named entity (or their parent) is blacklisted or has a negative reputation
         *
         * @param ContentScan $contentScan The content scan
         * @return bool True if MALICIOUS is confirmed
         */
        public static function isMaliciousConfirmed(ContentScan $contentScan): bool
        {
            foreach($contentScan->getResolvedEntities() as $resolved)
            {
                if(!$resolved->getEntity()->isWhitelisted())
                {
                    return true;
                }
            }

            $author = $contentScan->getAuthorEntity();
            while($author !== null)
            {
                if(count($author->getActiveBlacklists()) > 0 || $author->getEntity()->getReputation() < 0)
                {
                    return true;
                }

                $author = $author->getParentEntity();
            }

            return false;
        }

        /**
         * Returns True if the text holds a contact route (a link, a Telegram link, a handle or a phone number), the
         * named entities of a scan only hold the entities FederationLib already knows
         *
         * @param string $text The text
         * @return bool True if the text holds a contact route
         */
        public static function hasContactRoute(string $text): bool
        {
            return preg_match(self::CONTACT_ROUTE_PATTERN, $text) === 1;
        }

        /**
         * Forgets the cached readiness of the model, the next scan asks BayesianServer again
         *
         * @return void
         */
        public static function clearReadinessCache(): void
        {
            if(function_exists('apcu_enabled') && apcu_enabled())
            {
                apcu_delete(self::READY_CACHE_KEY);
            }
        }

        /**
         * Returns True if the model is ready to classify content, cached for a few seconds when APCu is available
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @return bool True if the model is ready
         * @throws BayesianException If the model statistics could not be retrieved
         */
        private static function isModelReady(BayesianClientInterface $client): bool
        {
            $cache = function_exists('apcu_enabled') && apcu_enabled();
            if($cache)
            {
                $cached = apcu_fetch(self::READY_CACHE_KEY, $found);
                if($found && is_bool($cached))
                {
                    return $cached;
                }
            }

            $ready = Classifier::isModelReady($client->getStatus()->getModel(), Configuration::getMinimumDocuments());
            if($cache)
            {
                apcu_store(self::READY_CACHE_KEY, $ready, self::READY_CACHE_TTL);
            }

            return $ready;
        }
    }
