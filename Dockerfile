#
#   BayesianPlugin Test Docker Image
#
#   The FederationLib FederationServer image (FEDERATIONLIB_IMAGE) with BayesianPlugin built and included, the plugin
#   is installed and enabled by FederationLib's docker-entrypoint.sh through REQUIRE_PLUGINS and FEDERATION_PLUGINS
#   (see docker-compose.test.yml). FederationLib's image bundles the BayesianServer the plugin connects to.
#
#   FEDERATIONLIB_IMAGE defaults to the image built from the FederationLib checkout in federation/ by
#   "make federation-image", this image is only used by the test environment and is never published.
#
FROM ghcr.io/nosial/ncc:dev AS plugin_builder
WORKDIR /plugin
COPY . /plugin
RUN ncc build --configuration release

FROM ghcr.io/nosial/federationlib:dev AS test

LABEL org.opencontainers.image.title="BayesianPlugin Test" \
      org.opencontainers.image.description="FederationServer Docker image with BayesianPlugin, for testing only" \
      org.opencontainers.image.vendor="Nosial"

# Installed by docker-entrypoint.sh through REQUIRE_PLUGINS (see docker-compose.test.yml)
COPY --from=plugin_builder /plugin/target/release/net.nosial.bayesian_plugin.ncc /opt/federationlib/plugins/net.nosial.bayesian_plugin.ncc
