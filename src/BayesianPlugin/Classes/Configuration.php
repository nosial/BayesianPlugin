<?php

    namespace BayesianPlugin\Classes;

    /**
     * The plugin's own configuration (ConfigLib configuration "bayesian_plugin"), every value can also be set with
     * its environment variable. The defaults connect to the BayesianServer that is bundled with the FederationLib
     * docker image.
     */
    class Configuration
    {
        private static ?\ConfigLib\Configuration $configuration = null;

        /**
         * Initializes the configuration with its default values
         *
         * @return void
         */
        public static function initialize(): void
        {
            self::$configuration = new \ConfigLib\Configuration('bayesian_plugin');

            self::$configuration->setDefault('ssl', false, 'BAYESIAN_PLUGIN_SSL');
            self::$configuration->setDefault('host', '127.0.0.1', 'BAYESIAN_PLUGIN_HOST');
            self::$configuration->setDefault('port', 6380, 'BAYESIAN_PLUGIN_PORT');
            // Skip the classification if the majority of the content's tokens are unknown to the model
            self::$configuration->setDefault('classify_known_tokens', true, 'BAYESIAN_PLUGIN_CLASSIFY_KNOWN_TOKENS');
            // The minimum number of training documents of the model, and of every label, before content is classified
            self::$configuration->setDefault('minimum_documents', 10, 'BAYESIAN_PLUGIN_MINIMUM_DOCUMENTS');
            // Submit the text of classified evidence to BayesianServer for training
            self::$configuration->setDefault('learning', true, 'BAYESIAN_PLUGIN_LEARNING');
            // Proxy BayesianServer's API below /bayesian for the root operator, disabled by default as it gives direct
            // access to the model (including training it)
            self::$configuration->setDefault('proxy', false, 'BAYESIAN_PLUGIN_PROXY');

            // Only save if the configuration file does not exist or we're in CLI mode
            if(!file_exists(self::$configuration->getPath()) || php_sapi_name() === 'cli')
            {
                self::$configuration->save();
            }
        }

        /**
         * Returns True if SSL is used to connect to BayesianServer
         *
         * @return bool True if SSL is used
         */
        public static function useSsl(): bool
        {
            return self::getBoolean('ssl', false);
        }

        /**
         * Returns the host of the BayesianServer to connect to
         *
         * @return string The host
         */
        public static function getHost(): string
        {
            return (string)self::get('host', '127.0.0.1');
        }

        /**
         * Returns the port of the BayesianServer to connect to
         *
         * @return int The port
         */
        public static function getPort(): int
        {
            return (int)self::get('port', 6380);
        }

        /**
         * Returns the BayesianServer endpoint built from the host, port and SSL configuration
         *
         * @return string The endpoint, eg; http://127.0.0.1:6380
         */
        public static function getEndpoint(): string
        {
            return sprintf('%s://%s:%d', self::useSsl() ? 'https' : 'http', self::getHost(), self::getPort());
        }

        /**
         * Returns True if classifications should be skipped when the majority of the content's tokens are unknown
         *
         * @return bool True to only classify known tokens
         */
        public static function classifyKnownTokens(): bool
        {
            return self::getBoolean('classify_known_tokens', true);
        }

        /**
         * Returns the minimum number of training documents of the model, and of every label, before content is
         * classified
         *
         * @return int The minimum number of training documents
         */
        public static function getMinimumDocuments(): int
        {
            return max(0, (int)self::get('minimum_documents', 10));
        }

        /**
         * Returns True if the text of classified evidence is submitted to BayesianServer for training
         *
         * @return bool True if learning is enabled
         */
        public static function isLearningEnabled(): bool
        {
            return self::getBoolean('learning', true);
        }

        /**
         * Returns True if BayesianServer's API is proxied below /bayesian for the root operator
         *
         * @return bool True if the proxy is enabled
         */
        public static function isProxyEnabled(): bool
        {
            return self::getBoolean('proxy', false);
        }

        /**
         * Returns a configuration value
         *
         * @param string $key The configuration key
         * @param mixed $default The value to return if the key does not exist
         * @return mixed The configuration value
         */
        private static function get(string $key, mixed $default): mixed
        {
            if(self::$configuration === null)
            {
                self::initialize();
            }

            return self::$configuration->get($key, $default);
        }

        /**
         * Returns a boolean configuration value, values set by environment variables are strings (eg; "false") and
         * are parsed accordingly
         *
         * @param string $key The configuration key
         * @param bool $default The value to return if the key does not exist or is not a boolean
         * @return bool The configuration value
         */
        private static function getBoolean(string $key, bool $default): bool
        {
            $value = self::get($key, $default);
            if(is_bool($value))
            {
                return $value;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }
    }
