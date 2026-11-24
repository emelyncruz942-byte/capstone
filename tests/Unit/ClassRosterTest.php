<?php

namespace Tests\Unit;

use App\Support\ClassRoster;
use PHPUnit\Framework\TestCase;

class ClassRosterTest extends TestCase
{
    public function test_members_are_sorted_by_last_name_then_given_name_with_unnamed_profiles_last(): void
    {
        $members = ClassRoster::sortByLastName([
            ['student_id' => 'student-4', 'profiles' => ['id' => '4', 'last_name' => '', 'first_name' => 'Unknown']],
            ['student_id' => 'student-5', 'profiles' => ['id' => '5', 'last_name' => 'Adams', 'first_name' => 'Amy']],
            ['student_id' => 'student-3', 'profiles' => ['id' => '3', 'last_name' => 'Cruz', 'first_name' => 'Zoe']],
            ['student_id' => 'student-1', 'profiles' => ['id' => '1', 'last_name' => 'Brito', 'first_name' => 'Beth']],
            ['student_id' => 'student-2', 'profiles' => ['id' => '2', 'last_name' => 'Cruz', 'first_name' => 'Ana']],
        ]);

        $this->assertSame(['5', '1', '2', '3', '4'], array_column(array_column($members, 'profiles'), 'id'));
    }
}
