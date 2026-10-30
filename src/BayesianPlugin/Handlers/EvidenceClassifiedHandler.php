<?php

    namespace BayesianPlugin\Handlers;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Classes\Configuration;
    use BayesianPlugin\Classes\Learner;
    use BayesianPlugin\Classes\Logger;
    use BayesianPlugin\Exceptions\BayesianException;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Plugin\RecordChange;

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
                $result = Learner::learn(BayesianPlugin::getClient(), $textContent, $evidence->getClassificationFlag()->value);
                if(!$result->isAccepted())
                {
                    Logger::log()->warning('Bayesian learn rejected, the learning queue is full; bayesian-sync will learn it later');
                }
            }
            catch(BayesianException $e)
            {
                Logger::log()->warning('Bayesian learn failed, bayesian-sync will learn it later: ' . $e->getMessage());
            }
        }
    }
