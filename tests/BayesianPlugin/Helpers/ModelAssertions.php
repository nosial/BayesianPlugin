<?php

    namespace BayesianPlugin\Tests\Helpers;

    use FederationLib\Enums\ClassificationFlag;

    /**
     * Assertions about how well content is classified that hold for any reasonable model, they never depend on the
     * confidences or on the classification of a single sample, which may change with BayesianServer's implementation,
     * only on the model telling the samples of every flag apart
     */
    trait ModelAssertions
    {
        private const string UNCLASSIFIED = 'UNCLASSIFIED';

        /**
         * Classifies every sample of every flag and asserts the classifications tell the flags apart:
         *
         *  - Most NORMAL samples are classified NORMAL
         *  - Most SUSPICIOUS and MALICIOUS samples are classified as a threat (SUSPICIOUS or MALICIOUS), the two
         *    overlap so a threat may be classified as either of them
         *  - Every flag is classified NORMAL or MALICIOUS more often for its own samples than for the samples of any
         *    other flag
         *
         * A sample that is not classified counts as a wrong classification.
         *
         * @param callable(string): ?ClassificationFlag $classify Classifies a sample, null if it was not classified
         * @param callable(ClassificationFlag): string[] $samples Returns the samples of a flag
         * @param string $description What is classified, for the failure message
         * @return void
         */
        private function assertDistinguishesFlags(callable $classify, callable $samples, string $description): void
        {
            /** @var array<string, array<string, int>> $predictions The number of predictions per flag of the samples */
            $predictions = [];
            foreach(ClassificationFlag::cases() as $flag)
            {
                $flagSamples = $samples($flag);
                $this->assertNotEmpty($flagSamples, sprintf('There are no %s samples', $flag->value));

                $predictions[$flag->value] = array_fill_keys([...array_map(fn(ClassificationFlag $f) => $f->value, ClassificationFlag::cases()), self::UNCLASSIFIED], 0);
                foreach($flagSamples as $sample)
                {
                    $predictions[$flag->value][$classify($sample)?->value ?? self::UNCLASSIFIED]++;
                }
            }

            $report = sprintf("%s, the classifications of the samples of every flag:\n%s", $description, self::formatPredictions($predictions));
            $rate = fn(ClassificationFlag $samplesOf, ClassificationFlag ...$classifiedAs) => array_sum(array_map(fn(ClassificationFlag $f) => $predictions[$samplesOf->value][$f->value], $classifiedAs)) / array_sum($predictions[$samplesOf->value]);

            $this->assertGreaterThan(0.5, $rate(ClassificationFlag::NORMAL, ClassificationFlag::NORMAL), "Most NORMAL samples must be classified NORMAL\n" . $report);
            foreach([ClassificationFlag::SUSPICIOUS, ClassificationFlag::MALICIOUS] as $threat)
            {
                $this->assertGreaterThan(0.5, $rate($threat, ClassificationFlag::SUSPICIOUS, ClassificationFlag::MALICIOUS), sprintf("Most %s samples must be classified as a threat\n%s", $threat->value, $report));
            }

            foreach([ClassificationFlag::NORMAL, ClassificationFlag::MALICIOUS] as $flag)
            {
                foreach(ClassificationFlag::cases() as $other)
                {
                    if($other !== $flag)
                    {
                        $this->assertGreaterThan($rate($other, $flag), $rate($flag, $flag), sprintf("%s must be classified more often for %s samples than for %s samples\n%s", $flag->value, $flag->value, $other->value, $report));
                    }
                }
            }
        }

        /**
         * @param array<string, array<string, int>> $predictions
         * @return string
         */
        private static function formatPredictions(array $predictions): string
        {
            $lines = [];
            foreach($predictions as $flag => $counts)
            {
                $lines[] = sprintf('  %-10s samples: %s', $flag, implode(', ', array_map(fn($label, $count) => sprintf('%s=%d', $label, $count), array_keys($counts), $counts)));
            }

            return implode("\n", $lines);
        }
    }
