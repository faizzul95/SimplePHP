<?php

declare(strict_types=1);

use Components\Validation;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for numeric, max_length, min_length, max, min, between,
 * and size rules — covering mb_strlen multibyte correctness and the
 * validateNumeric '0' edge case.
 */
final class ValidationRulesTest extends TestCase
{
    // -----------------------------------------------------------------------
    // validateNumeric — '0' must pass, null/empty skipped (nullable upstream)
    // -----------------------------------------------------------------------

    public function testNumericRuleAcceptsZeroString(): void
    {
        $validator = Validation::make(['qty' => '0'], ['qty' => 'numeric'])->validate();
        self::assertTrue($validator->passed(), 'Numeric rule must accept string "0"');
    }

    public function testNumericRuleAcceptsZeroInt(): void
    {
        $validator = Validation::make(['qty' => 0], ['qty' => 'numeric'])->validate();
        self::assertTrue($validator->passed(), 'Numeric rule must accept integer 0');
    }

    public function testNumericRuleAcceptsPositiveNumber(): void
    {
        $validator = Validation::make(['qty' => '42'], ['qty' => 'numeric'])->validate();
        self::assertTrue($validator->passed());
    }

    public function testNumericRuleAcceptsNegativeNumber(): void
    {
        $validator = Validation::make(['qty' => '-5'], ['qty' => 'numeric'])->validate();
        self::assertTrue($validator->passed());
    }

    public function testNumericRuleRejectsNonNumericString(): void
    {
        $validator = Validation::make(['qty' => 'abc'], ['qty' => 'numeric'])->validate();
        self::assertFalse($validator->passed());
    }

    // -----------------------------------------------------------------------
    // max_length / min_length — multibyte (CJK / emoji) character counts
    // -----------------------------------------------------------------------

    public function testMaxLengthCountsMultibyteCharactersNotBytes(): void
    {
        // 5 Chinese characters = 15 bytes in UTF-8, must pass max_length:5
        $fiveChinese = '你好世界啊';
        $validator = Validation::make(
            ['field' => $fiveChinese],
            ['field' => 'max_length:5']
        )->validate();
        self::assertTrue($validator->passed(), 'max_length must count characters, not bytes');
    }

    public function testMaxLengthRejectsStringExceedingCharacterLimit(): void
    {
        // 6 characters must fail max_length:5
        $sixChinese = '你好世界啊哦';
        $validator = Validation::make(
            ['field' => $sixChinese],
            ['field' => 'max_length:5']
        )->validate();
        self::assertFalse($validator->passed());
    }

    public function testMinLengthCountsMultibyteCharacters(): void
    {
        // 3 emoji characters must pass min_length:3
        $threeEmoji = '😀😁😂';
        $validator = Validation::make(
            ['field' => $threeEmoji],
            ['field' => 'min_length:3']
        )->validate();
        self::assertTrue($validator->passed(), 'min_length must count characters, not bytes');
    }

    public function testMinLengthRejectsStringBelowCharacterLimit(): void
    {
        // 2 emoji characters must fail min_length:3
        $twoEmoji = '😀😁';
        $validator = Validation::make(
            ['field' => $twoEmoji],
            ['field' => 'min_length:3']
        )->validate();
        self::assertFalse($validator->passed());
    }

    // -----------------------------------------------------------------------
    // max / min string variants — multibyte
    // -----------------------------------------------------------------------

    public function testMaxRuleForStringsCountsMultibyteCharacters(): void
    {
        $fiveArabic = 'مرحبا'; // 5 characters, 10 bytes
        $validator = Validation::make(
            ['field' => $fiveArabic],
            ['field' => 'max:5']
        )->validate();
        self::assertTrue($validator->passed(), 'max rule on strings must count characters, not bytes');
    }

    public function testMinRuleForStringsCountsMultibyteCharacters(): void
    {
        $threeArabic = 'مرح'; // 3 characters, 6 bytes
        $validator = Validation::make(
            ['field' => $threeArabic],
            ['field' => 'min:3']
        )->validate();
        self::assertTrue($validator->passed(), 'min rule on strings must count characters, not bytes');
    }

    // -----------------------------------------------------------------------
    // between — string multibyte
    // -----------------------------------------------------------------------

    public function testBetweenRuleCountsMultibyteCharacters(): void
    {
        $fourChinese = '你好世界';  // 4 chars, 12 bytes
        $validator = Validation::make(
            ['field' => $fourChinese],
            ['field' => 'between:3,5']
        )->validate();
        self::assertTrue($validator->passed(), 'between rule on strings must use character count');
    }

    public function testBetweenRuleRejectsStringOutsideRange(): void
    {
        $twoChinese = '你好';  // 2 chars
        $validator = Validation::make(
            ['field' => $twoChinese],
            ['field' => 'between:3,5']
        )->validate();
        self::assertFalse($validator->passed());
    }

    // -----------------------------------------------------------------------
    // size — string multibyte
    // -----------------------------------------------------------------------

    public function testSizeRuleCountsMultibyteCharacters(): void
    {
        $twoEmoji = '😀😁';  // 2 chars, 8 bytes
        $validator = Validation::make(
            ['field' => $twoEmoji],
            ['field' => 'size:2']
        )->validate();
        self::assertTrue($validator->passed(), 'size rule on strings must count characters, not bytes');
    }

    // -----------------------------------------------------------------------
    // emails rule — comma-separated email list
    // -----------------------------------------------------------------------

    public function testEmailsRuleAcceptsSingleValidEmail(): void
    {
        $validator = Validation::make(
            ['cc' => 'alice@example.com'],
            ['cc' => 'emails']
        )->validate();
        self::assertTrue($validator->passed());
    }

    public function testEmailsRuleAcceptsMultipleValidEmails(): void
    {
        $validator = Validation::make(
            ['cc' => 'alice@example.com, bob@example.com, carol@test.org'],
            ['cc' => 'emails']
        )->validate();
        self::assertTrue($validator->passed());
    }

    public function testEmailsRuleAcceptsEmailsWithNoSpaceAfterComma(): void
    {
        $validator = Validation::make(
            ['cc' => 'alice@example.com,bob@example.com'],
            ['cc' => 'emails']
        )->validate();
        self::assertTrue($validator->passed());
    }

    public function testEmailsRuleRejectsOneInvalidEmailAmongMany(): void
    {
        $validator = Validation::make(
            ['cc' => 'alice@example.com, not-an-email, carol@test.org'],
            ['cc' => 'emails']
        )->validate();
        self::assertFalse($validator->passed());
        self::assertArrayHasKey('cc', $validator->getErrors());
    }

    public function testEmailsRuleRejectsPlainInvalidString(): void
    {
        $validator = Validation::make(
            ['cc' => 'not-an-email'],
            ['cc' => 'emails']
        )->validate();
        self::assertFalse($validator->passed());
    }

    public function testEmailsRulePassesOnNullableNull(): void
    {
        $validator = Validation::make(
            ['cc' => null],
            ['cc' => 'nullable|emails']
        )->validate();
        self::assertTrue($validator->passed());
    }
}
