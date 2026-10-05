<?php

    /** @noinspection PhpUnhandledExceptionInspection */

    namespace BayesianPlugin\Tests;

    use BayesianPlugin\BayesianPlugin;
    use BayesianPlugin\Handlers\BayesianProxyHandler;
    use BayesianPlugin\Tests\Helpers\FakeBayesianClient;
    use FederationLib\Classes\PluginManager;
    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\EventType;
    use FederationLib\Enums\ScanningRules;
    use FederationLib\Objects\ContentInput;
    use FederationLib\Objects\Plugin;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\ScannedContent;
    use LogLib2\Logger;
    use PHPUnit\Framework\TestCase;

    /**
     * Tests the plugin through FederationLib's plugin system
     */
    class PluginTest extends TestCase
    {
        private const string PACKAGE = 'net.nosial.bayesian_plugin';

        protected function tearDown(): void
        {
            PluginManager::setPlugins([]);
            BayesianPlugin::setClient(null);
            Logger::unregisterHandlers();
        }

        public function testPluginIsValid(): void
        {
            $plugin = Plugin::load(self::PACKAGE);

            // The BayesianServer proxy, a new route for every method BayesianServer's API uses (including PUSH)
            $this->assertCount(1, $plugin->getRequestHandlers());
            $proxy = $plugin->getRequestHandlers()[0];
            $this->assertSame('/bayesian/*', $proxy->getPath());
            $this->assertSame(BayesianProxyHandler::class, $proxy->getClass());
            $this->assertNull($proxy->getExecutionPriority());
            foreach(['GET', 'POST', 'PUSH', 'HEAD'] as $method)
            {
                $this->assertContains($method, $proxy->getRequestMethods());
            }

            $this->assertCount(1, $plugin->getEventHandlers(EventType::CONTENT_SCAN));
            $this->assertCount(1, $plugin->getEventHandlers(EventType::RECORD_CHANGE));
            $this->assertSame(['EVIDENCE_CLASSIFIED'], $plugin->getEventHandlers(EventType::RECORD_CHANGE)[0]->getFilter());
            $this->assertSame([], $plugin->getEventHandlers(EventType::AUDIT_LOG));

            $result = PluginManager::validateRoutes([$plugin]);
            $this->assertSame([], $result['errors']);
            $this->assertSame([], $result['warnings']);
        }

        public function testScanResultsIncludeTheClassification(): void
        {
            $client = new FakeBayesianClient();
            $client->classifications['bad'] = FakeBayesianClient::classification('MALICIOUS', 0.9);
            BayesianPlugin::setClient($client);
            PluginManager::setPlugins([Plugin::load(self::PACKAGE)]);

            $contentScan = new ContentScan([new ContentInput('bad')], null, null, []);
            PluginManager::dispatchContentScan($contentScan);

            // The same way ScanContent builds the scan results
            $scannedContent = new ScannedContent([], null, $contentScan->getAddedClassifications(), $contentScan->getScanResults());
            $this->assertSame(ClassificationFlag::MALICIOUS, $scannedContent->getClassification()->getClassificationFlag());
            $this->assertLessThan(0.0, $scannedContent->getScanResults()[ScanningRules::CLASSIFICATION_MALICIOUS->name]);
            $this->assertGreaterThan(new ScannedContent([])->getRiskScore(), $scannedContent->getRiskScore());
        }

        public function testProxyPathMapsToBayesianServerPath(): void
        {
            $this->assertSame('/', BayesianProxyHandler::getBayesianPath('/bayesian'));
            $this->assertSame('/', BayesianProxyHandler::getBayesianPath('/bayesian/'));
            $this->assertSame('/health', BayesianProxyHandler::getBayesianPath('/bayesian/health'));
            $this->assertSame('/analytics', BayesianProxyHandler::getBayesianPath('/bayesian/analytics'));
            $this->assertSame('/some/nested/path/', BayesianProxyHandler::getBayesianPath('/bayesian/some/nested/path/'));
        }
    }
