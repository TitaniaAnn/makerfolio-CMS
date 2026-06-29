<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/EventTypes.php';

/**
 * Without the `setting()` helper from bootstrap.php, EventTypes::labels()
 * falls back to DEFAULT_LABELS. That's the path covered here; admin-override
 * behaviour is exercised in the Docker smoke tests instead.
 */
final class EventTypesTest extends TestCase
{
    public function test_label_returns_default_for_known_type(): void
    {
        $this->assertSame('Show',            \EventTypes::label('pottery_show'));
        $this->assertSame('Sale',            \EventTypes::label('pottery_sale'));
        $this->assertSame('Storefront Sale', \EventTypes::label('storefront_sale'));
        $this->assertSame('Class',           \EventTypes::label('class'));
    }

    public function test_label_returns_event_fallback_for_unknown(): void
    {
        $this->assertSame('Event', \EventTypes::label(''));
        $this->assertSame('Event', \EventTypes::label('not_a_real_type'));
    }

    public function test_css_class_matches_default_map(): void
    {
        $this->assertSame('pottery-show',    \EventTypes::cssClass('pottery_show'));
        $this->assertSame('storefront-sale', \EventTypes::cssClass('storefront_sale'));
        $this->assertSame('class',           \EventTypes::cssClass('class'));
        $this->assertSame('event',           \EventTypes::cssClass('unknown'));
    }

    public function test_default_labels_cover_every_css_class_key(): void
    {
        $this->assertSame(
            array_keys(\EventTypes::DEFAULT_LABELS),
            array_keys(\EventTypes::CSS_CLASSES),
            'DEFAULT_LABELS and CSS_CLASSES must stay in sync — adding a new event type means updating both.'
        );
    }
}
