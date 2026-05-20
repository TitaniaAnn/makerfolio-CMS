<?php
namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/EmailTemplates.php';

/**
 * Pure-function / catalog tests for EmailTemplates.
 *
 * Override behaviour (settings-backed) is exercised by the Docker smoke
 * verification; here we cover defaults, substitution, validation, and the
 * catalog invariants the admin UI + Mailer both rely on.
 */
final class EmailTemplatesTest extends TestCase
{
    public function test_get_returns_default_when_no_override(): void
    {
        // Without `setting()` defined (tests/bootstrap.php doesn't load it),
        // get() falls through to the catalog default.
        $this->assertSame(
            \EmailTemplates::TEMPLATES['owner_new_order']['subject'],
            \EmailTemplates::get('owner_new_order', 'subject')
        );
        $this->assertSame(
            \EmailTemplates::TEMPLATES['customer_receipt']['body'],
            \EmailTemplates::get('customer_receipt', 'body')
        );
    }

    public function test_get_throws_on_unknown_template(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown template 'not_a_template'");
        \EmailTemplates::get('not_a_template', 'subject');
    }

    public function test_get_throws_on_unknown_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown field 'preheader'");
        \EmailTemplates::get('owner_new_order', 'preheader');
    }

    public function test_substitute_replaces_known_variables(): void
    {
        $out = \EmailTemplates::substitute('Hi {name}, your order #{id}', [
            'name' => 'Jane',
            'id'   => '42',
        ]);
        $this->assertSame('Hi Jane, your order #42', $out);
    }

    public function test_substitute_leaves_unknown_variables_in_place(): void
    {
        // Deliberate: missing data should be visible in admin preview, not silently blanked.
        $out = \EmailTemplates::substitute('Hi {name}, {missing} here', ['name' => 'Jane']);
        $this->assertSame('Hi Jane, {missing} here', $out);
    }

    public function test_substitute_coerces_non_string_values(): void
    {
        $out = \EmailTemplates::substitute('{n} items for ${price}', [
            'n'     => 3,
            'price' => 45.5,
        ]);
        $this->assertSame('3 items for $45.5', $out);
    }

    public function test_render_does_both_lookup_and_substitution(): void
    {
        $out = \EmailTemplates::render('owner_new_order', 'subject', [
            'site_name'    => 'TestPottery',
            'product_name' => 'Bowl',
        ]);
        $this->assertSame('[TestPottery] New order — Bowl', $out);
    }

    public function test_setting_key_uses_email_prefix(): void
    {
        $this->assertSame('email.owner_new_order.subject', \EmailTemplates::settingKey('owner_new_order', 'subject'));
        $this->assertSame('email.customer_shipped.body',   \EmailTemplates::settingKey('customer_shipped', 'body'));
    }

    public function test_all_setting_keys_covers_every_template_field(): void
    {
        $keys = \EmailTemplates::allSettingKeys();
        $this->assertCount(count(\EmailTemplates::TEMPLATES) * 2, $keys);
        foreach (array_keys(\EmailTemplates::TEMPLATES) as $tk) {
            $this->assertContains("email.$tk.subject", $keys);
            $this->assertContains("email.$tk.body",    $keys);
        }
    }

    public function test_every_template_declares_label_description_subject_body_variables(): void
    {
        foreach (\EmailTemplates::TEMPLATES as $key => $tpl) {
            $this->assertArrayHasKey('label',       $tpl, "$key missing label");
            $this->assertArrayHasKey('description', $tpl, "$key missing description");
            $this->assertArrayHasKey('subject',     $tpl, "$key missing subject");
            $this->assertArrayHasKey('body',        $tpl, "$key missing body");
            $this->assertArrayHasKey('variables',   $tpl, "$key missing variables");
            $this->assertNotSame('', trim($tpl['subject']), "$key subject must not be blank");
            $this->assertNotSame('', trim($tpl['body']),    "$key body must not be blank");
        }
    }

    public function test_every_variable_referenced_in_a_template_is_declared(): void
    {
        // Catches the case where someone adds `{order_total}` to a body but
        // forgets to list it in `variables` — the admin UI's "available
        // variables" panel would then be wrong.
        foreach (\EmailTemplates::TEMPLATES as $key => $tpl) {
            $declared = array_keys($tpl['variables']);
            preg_match_all('/\{(\w+)\}/', $tpl['subject'] . "\n" . $tpl['body'], $matches);
            $referenced = array_values(array_unique($matches[1]));
            foreach ($referenced as $var) {
                $this->assertContains(
                    $var,
                    $declared,
                    "Template '$key' uses {{$var}} but doesn't declare it in 'variables'."
                );
            }
        }
    }

    public function test_preview_samples_cover_every_declared_variable(): void
    {
        // The admin live-preview substitutes from PREVIEW_SAMPLES; if a
        // variable isn't there the preview will show literal `{var_name}`.
        $samples = array_keys(\EmailTemplates::PREVIEW_SAMPLES);
        foreach (\EmailTemplates::TEMPLATES as $key => $tpl) {
            foreach (array_keys($tpl['variables']) as $var) {
                $this->assertContains(
                    $var,
                    $samples,
                    "Template '$key' declares {{$var}} but PREVIEW_SAMPLES has no sample value for it."
                );
            }
        }
    }
}
