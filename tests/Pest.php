<?php

declare(strict_types=1);

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\Tests\UnitTestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests use the full TestCase (with RefreshDatabase).
| Unit tests use the lighter UnitTestCase (no database refresh).
|
*/

uses(UnitTestCase::class)->in('Unit');
uses(TestCase::class)->in('Feature');
