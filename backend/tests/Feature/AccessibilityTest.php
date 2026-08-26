<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AccessibilityService;
use Tests\TestCase;

/**
 * Accessibility and Localization Hardening Tests — Phase 94.
 *
 * Verifies:
 * - WCAG 2.2 AA alignment checks
 * - Nepal localization validation
 * - Clinical UI safety rules
 * - Responsive design standards
 * - Touch target requirements
 * - Font/typography support
 */
class AccessibilityTest extends TestCase
{
    private AccessibilityService $accessibilityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accessibilityService = app(AccessibilityService::class);
    }

    /** @test */
    public function it_performs_comprehensive_accessibility_audit(): void
    {
        $config = [
            'keyboard_complete' => true,
            'aria_labels_count' => 146,
            'role_attributes_count' => 62,
            'skip_to_content' => true,
            'min_contrast_ratio' => 4.5,
            'min_touch_target_px' => 44,
            'min_font_size_px' => 14,
            'tabular_numerals' => true,
            'devanagari_font_support' => true,
            'focus_visible' => true,
            'keyboard_traps' => false,
            'semantic_html' => true,
            'reduced_motion' => true,
        ];

        $result = $this->accessibilityService->auditAccessibility($config);

        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(8, $result['summary']['total']);
        $this->assertGreaterThan(0, $result['summary']['passed']);

        // Verify all checks have required fields
        foreach ($result['checks'] as $check) {
            $this->assertArrayHasKey('rule', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('severity', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertContains($check['status'], ['pass', 'warning', 'fail']);
        }
    }

    /** @test */
    public function it_validates_nepal_localization(): void
    {
        $config = [
            'supported_locales' => ['en', 'ne'],
            'catalog_parity_enforced' => true,
            'default_currency' => 'NPR',
            'timezone' => 'Asia/Kathmandu',
            'date_format' => 'YYYY-MM-DD',
            'nepal_phone_validation' => true,
            'devanagari_font_support' => true,
            'language_selector' => true,
            'html_lang_attribute' => true,
            'lazy_loading' => true,
            'error_boundaries' => true,
            'loading_states' => true,
            'terminology_glossary' => true,
            'translation_quality_review' => true,
        ];

        $result = $this->accessibilityService->validateNepalLocalization($config);

        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(8, $result['summary']['total']);
        $this->assertGreaterThan(0, $result['summary']['passed']);
    }

    /** @test */
    public function it_validates_clinical_ui_safety(): void
    {
        $config = [
            'identity_spine' => true,
            'high_risk_confirmation' => true,
            'high_risk_audit' => true,
            'multiple_patient_ids' => true,
            'patient_verification' => true,
            'clinical_units_display' => true,
            'reference_range_display' => true,
            'allergy_check' => true,
            'duplicate_med_check' => true,
            'generic_brand_distinction' => true,
        ];

        $result = $this->accessibilityService->validateClinicalUISafety($config);

        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(5, $result['summary']['total']);
        $this->assertGreaterThan(0, $result['summary']['passed']);
    }

    /** @test */
    public function it_runs_full_audit_with_scoring(): void
    {
        $config = [
            'keyboard_complete' => true,
            'aria_labels_count' => 146,
            'role_attributes_count' => 62,
            'skip_to_content' => true,
            'min_contrast_ratio' => 4.5,
            'min_touch_target_px' => 44,
            'min_font_size_px' => 14,
            'tabular_numerals' => true,
            'devanagari_font_support' => true,
            'focus_visible' => true,
            'keyboard_traps' => false,
            'semantic_html' => true,
            'reduced_motion' => true,
            'supported_locales' => ['en', 'ne'],
            'catalog_parity_enforced' => true,
            'default_currency' => 'NPR',
            'timezone' => 'Asia/Kathmandu',
            'date_format' => 'YYYY-MM-DD',
            'nepal_phone_validation' => true,
            'language_selector' => true,
            'html_lang_attribute' => true,
            'lazy_loading' => true,
            'error_boundaries' => true,
            'loading_states' => true,
            'terminology_glossary' => true,
            'translation_quality_review' => true,
            'identity_spine' => true,
            'high_risk_confirmation' => true,
            'high_risk_audit' => true,
            'multiple_patient_ids' => true,
            'patient_verification' => true,
            'clinical_units_display' => true,
            'reference_range_display' => true,
            'allergy_check' => true,
            'duplicate_med_check' => true,
            'generic_brand_distinction' => true,
        ];

        $result = $this->accessibilityService->runFullAudit($config);

        $this->assertArrayHasKey('accessibility', $result);
        $this->assertArrayHasKey('nepal_localization', $result);
        $this->assertArrayHasKey('clinical_safety', $result);
        $this->assertArrayHasKey('overall', $result);

        $this->assertEquals(21, $result['overall']['total']);
        $this->assertGreaterThan(0, $result['overall']['passed']);
        $this->assertGreaterThanOrEqual(0, $result['overall']['failures']);
        $this->assertGreaterThanOrEqual(0.0, $result['overall']['score']);
        $this->assertLessThanOrEqual(100.0, $result['overall']['score']);
    }

    /** @test */
    public function it_fails_keyboard_inaccessibility(): void
    {
        $result = $this->accessibilityService->auditAccessibility([
            'keyboard_complete' => false,
        ]);

        $keyboardCheck = collect($result['checks'])->firstWhere('rule', 'keyboard-accessibility');
        $this->assertEquals('fail', $keyboardCheck['status']);
        $this->assertEquals('critical', $keyboardCheck['severity']);
    }

    /** @test */
    public function it_fails_insufficient_color_contrast(): void
    {
        $result = $this->accessibilityService->auditAccessibility([
            'min_contrast_ratio' => 2.0, // Below AA minimum
        ]);

        $contrastCheck = collect($result['checks'])->firstWhere('rule', 'color-contrast-aa');
        $this->assertEquals('fail', $contrastCheck['status']);
        $this->assertEquals(2.0, $contrastCheck['measured']);
    }

    /** @test */
    public function it_fails_missing_nepali_support(): void
    {
        $result = $this->accessibilityService->validateNepalLocalization([
            'supported_locales' => ['en'], // Missing Nepali
            'catalog_parity_enforced' => false,
        ]);

        $nepaliCheck = collect($result['checks'])->firstWhere('rule', 'nepali-language-support');
        $this->assertEquals('fail', $nepaliCheck['status']);
    }

    /** @test */
    public function it_fails_wrong_currency(): void
    {
        $result = $this->accessibilityService->validateNepalLocalization([
            'default_currency' => 'USD',
        ]);

        $currencyCheck = collect($result['checks'])->firstWhere('rule', 'nepal-currency');
        $this->assertEquals('fail', $currencyCheck['status']);
        $this->assertEquals('NPR', $currencyCheck['expected']);
        $this->assertEquals('USD', $currencyCheck['actual']);
    }

    /** @test */
    public function it_fails_missing_identity_spine(): void
    {
        $result = $this->accessibilityService->validateClinicalUISafety([
            'identity_spine' => false,
        ]);

        $spineCheck = collect($result['checks'])->firstWhere('rule', 'identity-spine');
        $this->assertEquals('fail', $spineCheck['status']);
        $this->assertEquals('critical', $spineCheck['severity']);
    }

    /** @test */
    public function it_fails_missing_high_risk_confirmation(): void
    {
        $result = $this->accessibilityService->validateClinicalUISafety([
            'high_risk_confirmation' => false,
            'high_risk_audit' => false,
        ]);

        $riskCheck = collect($result['checks'])->firstWhere('rule', 'high-risk-actions');
        $this->assertEquals('fail', $riskCheck['status']);
    }

    /** @test */
    public function it_provides_warnings_for_incomplete_features(): void
    {
        $result = $this->accessibilityService->auditAccessibility([
            'keyboard_complete' => true,
            'aria_labels_count' => 10, // Partial
            'role_attributes_count' => 5, // Partial
            'skip_to_content' => false, // Missing
        ]);

        $srCheck = collect($result['checks'])->firstWhere('rule', 'screen-reader-support');
        $this->assertEquals('warning', $srCheck['status']);
    }

    /** @test */
    public function it_validates_nepal_phone_format(): void
    {
        $result = $this->accessibilityService->validateNepalLocalization([
            'nepal_phone_validation' => true,
        ]);

        $phoneCheck = collect($result['checks'])->firstWhere('rule', 'nepal-phone-format');
        $this->assertEquals('pass', $phoneCheck['status']);
        $this->assertNotEmpty($phoneCheck['pattern']);
    }

    /** @test */
    public function it_validates_low_bandwidth_readiness(): void
    {
        $result = $this->accessibilityService->validateNepalLocalization([
            'lazy_loading' => true,
            'error_boundaries' => true,
            'loading_states' => true,
        ]);

        $bandwidthCheck = collect($result['checks'])->firstWhere('rule', 'low-bandwidth-readiness');
        $this->assertEquals('pass', $bandwidthCheck['status']);
        $this->assertTrue($bandwidthCheck['lazy_loading']);
        $this->assertTrue($bandwidthCheck['error_boundaries']);
    }

    /** @test */
    public function it_validates_medication_safety_completeness(): void
    {
        $result = $this->accessibilityService->validateClinicalUISafety([
            'allergy_check' => true,
            'duplicate_med_check' => true,
            'generic_brand_distinction' => true,
        ]);

        $medCheck = collect($result['checks'])->firstWhere('rule', 'medication-safety');
        $this->assertEquals('pass', $medCheck['status']);
        $this->assertEquals(3, $medCheck['checks_passed']);
    }

    /** @test */
    public function it_validates_reduced_motion_support(): void
    {
        $result = $this->accessibilityService->auditAccessibility([
            'reduced_motion' => true,
        ]);

        $motionCheck = collect($result['checks'])->firstWhere('rule', 'reduced-motion');
        $this->assertEquals('pass', $motionCheck['status']);
    }

    /** @test */
    public function it_validates_touch_target_size(): void
    {
        $result = $this->accessibilityService->auditAccessibility([
            'min_touch_target_px' => 48, // Exceeds minimum
        ]);

        $touchCheck = collect($result['checks'])->firstWhere('rule', 'touch-targets');
        $this->assertEquals('pass', $touchCheck['status']);
        $this->assertEquals(48, $touchCheck['measured_px']);
    }

    /** @test */
    public function accessibility_constants_are_correct(): void
    {
        $this->assertEquals(4.5, AccessibilityService::MIN_CONTRAST_RATIO_AA);
        $this->assertEquals(3.0, AccessibilityService::MIN_CONTRAST_RATIO_LARGE);
        $this->assertEquals(44, AccessibilityService::MIN_TOUCH_TARGET_PX);
        $this->assertEquals(14, AccessibilityService::MIN_FONT_SIZE_PX);
        $this->assertEquals(80, AccessibilityService::MAX_LINE_LENGTH_CHARS);
    }

    /** @test */
    public function nepal_constants_are_correct(): void
    {
        $this->assertEquals('NPR', AccessibilityService::NEPAL_CURRENCY);
        $this->assertEquals('Asia/Kathmandu', AccessibilityService::NEPAL_TIMEZONE);
        $this->assertNotEmpty(AccessibilityService::NEPAL_PHONE_PATTERN);
        $this->assertEquals('YYYY-MM-DD', AccessibilityService::NEPAL_DATE_FORMAT);
    }

    /** @test */
    public function breakpoints_are_defined_correctly(): void
    {
        $breakpoints = AccessibilityService::BREAKPOINTS;

        $this->assertArrayHasKey('mobile', $breakpoints);
        $this->assertArrayHasKey('tablet_small', $breakpoints);
        $this->assertArrayHasKey('tablet', $breakpoints);
        $this->assertArrayHasKey('laptop', $breakpoints);
        $this->assertArrayHasKey('desktop', $breakpoints);
        $this->assertArrayHasKey('desktop_wide', $breakpoints);

        // Verify ascending order
        $values = array_values($breakpoints);
        for ($i = 1; $i < count($values); $i++) {
            $this->assertGreaterThan($values[$i - 1], $values[$i]);
        }
    }
}
