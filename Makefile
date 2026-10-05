all: target/debug/net.nosial.bayesian_plugin.ncc target/release/net.nosial.bayesian_plugin.ncc
target/debug/net.nosial.bayesian_plugin.ncc:
	ncc build --configuration debug --log-level debug
target/release/net.nosial.bayesian_plugin.ncc:
	ncc build --configuration release --log-level debug

TEST_COMPOSE = docker compose -f docker-compose.yml
SERVER_ENDPOINT ?= http://172.17.0.1:7000
BAYESIAN_SERVER_ENDPOINT ?= http://172.17.0.1:6380

# Starts the docker environment from Dockerfile, FederationLib's published dev image (pulled, a local copy may be
# outdated) with the plugin built from source, installed and enabled
test-env:
	$(TEST_COMPOSE) build --pull
	$(TEST_COMPOSE) up -d
	@echo "Waiting for the test environment to be ready..."
	@for i in $$(seq 1 60); do \
		if curl -sf -o /dev/null "$(SERVER_ENDPOINT)/" && curl -sf -o /dev/null "$(BAYESIAN_SERVER_ENDPOINT)/health"; then \
			echo "The test environment is ready"; exit 0; \
		fi; \
		sleep 5; \
	done; \
	echo "The test environment did not become ready within 5 minutes"; $(TEST_COMPOSE) logs app; exit 1

test-env-down:
	$(TEST_COMPOSE) down -v

test: target/release/net.nosial.bayesian_plugin.ncc
	phpunit --configuration phpunit.xml

clean:
	rm -f target/debug/net.nosial.bayesian_plugin.ncc
	rm -f target/release/net.nosial.bayesian_plugin.ncc

.PHONY: all install clean test test-env test-env-down
