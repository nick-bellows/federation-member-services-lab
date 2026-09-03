<?php

namespace App\Federation\Enums;

/**
 * Who may perform a transition, relative to the application: the person who
 * filed it, or someone who reviews for its organization or federation.
 */
enum ApplicationActor: string
{
    case APPLICANT = 'applicant';
    case REVIEWER = 'reviewer';
}
