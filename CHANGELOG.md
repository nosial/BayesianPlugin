# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - Ongoing

This is an ongoing update


## [1.0.1] - 2026-10-0

This update adds optional direct access to BayesianServer's API through FederationLib for the root operator.

### Added
 - `proxy` option (`BAYESIAN_PLUGIN_PROXY`, disabled by default) to proxy BayesianServer's API below `/bayesian` for
   the root operator, eg; `POST /bayesian/analytics` to `POST /analytics`. Requests are passed on to BayesianServer
   as-is (including `PUSH` for training) and its responses are returned unchanged. While disabled, `/bayesian` responds
   like a path that doesn't exist
 - Tests of the proxy against the test environment, which enables it
 - `BayesianClient` accepts additional HTTP headers sent with every request, eg; to authenticate with the proxy

### Changed
 - The tests reach BayesianServer through the proxy as the root operator, the test environment no longer publishes
   BayesianServer's port and `BAYESIAN_SERVER_ENDPOINT` is no longer used
 - The handlers moved from the `BayesianPlugin\EventHandlers` namespace to `BayesianPlugin\Handlers`
 - Requires a FederationLib version that supports the `PUSH` request method for plugin routes, otherwise FederationLib
   rejects the plugin



## [1.0.0] - 2026-10-05

Initial release of BayesianPlugin