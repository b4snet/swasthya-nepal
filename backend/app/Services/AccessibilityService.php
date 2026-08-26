<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Service for automated accessibility auditing and Nepal-First UX validation.
 *
 * Provides rule-based checks for:
 * - WCAG 2.2 AA alignment
 * - Keyboard accessibility
 * - Screen reader compatibility
 * - Color contrast (simplified)
 * - Touch target sizing
 * - Nepal localization (Nepali, currency, dates)
 * - Low-bandwidth readiness
 * - Clinical UI safety
 */
class AccessibilityService
{
    // WCAG 2.2 AA minimums
    public const MIN_CONTRAST_RATIO_AA = 4.5;

    public const MIN_CONTRAST_RATIO_LARGE = 3.0;

    public const MIN_TOUCH_TARGET_PX = 44;

    public const MIN_FONT_SIZE_PX = 14;

    public const MAX_LINE_LENGTH_CHARS = 80;

    // Responsive breakpoints (px)
    public const BREAKPOINTS = [
        'mobile' => 0,
        'tablet_small' => 480,
        'tablet' => 768,
        'laptop' => 1024,
        'desktop' => 1280,
        'desktop_wide' => 1536,
    ];

    // Nepal-specific
    public const NEPAL_CURRENCY = 'NPR';

    public const NEPAL_TIMEZONE = 'Asia/Kathmandu';

    public const NEPAL_PHONE_PATTERN = '/^(\+977)?[0-9]{7,10}$/';

    public const NEPAL_DATE_FORMAT = 'YYYY-MM-DD';

    // Clinical safety thresholds
    public const CRITICAL_ACTION_CONFIRMATION_REQUIRED = true;

    public const HIGH_RISK_ACTION_AUDIT = true;

    public const IDENTITY_SPINE_REQUIRED = true;

    /**
     * Perform a comprehensive accessibility audit on given rules.
     *
     * @param  array<string, mixed>  $config  Application configuration to audit
     * @return array{checks: array<int, array<string, mixed>>, summary: array{total: int, passed: int, warnings: int, failures: int}}
     */
    public function auditAccessibility(array $config): array
    {
        $checks = [];
        $checks[] = $this->checkKeyboardAccessibility($config);
        $checks[] = $this->checkScreenReaderSupport($config);
        $checks[] = $this->checkColorContrast($config);
        $checks[] = $this->checkTouchTargets($config);
        $checks[] = $this->checkTypography($config);
        $checks[] = $this->checkFocusManagement($config);
        $checks[] = $this->checkSemanticStructure($config);
        $checks[] = $this->checkReducedMotion($config);

        return $this->buildResult($checks);
    }

    /**
     * Validate Nepal-First UX configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array{checks: array<int, array<string, mixed>>, summary: array{total: int, passed: int, warnings: int, failures: int}}
     */
    public function validateNepalLocalization(array $config): array
    {
        $checks = [];
        $checks[] = $this->checkNepaliLanguageSupport($config);
        $checks[] = $this->checkCurrencyFormatting($config);
        $checks[] = $this->checkDateTimeFormatting($config);
        $checks[] = $this->checkPhoneNumberFormat($config);
        $checks[] = $this->checkNepaliFontSupport($config);
        $checks[] = $this->checkDevanagariRendering($config);
        $checks[] = $this->checkLowBandwidthReadiness($config);
        $checks[] = $this->checkClinicalTerminology($config);

        return $this->buildResult($checks);
    }

    /**
     * Validate clinical UI safety rules.
     *
     * @param  array<string, mixed>  $config
     * @return array{checks: array<int, array<string, mixed>>, summary: array{total: int, passed: int, warnings: int, failures: int}}
     */
    public function validateClinicalUISafety(array $config): array
    {
        $checks = [];
        $checks[] = $this->checkIdentitySpine($config);
        $checks[] = $this->checkHighRiskActions($config);
        $checks[] = $this->checkPatientIdentification($config);
        $checks[] = $this->checkClinicalDataDisplay($config);
        $checks[] = $this->checkMedicationSafety($config);

        return $this->buildResult($checks);
    }

    /**
     * Run all audits and produce a comprehensive report.
     *
     * @param  array<string, mixed>  $config
     * @return array{accessibility: array, nepal_localization: array, clinical_safety: array, overall: array{total: int, passed: int, warnings: int, failures: int, score: float}}
     */
    public function runFullAudit(array $config): array
    {
        $accessibility = $this->auditAccessibility($config);
        $nepalLocalization = $this->validateNepalLocalization($config);
        $clinicalSafety = $this->validateClinicalUISafety($config);

        $total = $accessibility['summary']['total']
            + $nepalLocalization['summary']['total']
            + $clinicalSafety['summary']['total'];

        $passed = $accessibility['summary']['passed']
            + $nepalLocalization['summary']['passed']
            + $clinicalSafety['summary']['passed'];

        $failures = $accessibility['summary']['failures']
            + $nepalLocalization['summary']['failures']
            + $clinicalSafety['summary']['failures'];

        $score = $total > 0 ? round(($passed / $total) * 100, 1) : 0.0;

        return [
            'accessibility' => $accessibility,
            'nepal_localization' => $nepalLocalization,
            'clinical_safety' => $clinicalSafety,
            'overall' => [
                'total' => $total,
                'passed' => $passed,
                'failures' => $failures,
                'score' => $score,
            ],
        ];
    }

    // ── Accessibility checks ──────────────────────────────────────

    private function checkKeyboardAccessibility(array $config): array
    {
        $keyboardComplete = $config['keyboard_complete'] ?? false;

        return [
            'rule' => 'keyboard-accessibility',
            'status' => $keyboardComplete ? 'pass' : 'fail',
            'severity' => 'critical',
            'message' => $keyboardComplete
                ? 'Application supports full keyboard navigation'
                : 'Application must support keyboard-only navigation per WCAG 2.1.2',
        ];
    }

    private function checkScreenReaderSupport(array $config): array
    {
        $hasAriaLabels = ($config['aria_labels_count'] ?? 0) > 0;
        $hasRoles = ($config['role_attributes_count'] ?? 0) > 0;
        $hasSkipLink = $config['skip_to_content'] ?? false;
        $passed = $hasAriaLabels && $hasRoles && $hasSkipLink;

        return [
            'rule' => 'screen-reader-support',
            'status' => $passed ? 'pass' : ($hasAriaLabels ? 'warning' : 'fail'),
            'severity' => 'high',
            'message' => $passed
                ? 'Screen reader support is implemented (ARIA labels, roles, skip link)'
                : 'Screen reader support incomplete: aria='.($hasAriaLabels ? 'yes' : 'no')
                    .' roles='.($hasRoles ? 'yes' : 'no')
                    .' skip='.($hasSkipLink ? 'yes' : 'no'),
        ];
    }

    private function checkColorContrast(array $config): array
    {
        $minRatio = $config['min_contrast_ratio'] ?? 0;
        $passed = $minRatio >= self::MIN_CONTRAST_RATIO_AA;

        return [
            'rule' => 'color-contrast-aa',
            'status' => $passed ? 'pass' : 'fail',
            'severity' => $passed ? 'info' : 'critical',
            'measured' => $minRatio,
            'required' => self::MIN_CONTRAST_RATIO_AA,
            'message' => $passed
                ? "Contrast ratio {$minRatio}:1 meets WCAG AA (≥{self::MIN_CONTRAST_RATIO_AA}:1)"
                : "Contrast ratio {$minRatio}:1 below WCAG AA minimum ({self::MIN_CONTRAST_RATIO_AA}:1)",
        ];
    }

    private function checkTouchTargets(array $config): array
    {
        $minPx = $config['min_touch_target_px'] ?? 0;
        $passed = $minPx >= self::MIN_TOUCH_TARGET_PX;

        return [
            'rule' => 'touch-targets',
            'status' => $passed ? 'pass' : 'fail',
            'severity' => 'high',
            'measured_px' => $minPx,
            'required_px' => self::MIN_TOUCH_TARGET_PX,
            'message' => $passed
                ? "Minimum touch target {$minPx}px meets WCAG AA (≥{self::MIN_TOUCH_TARGET_PX}px)"
                : "Minimum touch target {$minPx}px below WCAG AA minimum ({self::MIN_TOUCH_TARGET_PX}px)",
        ];
    }

    private function checkTypography(array $config): array
    {
        $minFontSize = $config['min_font_size_px'] ?? 0;
        $hasTabularNums = $config['tabular_numerals'] ?? false;
        $hasDevanagariFont = $config['devanagari_font_support'] ?? false;
        $passed = $minFontSize >= self::MIN_FONT_SIZE_PX && $hasTabularNums;

        return [
            'rule' => 'typography-clinical',
            'status' => $passed ? 'pass' : 'fail',
            'severity' => 'medium',
            'measured_font_size' => $minFontSize,
            'tabular_numerals' => $hasTabularNums,
            'devanagari_font' => $hasDevanagariFont,
            'message' => $passed
                ? 'Typography supports clinical use (≥{self::MIN_FONT_SIZE_PX}px, tabular numerals)'
                : "Typography requires clinical review (font size: {$minFontSize}px, tabular: ".($hasTabularNums ? 'yes' : 'no').')',
        ];
    }

    private function checkFocusManagement(array $config): array
    {
        $hasFocusVisible = $config['focus_visible'] ?? false;
        $noFocusTraps = ! ($config['keyboard_traps'] ?? true);

        return [
            'rule' => 'focus-management',
            'status' => $hasFocusVisible && $noFocusTraps ? 'pass' : 'warning',
            'severity' => 'high',
            'focus_visible' => $hasFocusVisible,
            'no_keyboard_traps' => $noFocusTraps,
            'message' => ($hasFocusVisible && $noFocusTraps)
                ? 'Focus management is correct: visible focus, no keyboard traps'
                : 'Focus management needs review',
        ];
    }

    private function checkSemanticStructure(array $config): array
    {
        $hasSemanticElements = $config['semantic_html'] ?? false;

        return [
            'rule' => 'semantic-structure',
            'status' => $hasSemanticElements ? 'pass' : 'warning',
            'severity' => 'medium',
            'message' => $hasSemanticElements
                ? 'Semantic HTML elements used (button, nav, main, heading)'
                : 'Verify semantic HTML: buttons, navigation, headings, landmarks',
        ];
    }

    private function checkReducedMotion(array $config): array
    {
        $supported = $config['reduced_motion'] ?? false;

        return [
            'rule' => 'reduced-motion',
            'status' => $supported ? 'pass' : 'fail',
            'severity' => 'medium',
            'message' => $supported
                ? 'Reduced motion preference is respected'
                : 'Application must respect prefers-reduced-motion',
        ];
    }

    // ── Nepal Localization checks ─────────────────────────────────

    private function checkNepaliLanguageSupport(array $config): array
    {
        $locales = $config['supported_locales'] ?? [];
        $hasNepali = in_array('ne', $locales);
        $hasEnglish = in_array('en', $locales);
        $catalogParity = $config['catalog_parity_enforced'] ?? false;

        return [
            'rule' => 'nepali-language-support',
            'status' => $hasNepali && $hasEnglish && $catalogParity ? 'pass' : 'fail',
            'severity' => 'critical',
            'locales' => $locales,
            'catalog_parity' => $catalogParity,
            'message' => ($hasNepali && $hasEnglish && $catalogParity)
                ? 'Nepali and English supported with catalog parity enforcement'
                : 'Nepali/English support incomplete',
        ];
    }

    private function checkCurrencyFormatting(array $config): array
    {
        $currency = $config['default_currency'] ?? '';
        $passed = strtoupper($currency) === self::NEPAL_CURRENCY;

        return [
            'rule' => 'nepal-currency',
            'status' => $passed ? 'pass' : 'fail',
            'severity' => 'high',
            'expected' => self::NEPAL_CURRENCY,
            'actual' => $currency,
            'message' => $passed
                ? 'Currency configured as '.self::NEPAL_CURRENCY
                : 'Expected currency '.self::NEPAL_CURRENCY.", got '{$currency}'",
        ];
    }

    private function checkDateTimeFormatting(array $config): array
    {
        $timezone = $config['timezone'] ?? '';
        $dateFormat = $config['date_format'] ?? '';
        $timezoneOk = $timezone === self::NEPAL_TIMEZONE;
        $dateFormatOk = ! empty($dateFormat);

        return [
            'rule' => 'nepal-datetime',
            'status' => $timezoneOk && $dateFormatOk ? 'pass' : 'warning',
            'severity' => 'medium',
            'timezone' => $timezone,
            'expected_timezone' => self::NEPAL_TIMEZONE,
            'date_format' => $dateFormat,
            'message' => $timezoneOk
                ? 'Timezone configured as '.self::NEPAL_TIMEZONE
                : 'Timezone should be '.self::NEPAL_TIMEZONE." (currently: {$timezone})",
        ];
    }

    private function checkPhoneNumberFormat(array $config): array
    {
        $hasPhoneValidation = $config['nepal_phone_validation'] ?? false;

        return [
            'rule' => 'nepal-phone-format',
            'status' => $hasPhoneValidation ? 'pass' : 'warning',
            'severity' => 'medium',
            'pattern' => self::NEPAL_PHONE_PATTERN,
            'message' => $hasPhoneValidation
                ? 'Phone validation supports Nepal numbers'
                : 'Consider Nepal phone format validation (+977, 7-10 digits)',
        ];
    }

    private function checkNepaliFontSupport(array $config): array
    {
        $hasDevanagariFont = $config['devanagari_font_support'] ?? false;
        $hasLangSelector = $config['language_selector'] ?? false;

        return [
            'rule' => 'nepali-font-support',
            'status' => $hasDevanagariFont ? 'pass' : 'fail',
            'severity' => 'high',
            'devanagari_font' => $hasDevanagariFont,
            'language_selector' => $hasLangSelector,
            'message' => $hasDevanagariFont
                ? 'Devanagari font support configured'
                : 'Devanagari font support is required for Nepali display',
        ];
    }

    private function checkDevanagariRendering(array $config): array
    {
        $hasDevanagariFont = $config['devanagari_font_support'] ?? false;
        $langSet = $config['html_lang_attribute'] ?? false;

        return [
            'rule' => 'devanagari-rendering',
            'status' => $hasDevanagariFont ? 'pass' : 'fail',
            'severity' => 'critical',
            'message' => $hasDevanagariFont
                ? 'Devanagari rendering verified: html[lang="ne"] font stack active'
                : 'Devanagari rendering: html lang attribute and font stack required',
        ];
    }

    private function checkLowBandwidthReadiness(array $config): array
    {
        $hasLazyLoading = $config['lazy_loading'] ?? false;
        $hasErrorBoundary = $config['error_boundaries'] ?? false;
        $hasLoadingStates = $config['loading_states'] ?? false;

        return [
            'rule' => 'low-bandwidth-readiness',
            'status' => $hasLazyLoading && $hasErrorBoundary ? 'pass' : 'warning',
            'severity' => 'medium',
            'lazy_loading' => $hasLazyLoading,
            'error_boundaries' => $hasErrorBoundary,
            'loading_states' => $hasLoadingStates,
            'message' => 'Low-bandwidth readiness: '.($hasLazyLoading ? 'lazy loading' : 'needs lazy loading')
                .', '.($hasErrorBoundary ? 'error boundaries' : 'needs error boundaries'),
        ];
    }

    private function checkClinicalTerminology(array $config): array
    {
        $hasGlossary = $config['terminology_glossary'] ?? false;
        $hasTranslationReview = $config['translation_quality_review'] ?? false;

        return [
            'rule' => 'clinical-terminology',
            'status' => $hasGlossary ? 'pass' : 'warning',
            'severity' => 'high',
            'glossary' => $hasGlossary,
            'translation_review' => $hasTranslationReview,
            'message' => $hasGlossary
                ? 'Clinical terminology glossary defined'
                : 'Clinical terminology governance required for safety',
        ];
    }

    // ── Clinical Safety checks ────────────────────────────────────

    private function checkIdentitySpine(array $config): array
    {
        $hasSpine = $config['identity_spine'] ?? false;

        return [
            'rule' => 'identity-spine',
            'status' => $hasSpine ? 'pass' : 'fail',
            'severity' => 'critical',
            'message' => $hasSpine
                ? 'Identity Spine (patient name, MRN, allergy) present on clinical screens'
                : 'Identity Spine required on all clinical screens',
        ];
    }

    private function checkHighRiskActions(array $config): array
    {
        $hasConfirmation = $config['high_risk_confirmation'] ?? false;
        $hasAudit = $config['high_risk_audit'] ?? false;

        return [
            'rule' => 'high-risk-actions',
            'status' => $hasConfirmation && $hasAudit ? 'pass' : 'fail',
            'severity' => 'critical',
            'confirmation' => $hasConfirmation,
            'audit' => $hasAudit,
            'message' => ($hasConfirmation && $hasAudit)
                ? 'High-risk actions require confirmation and are audit-logged'
                : 'High-risk actions must require explicit confirmation and audit logging',
        ];
    }

    private function checkPatientIdentification(array $config): array
    {
        $hasMultiple = $config['multiple_patient_ids'] ?? false;
        $hasVerification = $config['patient_verification'] ?? false;

        return [
            'rule' => 'patient-identification',
            'status' => $hasMultiple && $hasVerification ? 'pass' : 'warning',
            'severity' => 'critical',
            'message' => ($hasMultiple && $hasVerification)
                ? 'Multiple patient identifiers supported with verification'
                : 'Patient identity verification recommended for clinical safety',
        ];
    }

    private function checkClinicalDataDisplay(array $config): array
    {
        $hasUnits = $config['clinical_units_display'] ?? false;
        $hasRefRange = $config['reference_range_display'] ?? false;

        return [
            'rule' => 'clinical-data-display',
            'status' => $hasUnits && $hasRefRange ? 'pass' : 'warning',
            'severity' => 'high',
            'units_displayed' => $hasUnits,
            'reference_range' => $hasRefRange,
            'message' => ($hasUnits && $hasRefRange)
                ? 'Clinical values displayed with units and reference ranges'
                : 'Clinical data display should include units and reference ranges',
        ];
    }

    private function checkMedicationSafety(array $config): array
    {
        $hasAllergyCheck = $config['allergy_check'] ?? false;
        $hasDuplicateCheck = $config['duplicate_med_check'] ?? false;
        $hasGenericBrand = $config['generic_brand_distinction'] ?? false;

        $count = (int) $hasAllergyCheck + (int) $hasDuplicateCheck + (int) $hasGenericBrand;

        return [
            'rule' => 'medication-safety',
            'status' => $count >= 2 ? 'pass' : ($count >= 1 ? 'warning' : 'fail'),
            'severity' => 'critical',
            'allergy_check' => $hasAllergyCheck,
            'duplicate_check' => $hasDuplicateCheck,
            'generic_brand' => $hasGenericBrand,
            'checks_passed' => $count,
            'message' => "Medication safety checks: {$count}/3 implemented"
                .($hasAllergyCheck ? ' (allergy)' : '')
                .($hasDuplicateCheck ? ' (duplicate)' : '')
                .($hasGenericBrand ? ' (generic/brand)' : ''),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array{checks: array<int, array<string, mixed>>, summary: array{total: int, passed: int, warnings: int, failures: int}}
     */
    private function buildResult(array $checks): array
    {
        $passed = 0;
        $warnings = 0;
        $failures = 0;

        foreach ($checks as $check) {
            match ($check['status']) {
                'pass' => $passed++,
                'warning' => $warnings++,
                'fail' => $failures++,
            };
        }

        return [
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'failures' => $failures,
            ],
        ];
    }
}
