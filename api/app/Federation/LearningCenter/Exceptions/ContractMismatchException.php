<?php

namespace App\Federation\LearningCenter\Exceptions;

/**
 * The provider answered with a shape this consumer does not understand
 * (wrong contract version, missing field, unknown status).
 */
class ContractMismatchException extends LearningCenterException {}
