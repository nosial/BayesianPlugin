<?php

    namespace BayesianPlugin\Exceptions;

    use Exception;

    /**
     * Thrown when a request to BayesianServer fails. This is intentionally not a FederationLib RequestException, a
     * RequestException thrown by a CONTENT_SCAN event handler rejects the scan request, while an unavailable
     * BayesianServer must only skip the classification.
     */
    class BayesianException extends Exception
    {
    }
