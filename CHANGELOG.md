# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.3] - Ongoing

This is an ongoing update


## [1.0.2] - 2026-10-30

This update introduces new improvements and fixes

### Added
 - `minimum_confidence` (`BAYESIAN_PLUGIN_MINIMUM_CONFIDENCE`, default `0.9`): a `SUSPICIOUS` or `MALICIOUS`
   classification is only reported when the probability that the content isn't `NORMAL` reaches it
 - `BayesianClientInterface::correct()` corrects the labels of a learned text (`PATCH /`), falling back to `learn()`
   for servers without corrections
 - `BayesianClientInterface::classify()` takes an optional `$negativeLabel`, sent as `negative_label` (BayesianServer
   1.0.4 and later)
 - `implicit_learning` (default `true`): scanned content is classified with `implicit_label` `NORMAL`, so
   BayesianServer learns content it classified as `NORMAL` later unless it's classified in the meantime
 - `EvidenceDeletedHandler` forgets the content of deleted evidence (`DELETE /`), unless other classified evidence has
   the same text, which is learned with its classification instead
 - `Learner`, how classifications are learned, shared with FederationLib's `bayesian-sync` and model rebuilds
 - `spam_severity` (default `upgrade`): spam is confirmed as `MALICIOUS` by signals the text can't carry reliably, a
   contact route in the content (link, Telegram link, handle, phone number or a resolved named entity) or a
   blacklisted or badly reputed author. `upgrade` raises confirmed `SUSPICIOUS` spam to `MALICIOUS`, `confirm` applies
   the label policy strictly, `model` keeps the model's label
 - `BayesianClientInterface::classifyBatch()`, `forget()` and a `$weight` for `learn()` and `correct()`

### Changed
 - Included the project's light and dark icons from NosialFrontier in the README header.
 - A `SUSPICIOUS` or `MALICIOUS` top label that BayesianServer does not predict (below its decision threshold) is no
   longer reported
 - The confidence of a `SUSPICIOUS` or `MALICIOUS` classification is the probability that the content isn't `NORMAL`
   instead of the probability of the label alone
 - Classified evidence is learned as a correction, so reclassified evidence replaces what the model learned before
 - Every evidence item of a scan is classified in one request (one request per item with servers before batches)
 - The readiness of the model is cached for 30 seconds with APCu instead of being asked from BayesianServer on every
   scan
 - `classify_known_tokens` only applies to the Naive Bayes models, the logistic model scores the characters of unknown
   words, which is how it recognizes new spelling variants of spam
 - Content is classified with `NORMAL` as the negative label, so the logistic model of BayesianServer reports
   `SUSPICIOUS` or `MALICIOUS` only when the content is unlikely enough to be `NORMAL` (calibrated for its false
   positive rate). BayesianServer no longer needs to be started with `--negative-label NORMAL`
 - A `SUSPICIOUS` or `MALICIOUS` label BayesianServer predicts is reported even when `NORMAL` is the most probable
   single label, eg; when `SUSPICIOUS` and `MALICIOUS` together outweigh it
 - Updated the project to use ncc 3.1.11

### Fixed
 - Corrected update_source to reference BayesianPlugin instead of FederationLib.


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
