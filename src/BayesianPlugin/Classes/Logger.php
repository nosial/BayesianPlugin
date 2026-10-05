<?php

    namespace BayesianPlugin\Classes;

    class Logger
    {
        private static ?\LogLib2\Logger $logger = null;

        /**
         * Returns the plugin's logger instance
         *
         * @return \LogLib2\Logger The logger
         */
        public static function log(): \LogLib2\Logger
        {
            if(self::$logger === null)
            {
                self::$logger = new \LogLib2\Logger('net.nosial.bayesian_plugin');
            }

            return self::$logger;
        }
    }
