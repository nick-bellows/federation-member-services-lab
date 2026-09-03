<?php

namespace App\Federation\LearningCenter\Exceptions;

/**
 * Timeout, connection failure or a 5xx: the answer is unknown for now, not wrong.
 */
class LearningCenterUnavailableException extends LearningCenterException {}
