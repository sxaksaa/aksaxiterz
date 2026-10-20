<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;

class HeaderParsingRegressionTest extends TestCase
{
    public function test_long_whitespace_before_header_separators_does_not_exhaust_the_parser(): void
    {
        $header = str_repeat('a'.str_repeat(' ', 25).',', 200);
        $parts = HeaderUtils::split($header, ',=');

        $this->assertSame(PREG_NO_ERROR, preg_last_error());
        $this->assertCount(200, $parts);
        $this->assertSame(['a'], $parts[199]);

        $this->assertSame(
            [['x', '1'], ['y', '2']],
            HeaderUtils::split('x=1'.str_repeat("\t", 25).';y=2', ';=')
        );
        $this->assertSame(PREG_NO_ERROR, preg_last_error());
    }

    public function test_normal_cache_control_and_quoted_values_are_preserved(): void
    {
        $this->assertSame(
            [['public'], ['max-age', '60'], ['custom', 'a b, c']],
            HeaderUtils::split('public, max-age=60, custom="a b, c"', ',=')
        );
    }
}
