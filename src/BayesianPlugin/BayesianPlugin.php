<?php

    namespace BayesianPlugin;

    use BayesianPlugin\Classes\BayesianClient;
    use BayesianPlugin\Interfaces\BayesianClientInterface;

    class BayesianPlugin
    {
        private static ?BayesianClientInterface $client = null;

        /**
         * Returns the BayesianServer client, created from the plugin's configuration on first use
         *
         * @return BayesianClientInterface The client
         */
        public static function getClient(): BayesianClientInterface
        {
            if(self::$client === null)
            {
                self::$client = new BayesianClient();
            }

            return self::$client;
        }

        /**
         * Replaces the BayesianServer client, null resets it to the client created from the plugin's configuration
         *
         * @param BayesianClientInterface|null $client The client
         * @return void
         */
        public static function setClient(?BayesianClientInterface $client): void
        {
            self::$client = $client;
        }
    }
