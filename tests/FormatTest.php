<?php

declare(strict_types=1);

namespace ClaimBot\Tests;

use ClaimBot\Format;

class FormatTest extends TestCase
{
    public function testDetectCrownDepLooselyFormatted(): void
    {
        $this->assertTrue(Format::isCrownDependencyPseudoPostcode('im11aa'));
    }

    public function testDetectCrownDepStrictlyFormatted(): void
    {
        $this->assertTrue(Format::isCrownDependencyPseudoPostcode('GY1 1AA'));
    }

    public function testDetectNonCrownDepLooselyFormatted(): void
    {
        $this->assertFalse(Format::isCrownDependencyPseudoPostcode('zy11aa'));
    }

    public function testDetectNonCrownDepStrictlyFormatted(): void
    {
        $this->assertFalse(Format::isCrownDependencyPseudoPostcode('ZY1 1AA'));
    }

    public function testFormatCrownDep(): void
    {
        $this->assertEquals('IM1 1AA', Format::formatCrownDependencyPseudoPostcode('im11aa'));
    }

    public function testFormatInvalidGivesBlankString(): void
    {
        $this->assertEquals('', Format::formatCrownDependencyPseudoPostcode('N1 1AA'));
    }
}
