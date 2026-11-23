<?php

namespace App\Console\Commands;

use App\Exceptions\IdentityReconciliationRequired;
use App\Models\ExternalIdentity;
use App\Models\User;
use App\Services\AuthentikIdentityService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use JsonException;

class ReconcileAuthentik extends Command
{
    protected $signature = 'authentik:reconcile {inventory : Local portal inventory JSON file} {--apply : Apply reviewed backend_user_id mappings atomically}';
    protected $description = 'Report portal account matches or import explicit Authentik identity mappings';

    public function handle(AuthentikIdentityService $identities): int
    {
        $path = $this->argument('inventory');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Inventory file is unavailable.');
            return self::FAILURE;
        }

        try {
            $document = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Invalid inventory JSON.');
            return self::FAILURE;
        }

        $issuer = config('services.authentik.issuer');
        $accounts = $document['accounts'] ?? null;
        if (! is_string($issuer) || ! $identities->validIdentity($issuer, 'inventory')
            || ($document['issuer'] ?? null) !== $issuer || ! is_array($accounts) || ! array_is_list($accounts)) {
            $this->error('The inventory must contain an accounts list and the exact configured Authentik issuer.');
            return self::FAILURE;
        }

        $rows = [];
        $portals = [];
        $subjects = [];
        $targets = [];
        foreach ($accounts as $index => $account) {
            $portal = $account['portal_user_id'] ?? null;
            $subject = $account['subject'] ?? null;
            $target = $account['backend_user_id'] ?? null;
            $email = $account['email'] ?? null;
            if (! is_int($portal) || $portal < 1 || ($subject !== null && ! is_string($subject))
                || ($target !== null && (! is_int($target) || $target < 1)) || ($email !== null && ! is_string($email))) {
                $this->error('Invalid account at row '.($index + 1).'.');
                return self::FAILURE;
            }
            $portals[$portal] = ($portals[$portal] ?? 0) + 1;
            if ($subject !== null) {
                $subjects[$subject] = ($subjects[$subject] ?? 0) + 1;
            }
            if ($target !== null) {
                $targets[$target] = ($targets[$target] ?? 0) + 1;
            }
        }

        $apply = $this->option('apply');
        try {
            DB::transaction(function () use ($accounts, $issuer, $identities, $portals, $subjects, $targets, $apply, &$rows) {
                $rows = [];
                $failed = false;
                foreach ($accounts as $account) {
                    $portal = $account['portal_user_id'];
                    $subject = $account['subject'] ?? null;
                    $target = $account['backend_user_id'] ?? null;
                    $email = $account['email'] ?? null;
                    if ($portals[$portal] > 1 || ($subject !== null && $subjects[$subject] > 1) || ($target !== null && $targets[$target] > 1)) {
                        $status = 'duplicate inventory mapping';
                    } elseif (! is_string($subject) || ! $identities->validIdentity($issuer, $subject)) {
                        $status = 'missing or invalid subject';
                    } else {
                        [$status, $target] = $this->inspect($identities, $issuer, $subject, $target, $email, $apply);
                    }
                    $rows[] = [$portal, $target ?? '—', $status];
                    if (! in_array($status, ['already linked', 'explicit mapping'], true)) {
                        $failed = true;
                    }
                }
                if ($apply && $failed) {
                    throw new IdentityReconciliationRequired();
                }
            }, 3);
        } catch (IdentityReconciliationRequired|UniqueConstraintViolationException) {
            $this->table(['Portal ID', 'Backend ID', 'Finding'], $rows);
            $this->error('Import rolled back. Review the mappings and run the report again.');
            return self::FAILURE;
        }

        $this->table(['Portal ID', 'Backend ID', 'Finding'], $rows);
        $this->info($apply ? 'Reviewed mappings applied.' : 'Dry run complete. No mappings changed.');
        return self::SUCCESS;
    }

    private function inspect(AuthentikIdentityService $identities, string $issuer, string $subject, ?int $target, ?string $email, bool $apply): array
    {
        $identity = $identities->identity($issuer, $subject);
        if ($identity) {
            return $target === null || $target === (int) $identity->user_id
                ? ['already linked', (int) $identity->user_id]
                : ['subject linked to a different backend account', $target];
        }
        if ($target === null) {
            $matches = $identities->validEmail($email)
                ? $identities->matchingAccounts($email)->orderBy('id')->pluck('id')
                : collect();
            if ($matches->count() === 1) {
                $id = $matches->first();
                return [ExternalIdentity::where('user_id', $id)->exists()
                    ? 'email candidate already has another identity' : 'possible email match; review required', $id];
            }
            return $matches->isEmpty() ? ['no email candidate', null] : ['ambiguous email candidates', $matches->implode(', ')];
        }

        $user = User::whereKey($target)->when($apply, fn ($q) => $q->lockForUpdate())->first();
        if (! $user) {
            return ['unknown backend account', $target];
        }
        if (ExternalIdentity::where('user_id', $target)->exists()) {
            return ['backend account already has another identity', $target];
        }
        if ($apply) {
            $identities->link($issuer, $subject, $user);
        }

        return ['explicit mapping', $target];
    }
}
