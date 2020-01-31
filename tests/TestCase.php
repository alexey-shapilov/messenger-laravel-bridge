<?php

declare(strict_types = 1);

namespace Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCaseAlias;
use ReflectionClass;
use ReflectionException;
use SA\MessengerBridge\Provider;

/**
 * Class TestCase
 */
abstract class TestCase extends OrchestraTestCaseAlias
{
    /**
     * Load package service provider
     *
     * @param Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            Provider::class,
        ];
    }

    /**
     * @param string $propertyName
     * @param $object
     *
     * @return mixed
     * @throws ReflectionException
     */
    public function getEncapsulateProperty(string $propertyName, $object)
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    /**
     * @param string $constantName
     * @param $object
     *
     * @return mixed
     * @throws ReflectionException
     */
    public function getEncapsulateConstant(string $constantName, $object)
    {
        $reflection = new ReflectionClass($object);

        return $reflection->getConstant($constantName);
    }
}
