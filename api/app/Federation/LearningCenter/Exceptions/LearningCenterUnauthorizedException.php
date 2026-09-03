<?php

namespace App\Federation\LearningCenter\Exceptions;

/**
 * The provider rejected the service token (401) or its scope (403). A
 * configuration problem on this side, never the member's.
 */
class LearningCenterUnauthorizedException extends LearningCenterException {}
