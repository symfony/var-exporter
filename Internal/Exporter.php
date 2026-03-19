<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter\Internal;

use Symfony\Component\VarExporter\Exception\NotInstantiableTypeException;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
class Exporter
{
    private static array $scopeMaps = [];
    private static array $protos = [];
    private static array $classInfo = [];
    private static $sentinel;

    /**
     * Prepares an array of values for VarExporter.
     *
     * For performance this method is public and has no type-hints.
     *
     * @param array $values
     * @param array &$objectsPool
     * @param array &$refsPool
     * @param int   &$objectsCount
     * @param bool  &$valuesAreStatic
     * @param array &$mask
     *
     * @return array
     *
     * @throws NotInstantiableTypeException When a value cannot be serialized
     */
    public static function prepare($values, &$objectsPool, &$refsPool, &$objectsCount, &$valuesAreStatic, &$mask = null)
    {
        $sentinel = self::$sentinel ??= new \stdClass();
        $refs = $values;
        foreach ($values as $k => $value) {
            if (\is_resource($value)) {
                throw new NotInstantiableTypeException(get_resource_type($value).' resource');
            }
            $refs[$k] = $sentinel;

            if ($isRef = !$valueIsStatic = $values[$k] !== $sentinel) {
                $values[$k] = &$value; // Break hard references to make $values completely
                unset($value);         // independent from the original structure
                $refs[$k] = $value = $values[$k];
                if ($value instanceof Reference && 0 > $value->id) {
                    $valuesAreStatic = false;
                    ++$value->count;
                    $mask[$k] = false;
                    continue;
                }
                $refsPool[] = [&$refs[$k], $value, &$value];
                $refs[$k] = $values[$k] = new Reference(-\count($refsPool), $value);
            }

            if (\is_array($value)) {
                if ($value) {
                    $m = null;
                    $value = self::prepare($value, $objectsPool, $refsPool, $objectsCount, $valueIsStatic, $m);
                    if (null !== $m) {
                        $mask[$k] = $m;
                    }
                }
                goto handle_value;
            } elseif (!\is_object($value) || $value instanceof \UnitEnum) {
                goto handle_value;
            }

            $valueIsStatic = false;
            $oid = spl_object_id($value);
            if (isset($objectsPool[$oid])) {
                ++$objectsCount;
                $value = $objectsPool[$oid][0];
                $mask[$k] = true;
                goto handle_value;
            }

            if ($value instanceof \Closure && !($r = new \ReflectionFunction($value))->isAnonymous()) {
                $callable = [$r->getClosureThis() ?? $r->getClosureCalledClass()?->name, $r->name];
                $rm = $callable[0] ? new \ReflectionMethod(...$callable) : null;
                $callable = self::prepare($callable, $objectsPool, $refsPool, $objectsCount, $valueIsStatic);
                $value = !($rm?->isPublic() ?? true) ? [$callable, $rm->class, $rm->name] : $callable;
                $mask[$k] = 0;

                goto handle_value;
            }

            $class = $value::class;

            if ('stdClass' === $class) {
                Registry::$reflectors[$class] ??= Registry::getClassReflector($class);
                $arrayValue = (array) $value;
                $objectsPool[$oid] = [$id = \count($objectsPool)];
                $m = null;
                $properties = $arrayValue ? self::prepare(['stdClass' => $arrayValue], $objectsPool, $refsPool, $objectsCount, $valueIsStatic, $m) : [];
                ++$objectsCount;
                $objectsPool[$oid] = [$id, 'stdClass', $properties, 0, $value, $m];
                $value = $id;
                $mask[$k] = true;
                goto handle_value;
            }

            $reflector = Registry::$reflectors[$class] ??= Registry::getClassReflector($class);
            $properties = [];
            $sleep = null;
            $proto = Registry::$prototypes[$class];

            if (self::$classInfo[$class][2] ??= $reflector->hasMethod('__serialize') ? ($reflector->getMethod('__serialize')->isPublic() ?: $reflector->getMethod('__serialize')) : false) {
                if (self::$classInfo[$class][2] instanceof \ReflectionMethod) {
                    throw new \Error('Call to '.(self::$classInfo[$class][2]->isProtected() ? 'protected' : 'private').' method "'.$class.'::__serialize()".');
                }

                if (!\is_array($arrayValue = $value->__serialize())) {
                    throw new \TypeError($class.'::__serialize() must return an array');
                }

                if ($hasUnserialize = self::$classInfo[$class][0] ??= $reflector->hasMethod('__unserialize')) {
                    $properties = $arrayValue;
                    goto prepare_value;
                }
            } elseif (($value instanceof \ArrayIterator || $value instanceof \ArrayObject) && null !== $proto) {
                // ArrayIterator and ArrayObject need special care because their "flags"
                // option changes the behavior of the (array) casting operator.
                [$arrayValue, $properties] = self::getArrayObjectProperties($value, $proto);

                // populates Registry::$prototypes[$class] with a new instance
                Registry::getClassReflector($class, Registry::$instantiableWithoutConstructor[$class], Registry::$cloneable[$class]);
            } elseif ($value instanceof \SplObjectStorage && Registry::$cloneable[$class] && null !== $proto) {
                // By implementing Serializable, SplObjectStorage breaks
                // internal references; let's deal with it on our own.
                foreach (clone $value as $v) {
                    $properties[] = $v;
                    $properties[] = $value[$v];
                }
                $properties = ['SplObjectStorage' => ["\0" => $properties]];
                $arrayValue = (array) $value;
            } elseif ($value instanceof \Serializable || $value instanceof \__PHP_Incomplete_Class) {
                ++$objectsCount;
                $objectsPool[$oid] = [$id = \count($objectsPool), serialize($value), [], 0, $value, null];
                $value = $id;
                $mask[$k] = true;
                goto handle_value;
            } else {
                if (self::$classInfo[$class][3] ??= $reflector->hasMethod('__sleep')) {
                    if (!\is_array($sleep = $value->__sleep())) {
                        trigger_error('serialize(): __sleep should return an array only containing the names of instance-variables to serialize', \E_USER_NOTICE);
                        $value = null;
                        goto handle_value;
                    }
                    $sleep = array_flip($sleep);
                }

                $arrayValue = (array) $value;
            }

            $proto = self::$protos[$class] ??= (array) $proto;

            if (null === $scopeMap = self::$scopeMaps[$class] ?? null) {
                $scopeMap = [];
                $parent = $reflector;
                do {
                    foreach ($parent->getProperties() as $p) {
                        if (!$p->isStatic() && !isset($scopeMap[$p->name])) {
                            $scopeMap[$p->name] = !$p->isPublic() || $p->isProtectedSet() || $p->isPrivateSet() ? $p->class : 'stdClass';
                        }
                    }
                } while ($parent = $parent->getParentClass());
                self::$scopeMaps[$class] = $scopeMap;
            }

            foreach ($arrayValue as $name => $v) {
                $i = 0;
                $n = (string) $name;
                if ('' === $n || "\0" !== $n[0]) {
                    $c = $scopeMap[$n] ?? 'stdClass';
                } elseif ('*' === $n[1]) {
                    $n = substr($n, 3);
                    $c = $scopeMap[$n] ?? $reflector->getProperty($n)->class;
                } else {
                    $i = strpos($n, "\0", 2);
                    $c = substr($n, 1, $i - 1);
                    $n = substr($n, 1 + $i);
                }
                if (null !== $sleep) {
                    if (!isset($sleep[$name]) && (!isset($sleep[$n]) || ($i && $c !== $class))) {
                        unset($arrayValue[$name]);
                        continue;
                    }
                    unset($sleep[$name], $sleep[$n]);
                }
                if ("\x00Error\x00trace" === $name || "\x00Exception\x00trace" === $name || !\array_key_exists($name, $proto) || $proto[$name] !== $v) {
                    $properties[$c][$n] = $v;
                }
            }
            if ($sleep) {
                foreach ($sleep as $n => $v) {
                    trigger_error(\sprintf('serialize(): "%s" returned as member variable from __sleep() but does not exist', $n), \E_USER_NOTICE);
                }
            }
            if ($hasUnserialize = self::$classInfo[$class][0] ??= $reflector->hasMethod('__unserialize')) {
                $properties = $arrayValue;
            }

            prepare_value:
            $objectsPool[$oid] = [$id = \count($objectsPool)];
            $m = null;
            $properties = self::prepare($properties, $objectsPool, $refsPool, $objectsCount, $valueIsStatic, $m);
            ++$objectsCount;
            $objectsPool[$oid] = [$id, $class, $properties, $hasUnserialize ? -$objectsCount : ((self::$classInfo[$class][1] ??= $reflector->hasMethod('__wakeup')) ? $objectsCount : 0), $value, $m];

            $value = $id;
            $mask[$k] = true;

            handle_value:
            if ($isRef) {
                $mask[$k] = false;
                unset($value); // Break the hard reference created above
            } elseif (!$valueIsStatic) {
                $values[$k] = $value;
            }
            $valuesAreStatic = $valueIsStatic && $valuesAreStatic;
        }

        return $values;
    }

    public static function export($value, $indent = '')
    {
        switch (true) {
            case \is_int($value) || \is_float($value): return var_export($value, true);
            case [] === $value: return '[]';
            case false === $value: return 'false';
            case true === $value: return 'true';
            case null === $value: return 'null';
            case '' === $value: return "''";
            case $value instanceof \UnitEnum: return '\\'.ltrim(var_export($value, true), '\\');
        }

        $subIndent = $indent.'    ';

        if (\is_string($value)) {
            $code = \sprintf("'%s'", addcslashes($value, "'\\"));

            $code = preg_replace_callback("/((?:[\\0\\r\\n]|\u{202A}|\u{202B}|\u{202D}|\u{202E}|\u{2066}|\u{2067}|\u{2068}|\u{202C}|\u{2069})++)(.)/", static function ($m) use ($subIndent) {
                $m[1] = \sprintf('\'."%s".\'', str_replace(
                    ["\0", "\r", "\n", "\u{202A}", "\u{202B}", "\u{202D}", "\u{202E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{202C}", "\u{2069}", '\n\\'],
                    ['\0', '\r', '\n', '\u{202A}', '\u{202B}', '\u{202D}', '\u{202E}', '\u{2066}', '\u{2067}', '\u{2068}', '\u{202C}', '\u{2069}', '\n"'."\n".$subIndent.'."\\'],
                    $m[1]
                ));

                if ("'" === $m[2]) {
                    return substr($m[1], 0, -2);
                }

                if (str_ends_with($m[1], 'n".\'')) {
                    return substr_replace($m[1], "\n".$subIndent.".'".$m[2], -2);
                }

                return $m[1].$m[2];
            }, $code, -1, $count);

            if ($count && str_starts_with($code, "''.")) {
                $code = substr($code, 3);
            }

            return $code;
        }

        if (!\is_array($value)) {
            throw new \UnexpectedValueException(\sprintf('Cannot export value of type "%s".', get_debug_type($value)));
        }
        $j = -1;
        $code = '';
        $isFlat = '' !== $indent;
        $size = 0;
        foreach ($value as $k => $v) {
            $code .= $subIndent;
            if (!\is_int($k) || 1 !== $k - $j) {
                $code .= self::export($k, $subIndent).' => ';
                ++$size;
            }
            if (\is_int($k) && $k > $j) {
                $j = $k;
            }
            if (\is_array($v)) {
                $isFlat = false;
            }
            $code .= self::export($v, $subIndent).",\n";
            ++$size;
        }

        if (!$isFlat) {
            return "[\n".$code.$indent.']';
        }

        // Single-line: content fits within the 20-items budget
        if ($size <= 20) {
            $j = -1;
            $code = '[';
            foreach ($value as $k => $v) {
                if ('[' !== $code) {
                    $code .= ', ';
                }
                if (!\is_int($k) || 1 !== $k - $j) {
                    $code .= self::export($k, $indent).' => ';
                }
                if (\is_int($k) && $k > $j) {
                    $j = $k;
                }
                $code .= self::export($v, $indent);
            }

            return $code.']';
        }

        // Multi-line wrapped: pack values onto each line; before appending the next
        // value, check that the line would still hold <= 20 items.
        $j = -1;
        $code = '';
        $line = '';
        $lineSize = 0;
        foreach ($value as $k => $v) {
            $part = '';
            $partSize = 1;
            if (!\is_int($k) || 1 !== $k - $j) {
                $part .= self::export($k, $subIndent).' => ';
                ++$partSize;
            }
            if (\is_int($k) && $k > $j) {
                $j = $k;
            }
            $part .= self::export($v, $subIndent).',';

            if ('' !== $line && $lineSize + $partSize > 20) {
                $code .= $subIndent.$line."\n";
                $line = $part;
                $lineSize = $partSize;
            } else {
                $line .= '' === $line ? $part : ' '.$part;
                $lineSize += $partSize;
            }
        }
        if ('' !== $line) {
            $code .= $subIndent.$line."\n";
        }

        return "[\n".$code.$indent.']';
    }

    /**
     * @param \ArrayIterator|\ArrayObject $value
     * @param \ArrayIterator|\ArrayObject $proto
     */
    private static function getArrayObjectProperties($value, $proto): array
    {
        $reflector = $value instanceof \ArrayIterator ? 'ArrayIterator' : 'ArrayObject';
        $reflector = Registry::$reflectors[$reflector] ??= Registry::getClassReflector($reflector);

        $properties = [
            $arrayValue = (array) $value,
            $reflector->getMethod('getFlags')->invoke($value),
            $value instanceof \ArrayObject ? $reflector->getMethod('getIteratorClass')->invoke($value) : 'ArrayIterator',
        ];

        $reflector = $reflector->getMethod('setFlags');
        $reflector->invoke($proto, \ArrayObject::STD_PROP_LIST);

        if ($properties[1] & \ArrayObject::STD_PROP_LIST) {
            $reflector->invoke($value, 0);
            $properties[0] = (array) $value;
        } else {
            $reflector->invoke($value, \ArrayObject::STD_PROP_LIST);
            $arrayValue = (array) $value;
        }
        $reflector->invoke($value, $properties[1]);

        if ([[], 0, 'ArrayIterator'] === $properties) {
            $properties = [];
        } else {
            if ('ArrayIterator' === $properties[2]) {
                unset($properties[2]);
            }
            $properties = [$reflector->class => ["\0" => $properties]];
        }

        return [$arrayValue, $properties];
    }
}
