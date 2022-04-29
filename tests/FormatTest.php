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

    public function testAgentAddressPartsSplitFromEnvVar(): void
    {
        $weirdlyFormattedAddress = str_replace(' ', '\\s', 'Dragon Court,Macklin Street,London,WC2B 5LX');

        $agentAddress = Format::agentAddressFromEnvVar($weirdlyFormattedAddress);

        $this->assertCount(3, $agentAddress);
        $this->assertArrayHasKey('line', $agentAddress);
        $this->assertArrayHasKey('postcode', $agentAddress);
        $this->assertArrayHasKey('country', $agentAddress);

        $this->assertCount(3, $agentAddress['line']);
        $this->assertEquals('WC2B 5LX', $agentAddress['postcode']);
        $this->assertEquals('United Kingdom', $agentAddress['country']);
    }
}
