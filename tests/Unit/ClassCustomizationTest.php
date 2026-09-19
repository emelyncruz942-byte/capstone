<?php

namespace Tests\Unit;

use App\Support\ClassCustomization;
use PHPUnit\Framework\TestCase;

class ClassCustomizationTest extends TestCase
{
    public function test_untrusted_css_and_icon_values_are_replaced_by_allowlisted_defaults(): void
    {
        $customization = ClassCustomization::normalize([
            'class_id' => 'class-id',
            'theme_color' => 'red;position:fixed;inset:0',
            'icon' => 'fa-solid fa-user-secret" onclick="alert(1)',
            'banner_pattern' => 'url(https://attacker.example/tracker)',
        ]);

        $this->assertSame('class-id', $customization['class_id']);
        $this->assertSame('#f59e0b', $customization['theme_color']);
        $this->assertSame('chalkboard', $customization['icon']);
        $this->assertSame('grid', $customization['banner_pattern']);
    }
}
