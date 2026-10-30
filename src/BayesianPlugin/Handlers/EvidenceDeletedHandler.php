<?php

    namespace BayesianPlugin\Handlers;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Classes\Configuration;
    use BayesianPlugin\Classes\Learner;
    use BayesianPlugin\Classes\Logger;
    use BayesianPlugin\Exceptions\BayesianException;
    use FederationLib\Enums\RecordChangeType;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Interfaces\RecordChangeEventHandlerInterface;
    use FederationLib\Objects\EvidenceRecord;
    use FederationLib\Objects\Plugin\RecordChange;

    class EvidenceDeletedHandler implements RecordChangeEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleRecordChange(RecordChange $change): void
        {
            if($change->getType() !== RecordChangeType::EVIDENCE_DELETED || !Configuration::isLearningEnabled())
            {
                return;
            }

            $evidence = $change->getRecord();
            if(!$evidence instanceof EvidenceRecord)
            {
                return;
            }

            try
            {
                $result = Learner::forget(BayesianPlugin::getClient(), $evidence);
                if($result !== null && !$result->isAccepted())
                {
                    Logger::log()->warning('Bayesian forget rejected, the learning queue is full');
                }
            }
            catch(BayesianException|DatabaseOperationException $e)
            {
                Logger::log()->warning('Bayesian forget failed: ' . $e->getMessage());
            }
        }
    }
