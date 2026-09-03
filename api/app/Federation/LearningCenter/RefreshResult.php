<?php

namespace App\Federation\LearningCenter;

use App\Federation\Models\CredentialSnapshot;

final class RefreshResult
{
    public function __construct(
        public readonly CredentialSnapshot $snapshot,
        public readonly bool $changed,
    ) {}
}
