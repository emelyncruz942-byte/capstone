<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentClassController;
use App\Http\Controllers\StudentController;
use App\Services\SupabaseService;
use Mockery;
use Tests\TestCase;

class PeerAvatarPrivacyTest extends TestCase
{
    private const SELF = '11111111-1111-4111-8111-111111111111';
    private const PRIVATE = '22222222-2222-4222-8222-222222222222';
    private const PUBLIC = '33333333-3333-4333-8333-333333333333';
    private const STALE = '44444444-4444-4444-8444-444444444444';

    public function test_dashboard_photos_use_only_ranked_profiles_and_recheck_peer_privacy(): void
    {
        $user = ['id' => self::SELF, 'role' => 'student', 'grade_level' => 4, 'avatar_url' => 'https://example.test/me.jpg'];
        session()->put('supabase_user', $user);
        $service = Mockery::mock(SupabaseService::class);
        $service->shouldReceive('adminSelect')->with('profiles', '*', ['id' => self::SELF])->andReturn([$user]);
        $service->shouldReceive('adminSelectHistory')->andReturn([]);
        $service->shouldReceive('adminRpcResult')->with('student_trophy_leaderboard', ['p_student_id' => self::SELF, 'p_limit' => 10])
            ->andReturn(['error' => null, 'data' => [['leaderboard' => [
                ['id' => self::SELF, 'rank' => 1, 'display_name' => 'My name'],
                ['id' => self::PRIVATE, 'rank' => 2, 'display_name' => 'Anonymous Student'],
                ['id' => self::PUBLIC, 'rank' => 3, 'display_name' => 'Public learner'],
                ['id' => self::STALE, 'rank' => 4, 'display_name' => 'Now private learner'],
            ]]]]);
        $service->shouldReceive('adminSelect')->once()->with('profiles', 'id,avatar_url,show_on_leaderboard', [
            'id' => ['operator' => 'in', 'value' => '('.self::SELF.','.self::PUBLIC.','.self::STALE.')'],
        ])->andReturn([
            ['id' => self::SELF, 'show_on_leaderboard' => false, 'avatar_url' => $user['avatar_url']],
            ['id' => self::PUBLIC, 'show_on_leaderboard' => true, 'avatar_url' => 'https://example.test/public.jpg'],
            ['id' => self::STALE, 'show_on_leaderboard' => false, 'avatar_url' => 'https://example.test/private.jpg'],
        ]);

        $rows = array_column((new StudentController($service))->index()->getData()['leaderboard'], null, 'id');
        $this->assertSame($user['avatar_url'], $rows[self::SELF]['avatar_url']);
        $this->assertFalse($rows[self::SELF]['is_anonymous']);
        $this->assertSame('https://example.test/public.jpg', $rows[self::PUBLIC]['avatar_url']);
        foreach ([self::PRIVATE, self::STALE] as $id) {
            $this->assertSame('Anonymous Student', $rows[$id]['display_name']);
            $this->assertNull($rows[$id]['avatar_url']);
            $this->assertTrue($rows[$id]['is_anonymous']);
        }
    }

    public function test_class_ranking_hides_private_peer_photos_and_keeps_public_and_own_photos(): void
    {
        session()->put('supabase_user', ['id' => self::SELF]);
        $service = Mockery::mock(SupabaseService::class);
        $members = [];
        foreach ([self::SELF => false, self::PRIVATE => false, self::PUBLIC => true] as $id => $visible) {
            $members[] = ['student_id' => $id, 'profiles' => ['first_name' => 'Learner', 'last_name' => 'Cruz',
                'show_on_leaderboard' => $visible, 'avatar_url' => 'https://example.test/'.$id.'.jpg']];
        }
        $service->shouldReceive('adminSelect')->with('class_members', Mockery::type('string'), ['class_id' => 'class-1'])->andReturn($members);
        $service->shouldReceive('adminSelect')->with('quiz_results', Mockery::type('string'), Mockery::type('array'))->andReturn([]);
        $service->shouldReceive('adminSelect')->with('quiz_session_students', Mockery::type('string'), Mockery::type('array'))
            ->andReturn(array_map(fn (array $member): array => ['student_id' => $member['student_id'], 'session_id' => 'session-1', 'eligibility_status' => 'eligible'], $members));
        $method = new \ReflectionMethod(StudentClassController::class, 'classLeaderboard');
        $rows = array_column($method->invoke(new StudentClassController($service), 'class-1', ['session-1']), null, 'student_id');
        $this->assertSame('Anonymous Student', $rows[self::PRIVATE]['name']);
        $this->assertNull($rows[self::PRIVATE]['avatar_url']);
        $this->assertTrue($rows[self::PRIVATE]['is_anonymous']);
        foreach ([self::SELF, self::PUBLIC] as $id) {
            $this->assertSame('https://example.test/'.$id.'.jpg', $rows[$id]['avatar_url']);
            $this->assertFalse($rows[$id]['is_anonymous']);
        }
    }
}
