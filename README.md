# BayesianPlugin

A [FederationLib](https://git.n64.cc/nosial/federationlib) plugin that classifies scanned content with
[BayesianServer](https://github.com/nosial/BayesianServer) and trains it with the classifications assigned to evidence.
It provides FederationLib's Bayesian classification and training, which used to be built into FederationLib.

## Table of contents

<!-- TOC -->
* [BayesianPlugin](#bayesianplugin)
  * [Table of contents](#table-of-contents)
  * [What it does](#what-it-does)
  * [Configuration](#configuration)
  * [Installation](#installation)
    * [Docker](#docker)
  * [Migrating from FederationLib's built-in implementation](#migrating-from-federationlibs-built-in-implementation)
  * [Building and testing](#building-and-testing)
    * [Test environment](#test-environment)
    * [Running the tests](#running-the-tests)
* [License](#license)
<!-- TOC -->

## What it does

The plugin does two things: it classifies the content FederationLib scans, and it learns from the classifications
operators give to evidence.

**Classifying content.** When content is scanned with `POST /scan`, every evidence item that has text is sent to
BayesianServer and classified as `NORMAL`, `SUSPICIOUS` or `MALICIOUS`. FederationLib takes it from there: the
classification appears in the scan's `classification`, counts towards the risk score through the `CLASSIFICATION_*`
scanning rules, and describes the evidence of reports that are generated automatically from a scan. The scan
request's `top_k` and `threshold` parameters are passed on to BayesianServer.

**Learning from classifications.** Whenever evidence is given a classification, its text is sent to BayesianServer to
train the model. This happens when:

 - evidence is submitted with a classification,
 - evidence is classified with `PATCH /evidence/{uuid}/classify`,
 - a report is closed with a classification.

Learning can be turned off with the `learning` option.

**When content isn't classified.** The plugin would rather not classify content than guess. It skips the
classification when:

 - the model hasn't been trained enough yet, it needs at least `minimum_documents` documents in total and for each of
   `NORMAL`, `SUSPICIOUS` and `MALICIOUS`,
 - the model contains a label the plugin doesn't know,
 - most of the content's words are unknown to the model (`classify_known_tokens`).

The confidence of a classification is the probability BayesianServer gives its top label.

**If BayesianServer is unavailable**, scans and other operations still succeed, the content just isn't classified
and the error is logged.

| Event handler               | Handles                                                   |
|-----------------------------|-----------------------------------------------------------|
| `ContentScanHandler`        | `CONTENT_SCAN`, classifies scanned content                |
| `EvidenceClassifiedHandler` | `RECORD_CHANGE` (`EVIDENCE_CLASSIFIED`), trains the model |

## Configuration

The plugin has its own ConfigLib configuration named `bayesian_plugin`, every value can also be set with its
environment variable. The defaults connect to the BayesianServer bundled with the FederationLib docker image.

| Name                    | Environment Variable                    | Type    | Default Value | Description                                                                          |
|-------------------------|-----------------------------------------|---------|---------------|--------------------------------------------------------------------------------------|
| `host`                  | `BAYESIAN_PLUGIN_HOST`                  | string  | `127.0.0.1`   | The host of BayesianServer                                                           |
| `port`                  | `BAYESIAN_PLUGIN_PORT`                  | integer | `6380`        | The port of BayesianServer                                                           |
| `ssl`                   | `BAYESIAN_PLUGIN_SSL`                   | boolean | `false`       | Connect to BayesianServer with HTTPS                                                 |
| `classify_known_tokens` | `BAYESIAN_PLUGIN_CLASSIFY_KNOWN_TOKENS` | boolean | `true`        | Skip the classification when the majority of the content's tokens are unknown        |
| `minimum_documents`     | `BAYESIAN_PLUGIN_MINIMUM_DOCUMENTS`     | integer | `10`          | Training documents required in total and per label before content is classified      |
| `learning`              | `BAYESIAN_PLUGIN_LEARNING`              | boolean | `true`        | Train BayesianServer with the text content of classified evidence                    |

## Installation

The plugin is loaded by FederationLib through the `plugins` configuration (`FEDERATION_PLUGINS`) using its package
name, `net.nosial.bayesian_plugin`. FederationLib (and ConfigLib and LogLib2) are provided by FederationLib at runtime.

```shell
make target/release/net.nosial.bayesian_plugin.ncc
ncc install --package="$PWD/target/release/net.nosial.bayesian_plugin.ncc" --yes --reinstall
```

### Docker

The FederationLib docker image installs plugins listed in `REQUIRE_PLUGINS` before it initializes:

```yaml
environment:
  - REQUIRE_PLUGINS=/opt/plugins/net.nosial.bayesian_plugin.ncc   # Anything `ncc install` accepts
  - FEDERATION_PLUGINS=net.nosial.bayesian_plugin
```

## Migrating from FederationLib's built-in implementation

No migration is needed, FederationLib includes this plugin as one of the default plugins, the plugin uses the same 
BayesianServer and model, nothing needs to be retrained.

This plugin does not conflict with any other plugin by default.

## Building and testing

```shell
make                  # Builds target/release and target/debug
make test-env         # Starts the test environment (FederationLib with the plugin installed, see below)
make test             # Runs the tests (phpunit) against the test environment, requires the release build
make test-env-down    # Removes the test environment and its data
```

### Test environment

The tests run against a live FederationLib server with the plugin installed, started with Docker Compose
(`docker-compose.yml`):

| Service   | Description                                                                                          | Port                            |
|-----------|------------------------------------------------------------------------------------------------------|---------------------------------|
| `app`     | FederationLib's published `dev` image (`ghcr.io/nosial/federationlib:dev`) with the plugin installed | `7000` (`FEDERATION_PORT`)      |
|           | The BayesianServer bundled with FederationLib's image, the one the plugin uses                       | `6380` (`BAYESIAN_SERVER_PORT`) |
| `mariadb` | FederationLib's database                                                                             | -                               |
| `redis`   | FederationLib's cache                                                                                | -                               |

The `Dockerfile` builds the plugin from source and adds it to FederationLib's image. FederationLib's entrypoint
installs it and enables it through `REQUIRE_PLUGINS` and `FEDERATION_PLUGINS`. The image is only used for testing and
is never published.

 - `make test-env` builds with `--pull`, so the tests always run against the latest build of FederationLib's `dev`
   image rather than an outdated local copy. It then waits until FederationLib and BayesianServer respond.
 - The plugin inside the container is the one built when the image was built, not `target/release`. Run
   `make test-env` again after changing the plugin, otherwise the tests against the server use the old plugin.
 - Nothing is persisted: `make test-env-down` removes the database and the trained model.

### Running the tests

The tests import FederationLib from the installed `net.nosial.federation` package. They're run against FederationLib's
`dev` branch, so the plugin is always tested against the latest working build of the server. Install FederationLib from
a checkout of that branch (its dependencies are installed with it), then the plugin package:

```shell
git clone --branch dev https://github.com/nosial/federationlib
(cd federationlib && ncc build --configuration release && ncc install --package="$PWD/target/release/net.nosial.federation.ncc" --yes --reinstall)
make target/release/net.nosial.bayesian_plugin.ncc
ncc install --package="$PWD/target/release/net.nosial.bayesian_plugin.ncc" --yes --reinstall
```

The tests are configured by `phpunit.xml`, an environment variable that is already set takes precedence:

| Environment Variable       | Default                            | Description                                                          |
|----------------------------|------------------------------------|----------------------------------------------------------------------|
| `SERVER_ENDPOINT`          | `http://172.17.0.1:7000`           | The FederationLib server of the test environment                     |
| `SERVER_ACCESS_TOKEN`      | `abcdefghijklmnopqrstuvwxyz123456` | Its access token (`FEDERATION_ACCESS_TOKEN` of `docker-compose.yml`) |
| `BAYESIAN_SERVER_ENDPOINT` | `http://172.17.0.1:6380`           | The BayesianServer bundled with that FederationLib server            |
| `NCC_BUILD_OUTPUT_PATH`    | `target/release/...`               | The plugin `.ncc` package to import                                  |

The endpoints use the Docker host's bridge address (`172.17.0.1`), the same as FederationLib's tests, so they work
from the host and from a CI job's container. Where the bridge isn't reachable (eg; Docker Desktop), use
`http://127.0.0.1:7000` and `http://127.0.0.1:6380`. The tests train the BayesianServer, so only run them against
the test environment. A test fails rather than being skipped if a server is unreachable.

# License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.