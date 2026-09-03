<?php

namespace App\Federation\Exceptions;

use RuntimeException;

/**
 * Base class for rule violations in the federation domain. Callers map these
 * to HTTP responses; the domain never speaks HTTP.
 */
abstract class FederationDomainException extends RuntimeException {}
