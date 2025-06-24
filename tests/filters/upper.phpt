<?php

/**
 * Test: Latte\Essential\Filters::upper
 * @phpExtension mbstring
 */

declare(strict_types=1);

use Latte\Essential\Filters;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

Assert::same('HELLO', Filters::upper('hello'));
Assert::same('ČESKÝ', Filters::upper('český'));
Assert::same('ABC123', Filters::upper('abc123'));
Assert::same('', Filters::upper(''));
