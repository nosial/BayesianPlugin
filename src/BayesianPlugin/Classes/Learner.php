<?php

    namespace BayesianPlugin\Classes;

    use BayesianPlugin\Exceptions\BayesianException;
    use BayesianPlugin\Interfaces\BayesianClientInterface;
    use BayesianPlugin\Objects\BayesianLearn;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Objects\EvidenceRecord;

    /**
     * Teaches BayesianServer the classifications of evidence. Used for classifications as they happen, and by
     * FederationLib to learn the classifications BayesianServer missed or to rebuild a model, so a classification is
     * learned the same way however it's learned.
     */
    class Learner
    {
        /**
         * Learns a classification as a correction, so the latest classification of a text replaces what the model
         * learned about it before
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @param string $text The text content of the evidence
         * @param string $label The classification flag
         * @return BayesianLearn The response, not accepted when the learning queue is full
         * @throws BayesianException If the request failed
         */
        public static function learn(BayesianClientInterface $client, string $text, string $label): BayesianLearn
        {
            return $client->correct($text, $label);
        }

        /**
         * Forgets the content of deleted evidence. Other classified evidence with the same text keeps the text
         * learned: it's learned again with the latest of their classifications instead.
         *
         * @param BayesianClientInterface $client The BayesianServer client
         * @param EvidenceRecord $deleted The evidence as it was before it was deleted
         * @return BayesianLearn|null The response, null if there was nothing to do
         * @throws BayesianException If the request failed
         * @throws DatabaseOperationException If other evidence with the same text could not be retrieved
         */
        public static function forget(BayesianClientInterface $client, EvidenceRecord $deleted): ?BayesianLearn
        {
            $text = $deleted->getTextContent();
            if($text === null || strlen($text) === 0 || $deleted->getClassificationFlag() === null)
            {
                return null;
            }

            $other = EvidenceManager::getLatestClassifiedByText($text, $deleted->getUuid());
            if($other !== null)
            {
                return self::learn($client, $text, $other['classification_flag']);
            }

            return $client->forget($text);
        }
    }
