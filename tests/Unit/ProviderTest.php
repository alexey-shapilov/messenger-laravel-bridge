<?php

declare(strict_types = 1);

namespace Tests;

use ReflectionException;

/**
 * Class ProviderTest
 * @package Tests
 */
class ProviderTest extends TestCase
{
    /**
     * @inheritDoc
     */
    protected function getEnvironmentSetUp($app)
    {
    }

    /**
     * @throws ReflectionException
     */
    public function testConfig()
    {
        $config = $this->app->get('config')->get('messenger', []);

        $this->assertTrue(count($config) !== 0);
    }
}
