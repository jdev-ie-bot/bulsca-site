<?php

namespace Tests\Feature\Auth;

use App\Exceptions\IdentityReconciliationRequired;
use App\Models\ExternalIdentity;
use App\Models\Portal\Membership;
use App\Models\Portal\Organisation;
use App\Models\User;
use App\Services\AuthentikIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthentikIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://id.example.test/application/o/portal/';

    private function resolve(string $subject, ?string $email, bool $verified = true): User
    {
        return app(AuthentikIdentityService::class)->resolve(self::ISSUER, $subject, $email, $verified, 'Provider Name');
    }

    private function reconcile(array $accounts, bool $apply = false, string $issuer = self::ISSUER): int
    {
        config(['services.authentik.issuer' => self::ISSUER]);
        $file = tempnam(sys_get_temp_dir(), 'identity-test-');
        try {
            file_put_contents($file, json_encode(['issuer' => $issuer, 'accounts' => $accounts], JSON_THROW_ON_ERROR));
            return Artisan::call('authentik:reconcile', ['inventory' => $file, '--apply' => $apply]);
        } finally {
            unlink($file);
        }
    }

    public function test_verified_email_adds_identity_to_exact_existing_user_without_changing_authority_or_credentials(): void
    {
        $user = User::factory()->create(['email' => 'Person@Example.test', 'sso_user_id' => 84]);
        $user->assignRole(Role::findOrCreate('editor', 'web'));
        $membership = Membership::create(['organisation_id' => Organisation::where('slug', 'bulsca')->firstOrFail()->id, 'user_id' => $user->id]);
        $uni = DB::table('universities')->insertGetId(['name' => 'Existing university']);
        DB::table('user_universities')->insert(['user' => $user->id, 'uni' => $uni, 'admin' => true]);
        $original = $user->fresh()->getRawOriginal();

        $this->assertTrue($this->resolve('Subject-A', 'person@example.test')->is($user));
        $this->assertSame($original, $user->fresh()->getRawOriginal());
        $this->assertTrue($user->fresh()->hasRole('editor'));
        $this->assertSame($user->id, $membership->fresh()->user_id);
        $this->assertTrue($user->isUniAdmin($uni));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('external_identities', 1);
    }

    public function test_prelink_is_authoritative_and_subjects_are_case_sensitive(): void
    {
        $first = $this->resolve('Subject-A', 'a@example.test');
        $second = $this->resolve('subject-a', 'b@example.test');
        $this->assertFalse($first->is($second));
        $this->assertTrue($this->resolve('Subject-A', 'b@example.test', false)->is($first));
        $this->assertTrue($this->resolve('Subject-A', null, false)->is($first));
        $this->assertDatabaseCount('external_identities', 2);
        $this->assertDatabaseCount('portal_memberships', 0);
        $this->assertDatabaseCount('model_has_roles', 0);
        $this->assertDatabaseCount('user_universities', 0);
        $this->assertNull($first->password);
    }

    public function test_subject_is_scoped_to_its_exact_issuer(): void
    {
        $first = $this->resolve('s', 'a@example.test');
        $second = app(AuthentikIdentityService::class)->resolve(self::ISSUER.'other/', 's', 'b@example.test', true);
        $this->assertFalse($first->is($second));
        $this->assertDatabaseCount('external_identities', 2);
    }

    public function test_unverified_email_cannot_link_or_create_any_account(): void
    {
        $existing = User::factory()->create();
        foreach ([$existing->email, 'new@example.test'] as $email) {
            try {
                $this->resolve('unlinked', $email, false);
                $this->fail('Unverified identity accepted');
            } catch (IdentityReconciliationRequired $error) {
                $this->assertStringNotContainsString($email, $error->getMessage());
            }
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('external_identities', 0);
    }

    public function test_email_cannot_replace_an_existing_subject_or_choose_between_duplicate_candidates(): void
    {
        $user = $this->resolve('a', 'single@example.test');
        User::factory()->create(['email' => 'candidate@example.test']);
        User::factory()->create(['email' => ' candidate@example.test']);
        foreach (['single@example.test', 'candidate@example.test'] as $email) {
            try {
                $this->resolve('b', $email);
                $this->fail('Unsafe email link accepted');
            } catch (IdentityReconciliationRequired $error) {
                $this->assertStringNotContainsString($email, $error->getMessage());
            }
        }
        $this->assertDatabaseCount('external_identities', 1);
        $this->assertTrue($this->resolve('a', null, false)->is($user));
    }

    public function test_report_and_explicit_import_preserve_accounts_and_are_repeatable(): void
    {
        $first = User::factory()->create(['email' => 'existing@example.test']);
        $second = User::factory()->create(['email' => ' existing@example.test']);
        $row = ['portal_user_id' => 45, 'subject' => 'sub-45', 'email' => 'existing@example.test'];
        $this->assertSame(0, $this->reconcile([$row]));
        $this->assertStringContainsString('ambiguous email candidates', Artisan::output());
        $this->assertStringNotContainsString('existing@example.test', Artisan::output());
        $this->assertSame(1, $this->reconcile([$row], true));
        $row['backend_user_id'] = $second->id;
        $this->assertSame(0, $this->reconcile([$row]));
        $this->assertDatabaseCount('external_identities', 0);
        $this->assertSame(0, $this->reconcile([$row], true));
        $this->assertSame(0, $this->reconcile([$row], true));
        $this->assertTrue($this->resolve('sub-45', $first->email)->is($second));
        $this->assertDatabaseCount('external_identities', 1);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_conflicts_and_different_issuers_never_partially_apply(): void
    {
        $first = User::factory()->create();
        $second = $this->resolve('taken', 'second@example.test');
        $good = ['portal_user_id' => 1, 'subject' => 'free', 'backend_user_id' => $first->id];
        $bad = ['portal_user_id' => 2, 'subject' => 'different', 'backend_user_id' => $second->id];
        $this->assertSame(1, $this->reconcile([$good], true, self::ISSUER.'wrong/'));
        $this->assertSame(1, $this->reconcile([$good, $bad], true));
        $duplicateSubject = ['portal_user_id' => 7, 'subject' => 'free', 'backend_user_id' => $second->id];
        $duplicateTarget = ['portal_user_id' => 8, 'subject' => 'different', 'backend_user_id' => $first->id];
        foreach ([$good, $duplicateSubject, $duplicateTarget] as $duplicate) {
            $this->assertSame(1, $this->reconcile([$good, $duplicate], true));
            $this->assertStringContainsString('duplicate inventory mapping', Artisan::output());
        }
        $third = User::factory()->create();
        $bad['backend_user_id'] = $third->id;
        $bad['subject'] = 'taken';
        $this->assertSame(1, $this->reconcile([$good, $bad], true));
        $this->assertSame(1, $this->reconcile([['portal_user_id' => 4, 'subject' => null]], true));
        $this->assertDatabaseCount('external_identities', 1);
        $this->assertEquals($second->id, ExternalIdentity::firstOrFail()->user_id);
    }
}
