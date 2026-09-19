<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use Tests\TestCase;

class RecoveryWorkflowTest extends TestCase
{
    private const ACTOR = '11111111-1111-4111-8111-111111111111';
    private const TARGET = '22222222-2222-4222-8222-222222222222';
    private const INTENT = '33333333-3333-4333-8333-333333333333';

    public function test_old_account_delete_button_only_deactivates_and_never_calls_auth_delete(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminRpcResult')->once()->with('set_account_deactivated', [
            'p_actor_id' => self::ACTOR, 'p_id' => self::TARGET, 'p_restore' => false,
        ])->andReturn($this->rpcResult(['id' => self::TARGET]));
        $service->shouldReceive('setAuthUserSuspended')->once()->with(self::TARGET, true)->andReturn(true);
        $service->shouldNotReceive('deleteAuthUser');
        $this->admin()->delete('/admin/user/'.self::TARGET)->assertRedirect('/admin/trash?type=account')
            ->assertSessionHas('success');
    }

    public function test_unavailable_transaction_blocks_deactivation_before_auth_changes(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminRpcResult')->once()->andReturn(['error' => 'audit missing', 'data' => []]);
        $service->shouldNotReceive('setAuthUserSuspended');
        $service->shouldNotReceive('deleteAuthUser');
        $this->admin()->delete('/admin/user/'.self::TARGET)->assertSessionHas('error');
    }

    public function test_teacher_restore_is_delegated_to_the_owner_checked_transaction(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminRpcResult')->once()->with('set_recovery_item', [
            'p_actor_id' => self::ACTOR, 'p_kind' => 'class', 'p_id' => self::TARGET, 'p_restore' => true,
        ])->andReturn($this->rpcResult(['id' => self::TARGET]));
        $service->shouldNotReceive('adminUpdate');
        $this->withSession(['supabase_user' => ['id' => self::ACTOR, 'role' => 'teacher']])
            ->post('/teacher/trash/class/'.self::TARGET.'/restore')->assertRedirect('/teacher/trash?type=class')
            ->assertSessionHas('success');
    }

    public function test_permanent_delete_requires_both_typed_confirmation_and_exact_target(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldNotReceive('adminRpcResult');
        $service->shouldNotReceive('deleteAuthUser');
        $this->admin()->delete('/admin/trash/account/'.self::TARGET, ['confirmation' => 'DELETE', 'delete_account_id' => self::ACTOR])
            ->assertSessionHasErrors('delete_account_id');
    }

    public function test_permanent_delete_cannot_skip_the_database_delay_and_ownership_guard(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminRpcResult')->once()->with('prepare_account_purge', [
            'p_actor_id' => self::ACTOR, 'p_id' => self::TARGET,
        ])->andReturn(['error' => 'Deactivate for seven days', 'data' => []]);
        $service->shouldNotReceive('deleteAuthUser');
        $this->admin()->delete('/admin/trash/account/'.self::TARGET, $this->confirmation())->assertSessionHas('error');
    }

    public function test_failed_auth_purge_finalizes_failure_and_leaves_account_deactivated(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminRpcResult')->once()->with('prepare_account_purge', [
            'p_actor_id' => self::ACTOR, 'p_id' => self::TARGET,
        ])->andReturn($this->rpcResult(['intent_id' => self::INTENT]));
        $service->shouldReceive('deleteAuthUser')->once()->with(self::TARGET)->andReturn(false);
        $service->shouldReceive('adminRpcResult')->once()->with('cancel_account_purge', [
            'p_actor_id' => self::ACTOR, 'p_id' => self::TARGET, 'p_intent_id' => self::INTENT,
        ])->andReturn($this->rpcResult(['cancelled' => true]));
        $this->admin()->delete('/admin/trash/account/'.self::TARGET, $this->confirmation())->assertSessionHas('error');
    }

    public function test_successful_permanent_purge_finishes_the_durable_security_audit(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminRpcResult')->once()->andReturn($this->rpcResult(['intent_id' => self::INTENT]));
        $service->shouldReceive('deleteAuthUser')->once()->with(self::TARGET)->andReturn(true);
        $service->shouldReceive('completePrivilegedAudit')->once()->with(self::INTENT, true, ['permanently_deleted' => true])->andReturn(true);
        $this->admin()->delete('/admin/trash/account/'.self::TARGET, $this->confirmation())
            ->assertSessionHas('success', 'Account permanently deleted. This cannot be undone from Trash.');
    }

    public function test_trash_page_lists_only_owned_rows_and_includes_real_native_restore_forms(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $this->withoutVite();
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminSelectPage')->once()->with('classes', '*', [
            'deleted_at' => ['operator' => 'not.is', 'value' => 'null'], 'order' => 'deleted_at.desc,id.asc', 'teacher_id' => self::ACTOR,
        ], 25, 0)->andReturn(['total' => 1, 'data' => [['id' => self::TARGET, 'class_name' => 'Orion',
            'deleted_at' => now()->toIso8601String(), 'deleted_by' => self::ACTOR]]]);
        $this->withSession(['supabase_user' => ['id' => self::ACTOR, 'role' => 'teacher', 'email' => 'teacher@example.test',
            'avatar_url' => null, 'first_name' => 'Test', 'last_name' => 'Teacher']])
            ->get('/teacher/trash')->assertOk()->assertSee('/teacher/trash/class/'.self::TARGET.'/restore', false)
            ->assertSee('data-native-navigation', false);
    }

    public function test_reactivation_never_cancels_a_suspension_that_preceded_deactivation(): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $service = $this->mock(SupabaseService::class);
        $service->shouldReceive('adminSelect')->once()->andReturn([['id' => self::TARGET, 'role' => 'student',
            'deactivated_at' => now()->toIso8601String(), 'deactivation_was_suspended' => true]]);
        $service->shouldNotReceive('setAuthUserSuspended');
        $service->shouldReceive('adminRpcResult')->once()->with('set_account_deactivated', [
            'p_actor_id' => self::ACTOR, 'p_id' => self::TARGET, 'p_restore' => true,
        ])->andReturn($this->rpcResult(['id' => self::TARGET]));
        $this->admin()->post('/admin/trash/account/'.self::TARGET.'/restore')
            ->assertSessionHas('success', 'Account reactivated, but its previous suspension is still in effect.');
    }

    private function admin(): static
    {
        return $this->withSession(['supabase_user' => ['id' => self::ACTOR, 'role' => 'admin']]);
    }
    private function rpcResult(array $data): array { return ['error' => null, 'data' => [$data], 'status' => 200]; }
    private function confirmation(): array { return ['confirmation' => 'DELETE', 'delete_account_id' => self::TARGET]; }
}
