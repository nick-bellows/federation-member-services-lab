<?php

namespace App\Federation\LearningCenter\Exceptions;

use RuntimeException;

/**
 * Base for every failure of the credentials contract. Messages are for logs
 * and never contain token contents.
 */
class LearningCenterException extends RuntimeException {}
