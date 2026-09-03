<?php

namespace Tests\Support;

use App\Federation\LearningCenter\CredentialFacts;
use App\Federation\LearningCenter\CredentialsClient;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnavailableException;

/**
 * The test world's default Learning Center: absent. Approvals still succeed,
 * no snapshot is written and no HTTP leaves the process. Tests that need the
 * provider fake it explicitly (see CredentialSnapshotsTest).
 */
final class UnavailableCredentialsClient implements CredentialsClient
{
    public int $calls = 0;

    public function fetch(string $subject): CredentialFacts
    {
        $this->calls++;

        throw new LearningCenterUnavailableException('Learning Center is not part of this test');
    }
}
