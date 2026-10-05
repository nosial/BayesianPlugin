<?php

    namespace BayesianPlugin\Handlers;

    use BayesianPlugin\Classes\Configuration;
    use BayesianPlugin\Classes\Logger;
    use FederationLib\Classes\Managers\OperatorManager;
    use FederationLib\Classes\PluginRequestHandler;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;

    /**
     * Proxies every request below /bayesian to BayesianServer's API, eg; `POST /bayesian/analytics` is sent to
     * BayesianServer as `POST /analytics`, and responds with BayesianServer's response as-is (status code, content type
     * and body). Only the root operator may use it, the request's own Authorization header is never forwarded.
     *
     * The proxy is disabled unless the "proxy" configuration (BAYESIAN_PLUGIN_PROXY) is enabled, the route then responds
     * like a route that doesn't exist.
     */
    class BayesianProxyHandler extends PluginRequestHandler
    {
        /**
         * The path prefix of the proxy, the remainder of the path is the path of BayesianServer's API
         */
        public const string PATH_PREFIX = '/bayesian';

        private const int TIMEOUT = 60;

        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            // Checked before authenticating, so a disabled proxy can't be told apart from a route that doesn't exist
            if(!Configuration::isProxyEnabled())
            {
                self::errorResponse('Invalid request method or path.', HttpResponseCode::BAD_REQUEST->value);
                return;
            }

            $operator = FederationServer::requireAuthenticatedOperator();
            if(!OperatorManager::isRootOperator($operator->getUuid()))
            {
                throw new RequestException('Only the root operator can access BayesianServer', HttpResponseCode::FORBIDDEN);
            }

            $url = rtrim(Configuration::getEndpoint(), '/') . self::getBayesianPath(self::getPath() ?? '/');
            $query = parse_url(self::getUri() ?? '', PHP_URL_QUERY);
            if(is_string($query) && $query !== '')
            {
                $url .= '?' . $query;
            }

            $method = self::getRequestMethod() ?? 'GET';
            $body = self::getInputContent();
            $headers = [];
            if(!empty($_SERVER['CONTENT_TYPE']))
            {
                $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
            }
            if(!empty($_SERVER['HTTP_ACCEPT']))
            {
                $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
            }

            $contentType = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_NOBODY => $method === 'HEAD',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HEADERFUNCTION => static function($ch, string $header) use (&$contentType): int
                {
                    if(stripos($header, 'Content-Type:') === 0)
                    {
                        $contentType = trim(substr($header, strlen('Content-Type:')));
                    }

                    return strlen($header);
                },
            ]);

            if($body !== null && $body !== '')
            {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            if($response === false)
            {
                $error = curl_error($ch);
                $timedOut = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
                Logger::log()->error(sprintf('BayesianServer proxy request [%s] %s failed: %s', $method, $url, $error));
                throw new RequestException(sprintf('BayesianServer is unavailable: %s', $error), $timedOut ? HttpResponseCode::GATEWAY_TIMEOUT : HttpResponseCode::BAD_GATEWAY);
            }

            // Respond with BayesianServer's response as-is, rather than with FederationLib's JSON responses
            self::markResponseSent();
            http_response_code(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
            if($contentType !== null)
            {
                header('Content-Type: ' . $contentType);
            }

            print($response);
        }

        /**
         * Returns the path of BayesianServer's API for the request path, eg; `/analytics` for `/bayesian/analytics`
         *
         * @param string $path The request path, starting with the proxy's path prefix
         * @return string The path of BayesianServer's API, always starting with a slash
         */
        public static function getBayesianPath(string $path): string
        {
            $path = substr($path, strlen(self::PATH_PREFIX));
            return '/' . ltrim($path, '/');
        }
    }
