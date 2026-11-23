<?php

namespace App\Services;

use App\Exceptions\IdentityReconciliationRequired;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AuthentikIdentityService
{
    public function resolve(string $issuer, string $subject, ?string $email, bool $emailVerified, ?string $name = null): User
    {
        if (! $this->validIdentity($issuer, $subject)) {
            throw new IdentityReconciliationRequired();
        }

        try {
            return DB::transaction(function () use ($issuer, $subject, $email, $emailVerified, $name) {
                $identity = $this->identity($issuer, $subject);
                if ($identity) {
                    return $identity->user;
                }
                if (! $emailVerified || ! $this->validEmail($email)) {
                    throw new IdentityReconciliationRequired();
                }

                $matches = $this->matchingAccounts($email)->lockForUpdate()->limit(2)->get();
                if ($matches->count() > 1) {
                    throw new IdentityReconciliationRequired();
                }
                $user = $matches->first();
                if (! $user) {
                    $user = User::create([
                        'name' => mb_substr(trim($name ?? '') ?: trim($email), 0, 255),
                        'email' => trim($email),
                        'auth_type' => 'authentik',
                    ]);
                }
                $this->link($issuer, $subject, $user);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // A concurrent callback may already have committed the same exact identity.
            return $this->identity($issuer, $subject)?->user ?? throw new IdentityReconciliationRequired();
        }
    }

    public function validIdentity(string $issuer, string $subject): bool
    {
        return $issuer !== '' && strlen($issuer) <= 512 && trim($issuer) === $issuer
            && filter_var($issuer, FILTER_VALIDATE_URL) !== false
            && strlen($subject) <= 255 && preg_match('/\A[\x20-\x7E]+\z/', $subject) === 1
            && trim($subject) !== '';
    }

    public function validEmail(?string $email): bool
    {
        return $email !== null && strlen(trim($email)) <= 254 && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function identity(string $issuer, string $subject): ?ExternalIdentity
    {
        return ExternalIdentity::where('issuer', $issuer)->where('subject', $subject)->first();
    }

    public function matchingAccounts(string $email): Builder
    {
        // MySQL's default collation also folds accents. Fold only case and surrounding spaces.
        return User::whereRaw('CAST(LOWER(TRIM(email)) AS BINARY) = ?', [strtolower(trim($email))]);
    }

    /** The caller has locked this user inside the surrounding transaction. */
    public function link(string $issuer, string $subject, User $user): void
    {
        $existing = ExternalIdentity::where('user_id', $user->id)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->issuer !== $issuer || $existing->subject !== $subject) {
                throw new IdentityReconciliationRequired();
            }

            return;
        }

        ExternalIdentity::create(['issuer' => $issuer, 'subject' => $subject, 'user_id' => $user->id]);
    }
}
