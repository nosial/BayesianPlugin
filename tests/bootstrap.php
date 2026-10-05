<?PHP
        require 'ncc';

        // FederationLib provides the plugin system, it is imported from FEDERATIONLIB_PATH, otherwise from the build
        // output of a FederationLib checkout in federation/ (as the CI does), otherwise from the installed package. It
        // must be imported before the plugin: importing the plugin also imports its net.nosial.federation dependency
        // from the installed packages (eg; the latest release installed by "ncc project install"), and ncc skips a
        // package that is already imported
        $federationLibPath = __DIR__ . DIRECTORY_SEPARATOR . '../federation/target/release/net.nosial.federation.ncc';
        if(getenv('FEDERATIONLIB_PATH'))
        {
            $federationLibPath = getenv('FEDERATIONLIB_PATH');
        }

        if(file_exists($federationLibPath))
        {
            import($federationLibPath);
        }
        else
        {
            try
            {
                import('net.nosial.federation');
            }
            catch(Throwable $e)
            {
                throw new Exception('FederationLib not found, install net.nosial.federation, set FEDERATIONLIB_PATH or build it in federation/: ' . $e->getMessage(), 0, $e);
            }
        }

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
