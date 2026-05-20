<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/PageText.php';

/**
 * Without the `setting()` helper from bootstrap.php, PageText::get() falls
 * back to DEFAULTS. That's the path covered here; admin-override behaviour is
 * exercised in the Docker smoke tests.
 */
final class PageTextTest extends TestCase
{
    public function test_get_returns_default_when_no_override(): void
    {
        $this->assertSame('Featured Work', \PageText::get('home', 'featured_work_title'));
        $this->assertSame('Home',          \PageText::get('nav', 'home'));
        $this->assertSame('Buy Now',       \PageText::get('shop', 'btn_buy_now'));
    }

    public function test_get_throws_on_unknown_group_or_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PageText: unknown key home.nonsense');
        \PageText::get('home', 'nonsense');
    }

    public function test_get_throws_on_unknown_group(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \PageText::get('not_a_group', 'whatever');
    }

    public function test_setting_key_uses_text_prefix(): void
    {
        $this->assertSame('text.home.featured_work_title', \PageText::settingKey('home', 'featured_work_title'));
        $this->assertSame('text.shop.btn_enquire',         \PageText::settingKey('shop', 'btn_enquire'));
    }

    public function test_groups_returns_declared_groups_in_order(): void
    {
        $expected = ['titles', 'checkout', 'nav', 'footer', 'home', 'portfolio', 'shop', 'about', 'events', 'templates', 'announcement', 'order'];
        $this->assertSame($expected, \PageText::groups());
    }

    public function test_all_keys_flattens_every_pair(): void
    {
        $pairs = \PageText::allKeys();
        $this->assertNotEmpty($pairs);
        // Each entry is a [group, key] tuple
        foreach ($pairs as $pair) {
            $this->assertCount(2, $pair);
            $this->assertIsString($pair[0]);
            $this->assertIsString($pair[1]);
            $this->assertArrayHasKey($pair[1], \PageText::DEFAULTS[$pair[0]]);
        }
        // Total count matches the sum of per-group key counts
        $expectedCount = 0;
        foreach (\PageText::DEFAULTS as $keys) {
            $expectedCount += count($keys);
        }
        $this->assertCount($expectedCount, $pairs);
    }

    public function test_every_default_value_is_a_non_empty_string(): void
    {
        foreach (\PageText::DEFAULTS as $group => $keys) {
            foreach ($keys as $key => $default) {
                $this->assertIsString($default, "default $group.$key must be string");
                $this->assertNotSame('', trim($default), "default $group.$key must not be empty/whitespace");
            }
        }
    }
}
