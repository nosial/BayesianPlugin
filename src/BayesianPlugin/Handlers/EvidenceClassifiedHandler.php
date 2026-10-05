<?php

    namespace BayesianPlugin\Handlers;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Classes\Configuration;
    use BayesianPlugin\Classes\Logger;
    use BayesianPlugin\Exceptions\BayesianException;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Plugin\RecordChange;

    /**
     * Trains BayesianServer with the text content of evidence when it is classified (the EVIDENCE_CLASSIFIED record
     * change), either when the evidence is submitted with a classification, classified directly or classified by
     * closing a report
     */
    class EvidenceClassifiedHandler implements RecordChangeEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleRecordChange(RecordChange $change): void
        {
            if($change->getType() !== RecordChangeType::EVIDENCE_CLASSIFIED || !Configuration::isLearningEnabled())
            {
                return;
            }

            $evidence = $change->getRecord();
            if(!$evidence instanceof EvidenceRecord || $evidence->getClassificationFlag() === null)
            {
                return;
            }

            $textContent = $evidence->getTextContent();
            if($textContent === null || strlen($textContent) === 0)
            {
                return;
            }

            try
            {
                BayesianPlugin::getClient()->learn($textContent, $evidence->getClassificationFlag()->value);
            }
            catch(BayesianException $e)
            {
                Logger::log()->warning('Bayesian learn failed: ' . $e->getMessage());
            }
        }
    }
