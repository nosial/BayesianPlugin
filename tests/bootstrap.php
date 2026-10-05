<?PHP
        require 'ncc';

        // FederationLib provides the plugin system, it is installed on the system (net.nosial.federation)
        import('net.nosial.federation');

        $buildOutputPath = __DIR__ . DIRECTORY_SEPARATOR . '../target/release/net.nosial.bayesian_plugin.ncc';
        if(getenv('NCC_BUILD_OUTPUT_PATH'))
        {
            $buildOutputPath = getenv('NCC_BUILD_OUTPUT_PATH');
        }

        if(!file_exists($buildOutputPath))
        {
            throw new Exception('Build output not found: ' . $buildOutputPath);
        }

        import($buildOutputPath);

        require __DIR__ . '/BayesianPlugin/Helpers/FakeBayesianClient.php';
        require __DIR__ . '/BayesianPlugin/Helpers/TrainingData.php';
        require __DIR__ . '/BayesianPlugin/Helpers/BayesianServerHelper.php';
        require __DIR__ . '/BayesianPlugin/Helpers/ModelAssertions.php';

        // LogLib2's handlers may interfere with tests, so we unregister them here.
        \LogLib2\Logger::unregisterHandlers();
