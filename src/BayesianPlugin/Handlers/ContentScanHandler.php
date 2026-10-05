<?php

    namespace BayesianPlugin\Handlers;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Classes\Classifier;
    use BayesianPlugin\Classes\Configuration;
    use BayesianPlugin\Classes\Logger;
    use BayesianPlugin\Exceptions\BayesianException;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Objects\Plugin\ContentScan;

    /**
     * Classifies every evidence item of a content scan with BayesianServer, the classifications are included in the
     * scan results
     */
    class ContentScanHandler implements ContentScanEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleContentScan(ContentScan $contentScan): void
        {
            $client = BayesianPlugin::getClient();
            $modelReady = null;

            foreach($contentScan->getEvidence() as $evidenceIndex => $evidence)
            {
                $textContent = $evidence->getTextContent();
                if($textContent === null || strlen($textContent) === 0)
                {
                    continue;
                }

                try
                {
                    // The model's readiness is checked once per scan rather than once per evidence item
                    $modelReady ??= Classifier::isModelReady($client->getStatus()->getModel(), Configuration::getMinimumDocuments());
                    if(!$modelReady)
                    {
                        return;
                    }

                    $classification = Classifier::classify($client, $textContent, $contentScan->getThreshold(), $contentScan->getTopK(), Configuration::classifyKnownTokens());
                    if($classification !== null)
                    {
                        $contentScan->addClassification($classification, $evidenceIndex);
                    }
                }
                catch(BayesianException $e)
                {
                    Logger::log()->error('Classification Error: ' . $e->getMessage(), $e);
                }
            }
        }
    }
