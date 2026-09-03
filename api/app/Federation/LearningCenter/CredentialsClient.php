<?php

namespace App\Federation\LearningCenter;

use App\Federation\LearningCenter\Exceptions\ContractMismatchException;
use App\Federation\LearningCenter\Exceptions\LearningCenterMemberNotFoundException;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnauthorizedException;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnavailableException;

interface CredentialsClient
{
    /**
     * @throws LearningCenterMemberNotFoundException
     * @throws LearningCenterUnauthorizedException
     * @throws LearningCenterUnavailableException
     * @throws ContractMismatchException
     */
    public function fetch(string $subject): CredentialFacts;
}
