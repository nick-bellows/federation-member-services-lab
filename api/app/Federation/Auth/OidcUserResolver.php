<?php

namespace App\Federation\Auth;

use App\Federation\Support\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Maps a verified identity to a users row.
 *
 * 1. Known subject for this issuer: that user.
 * 2. Unknown subject, verified e-mail matching a user without an identity:
 *    link them (recorded in the audit trail). A user already linked to a
 *    different subject is a conflict, never silently re-linked.
 * 3. Otherwise, when provisioning is enabled and the e-mail is verified:
 *    create the user. Unverified e-mail claims are never trusted for
 *    linking or provisioning; that is the classic account-takeover hole.
 */
class OidcUserResolver
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly bool $provisionUsers,
    ) {}

    public function resolve(OidcIdentity $identity): User
    {
        $user = User::withoutGlobalScopes()
            ->where('oidc_issuer', $identity->issuer)
            ->where('oidc_subject', $identity->subject)
            ->first();

        if ($user) {
            return $user;
        }

        if (! $identity->email || ! $identity->emailVerified) {
            throw new OidcException('Unknown subject and no verified e-mail to link or provision with.');
        }

        $byEmail = User::withoutGlobalScopes()->where('email', $identity->email)->first();

        if ($byEmail) {
            if ($byEmail->oidc_subject !== null) {
                throw new OidcException('E-mail already linked to a different identity.');
            }

            $byEmail->forceFill([
                'oidc_issuer' => $identity->issuer,
                'oidc_subject' => $identity->subject,
            ])->save();

            $this->audit->record(
                actor: $byEmail,
                action: 'user.identity_linked',
                auditable: $byEmail,
                new: ['oidc_issuer' => $identity->issuer],
            );

            return $byEmail;
        }

        if (! $this->provisionUsers) {
            throw new OidcException('Unknown subject and provisioning is disabled.');
        }

        // A person's first page fans out into parallel requests, each of which
        // may arrive here before the other has committed. createOrFirst turns
        // the loser's unique-key violation into a lookup of the winner's row.
        $user = User::unguarded(fn () => User::withoutGlobalScopes()->createOrFirst(
            ['oidc_issuer' => $identity->issuer, 'oidc_subject' => $identity->subject],
            [
                'name' => $identity->name ?? $identity->email,
                'email' => $identity->email,
                'password' => Str::password(48),
            ],
        ));

        if ($user->wasRecentlyCreated) {
            $this->audit->record(
                actor: $user,
                action: 'user.provisioned',
                auditable: $user,
                new: ['oidc_issuer' => $identity->issuer, 'email' => $identity->email],
            );
        }

        return $user;
    }
}
