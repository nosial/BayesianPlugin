all: target/debug/net.nosial.bayesian_plugin.ncc target/release/net.nosial.bayesian_plugin.ncc
target/debug/net.nosial.bayesian_plugin.ncc:
	ncc build --configuration debug --log-level debug
target/release/net.nosial.bayesian_plugin.ncc:
	ncc build --configuration release --log-level debug

# FederationLib provides the plugin system and the server the tests run against, it is checked out in federation/
# unless FEDERATIONLIB_SOURCE points to another checkout, eg; make test-env FEDERATIONLIB_SOURCE=../FederationLib
FEDERATIONLIB_SOURCE ?= federation
FEDERATIONLIB_REPOSITORY ?= https://github.com/nosial/federationlib
FEDERATIONLIB_BRANCH ?= dev
FEDERATIONLIB_IMAGE ?= ghcr.io/nosial/federationlib:dev
TEST_COMPOSE = docker compose -f docker-compose.yml
SERVER_ENDPOINT ?= http://172.17.0.1:7000
BAYESIAN_SERVER_ENDPOINT ?= http://172.17.0.1:6380

federation:
	git clone --branch $(FEDERATIONLIB_BRANCH) $(FEDERATIONLIB_REPOSITORY) federation

# Imported by tests/bootstrap.php
federation/target/release/net.nosial.federation.ncc: | federation
	cd federation && ncc build --configuration release --log-level debug

# Builds FederationLib's own (production) docker image from federation/, the test image installs the plugin into it
federation-image: | $(FEDERATIONLIB_SOURCE)
	docker build -t $(FEDERATIONLIB_IMAGE) $(FEDERATIONLIB_SOURCE)

# Starts the docker environment from Dockerfile, a FederationLib server with the plugin installed and enabled
test-env: federation-image
	FEDERATIONLIB_IMAGE=$(FEDERATIONLIB_IMAGE) $(TEST_COMPOSE) up -d --build
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

.PHONY: all install clean test federation-image test-env test-env-down
