<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter;

use Symfony\Component\VarExporter\Exception\ClassNotFoundException;
use Symfony\Component\VarExporter\Exception\ExceptionInterface;
use Symfony\Component\VarExporter\Exception\NotInstantiableTypeException;

/**
 * A utility class to create objects without calling their constructor.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class Instantiator
{
    /**
     * Creates an object and sets its properties without calling its constructor nor any other methods.
     *
     * @see Hydrator::hydrate() for examples
     *
     * @template T of object
     *
     * @param class-string<T>                           $class       The class of the instance to create
     * @param array<string, mixed>                      $mangledVars The properties to set on the instance
     * @param array<class-string, array<string, mixed>> $scopedVars  The properties to set on the instance,
     *                                                               keyed by their declaring class
     *
     * @return T
     *
     * @throws ExceptionInterface When the instance cannot be created
     */
    public static function instantiate(string $class, array $mangledVars = [], array $scopedVars = []): object
    {
        try {
            return deepclone_hydrate($class, $scopedVars, $mangledVars);
        } catch (\DeepClone\ClassNotFoundException $e) {
            throw new ClassNotFoundException($e);
        } catch (\DeepClone\NotInstantiableException $e) {
            throw new NotInstantiableTypeException($e);
        }
    }
}
