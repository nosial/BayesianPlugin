<?php

    namespace BayesianPlugin\Tests\Helpers;

    use FederationLib\Enums\ClassificationFlag;

    /**
     * Curated text samples (one per line in data/<flag>.txt, the same samples FederationLib's tests use), the first
     * samples of every flag are used for training and the remaining samples are never trained on
     */
    class TrainingData
    {
        public const int TRAINING_SAMPLES = 15;

        /** @var array<string, string[]> */
        private static array $samples = [];

        /**
         * Returns every sample of the given flag
         *
         * @return string[]
         */
        public static function samples(ClassificationFlag $flag): array
        {
            if(!isset(self::$samples[$flag->value]))
            {
                $lines = file(__DIR__ . '/data/' . strtolower($flag->value) . '.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                self::$samples[$flag->value] = $lines === false ? [] : array_values($lines);
            }

            return self::$samples[$flag->value];
        }

        /**
         * Returns the samples used for training
         *
         * @return string[]
         */
        public static function trainingSamples(ClassificationFlag $flag): array
        {
            return array_slice(self::samples($flag), 0, self::TRAINING_SAMPLES);
        }

        /**
         * Returns the samples that are never trained on
         *
         * @return string[]
         */
        public static function testSamples(ClassificationFlag $flag): array
        {
            return array_slice(self::samples($flag), self::TRAINING_SAMPLES);
        }
    }
