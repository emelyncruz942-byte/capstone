<?php

namespace Tests\Feature;

use App\Http\Middleware\SupabaseAuth;
use App\Services\SupabaseService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentPrivacyTest extends TestCase
{
    public static function privacyChoices(): array
    {
        return [
            'anonymous enabled' => [['anonymous_profile' => '1'], true, false],
            'student opts out' => [['anonymous_profile' => '0'], false, true],
            'legacy form opts out' => [['show_on_leaderboard' => '1'], false, true],
            'legacy form stays anonymous' => [['show_on_leaderboard' => '0'], true, false],
            'unrelated edit preserves anonymous' => [[], false, false],
            'unrelated edit ignores stale public session' => [[], true, false],
            'new flag takes precedence' => [['anonymous_profile' => '1', 'show_on_leaderboard' => '1'], true, false],
        ];
    }

    #[DataProvider('privacyChoices')]
    public function test_students_can_keep_or_disable_anonymous_profiles(array $choice, bool $previouslyVisible, bool $expectedVisible): void
    {
        $this->withoutMiddleware(SupabaseAuth::class);
        $user = [
            'id' => '11111111-1111-4111-8111-111111111111',
            'role' => 'student',
            'first_name' => 'Test',
            'last_name' => 'Learner',
            'grade_level' => 4,
            'show_on_leaderboard' => $previouslyVisible,
        ];
        $supabase = $this->mock(SupabaseService::class);
        $expectedChanges = [
            'first_name' => 'Test',
            'last_name' => 'Learner',
            'grade_level' => 4,
            'leaderboard_alias' => null,
        ];
        if (array_key_exists('anonymous_profile', $choice) || array_key_exists('show_on_leaderboard', $choice)) {
            $expectedChanges['show_on_leaderboard'] = $expectedVisible;
        }
        $supabase->shouldReceive('updateProfile')->once()->with($user['id'], $expectedChanges)
            ->andReturn([array_merge($user, ['show_on_leaderboard' => $expectedVisible])]);
        $supabase->shouldReceive('audit')->once();

        $this->withSession(['supabase_user' => $user])->post('/student/profile', array_merge([
            'first_name' => 'Test',
            'last_name' => 'Learner',
            'grade_level' => 4,
        ], $choice))
            ->assertRedirect('/student/dashboard?section=profile')
            ->assertSessionHas('supabase_user.show_on_leaderboard', $expectedVisible)
            ->assertSessionMissing('error');
    }
}
