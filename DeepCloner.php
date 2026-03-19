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

use Symfony\Component\VarExporter\Exception\LogicException;
use Symfony\Component\VarExporter\Exception\NotInstantiableTypeException;
use Symfony\Component\VarExporter\Internal\Exporter;
use Symfony\Component\VarExporter\Internal\Hydrator as InternalHydrator;
use Symfony\Component\VarExporter\Internal\Reference;
use Symfony\Component\VarExporter\Internal\Registry;

/**
 * Deep-clones PHP values while preserving copy-on-write benefits for strings and arrays.
 *
 * Unlike unserialize(serialize()), this approach does not reallocate strings and scalar-only
 * arrays, allowing PHP's copy-on-write mechanism to share memory for these values.
 *
 * DeepCloner instances are serializable: the serialized form is a pure array; it contains
 * only scalars and nested arrays, no objects. This makes it suitable for encoding with
 * json_encode(), var_export(), or any serializer that handles plain PHP arrays.
 * The format uses a compact representation that deduplicates class and property names,
 * typically producing a payload smaller than serialize($value) itself.
 *
 * @template T
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class DeepCloner
{
    private mixed $value;
    private mixed $prepared;
    private array $objectMeta;
    private array $properties;
    private array $resolve;
    private array $states;
    private array $refs;
    private array $originals;
    private array $refMasks;
    private mixed $preparedMask;

    /**
     * @param T $value
     */
    public function __construct(mixed $value)
    {
        if (!\is_object($value) && !(\is_array($value) && $value) || $value instanceof \UnitEnum) {
            $this->value = $value;

            return;
        }

        $objectsPool = [];
        $refsPool = [];
        $objectsCount = 0;
        $isStatic = true;
        $refs = [];
        $refMasks = [];
        $topMask = null;

        try {
            $prepared = Exporter::prepare([$value], $objectsPool, $refsPool, $objectsCount, $isStatic, $topMask)[0];
        } finally {
            // Process refs BEFORE cleanup: References are still in place, breaking cycles.
            // After cleanup restores originals, PHP &-references would recreate circular structures.
            // $v[1] = original value (for type detection), $v[2] = value after prepare's fallthrough.
            foreach ($refsPool as $i => $v) {
                if ($v[0]->count) {
                    if ($v[1] instanceof \UnitEnum) {
                        $refs[1 + $i] = $v[1]::class.'::'.$v[1]->name;
                        $refMasks[1 + $i] = 'e';
                    } elseif (\is_object($v[1])) {
                        $oid = spl_object_id($v[1]);
                        $refs[1 + $i] = isset($objectsPool[$oid]) ? $objectsPool[$oid][0] : $v[2];
                        $refMasks[1 + $i] = true;
                    } elseif (\is_array($v[2])) {
                        $m = null;
                        $refs[1 + $i] = self::replaceRefs($v[2], $m);
                        if (null !== $m) {
                            $refMasks[1 + $i] = $m;
                        }
                    } else {
                        $refs[1 + $i] = $v[2];
                    }
                }
                $v[0] = $v[1];
            }
        }

        if ($isStatic) {
            $this->value = $value;

            return;
        }

        $objectMeta = [];
        $properties = [];
        $resolve = [];
        $states = [];

        foreach ($objectsPool as [$id, $class, $props, $wakeup, $v, $propMask]) {
            $objectMeta[$id] = [$class, $wakeup];

            if (0 < $wakeup) {
                $states[$wakeup] = $id;
            } elseif (0 > $wakeup) {
                $states[-$wakeup] = null !== $propMask ? [$id, $props, $propMask] : [$id, $props];
                $props = [];
            }

            foreach ($props as $scope => $scopeProps) {
                foreach ($scopeProps as $name => $propValue) {
                    $properties[$scope][$name][$id] = $propValue;
                    if (isset($propMask[$scope][$name])) {
                        $resolve[$scope][$name][$id] = $propMask[$scope][$name];
                    }
                }
            }
        }

        ksort($states);

        // Convert any remaining hard-ref Reference objects to integers so that
        // the internal state is fully scalar (no Reference/NamedClosure objects).
        // This makes __serialize() trivial and reconstruct() simpler.
        $preparedMask = \is_int($prepared) ? null : ($topMask[0] ?? null);
        if (!\is_int($prepared)) {
            $m = null;
            $prepared = self::replaceRefs($prepared, $m);
            // $m has entries for count>0 hard refs; count=0 are unwrapped (absent from $m)
            // Remove stale false entries from preparedMask and merge
            if (\is_array($preparedMask)) {
                if (null !== $m) {
                    $preparedMask = $m + array_filter($preparedMask, static fn ($v) => false !== $v);
                } else {
                    $preparedMask = array_filter($preparedMask, static fn ($v) => false !== $v) ?: null;
                }
            } elseif (false === $preparedMask) {
                $preparedMask = $m;
            }
        }

        foreach ($resolve as $scope => $names) {
            foreach ($names as $name => $ids) {
                foreach ($ids as $id => $marker) {
                    if (false !== $marker) {
                        continue;
                    }
                    $v = $properties[$scope][$name][$id];
                    if (!$v instanceof Reference) {
                        $m = null;
                        $properties[$scope][$name][$id] = self::replaceRefs($v, $m);
                        if (null !== $m) {
                            $ids[$id] = $m;
                        } else {
                            unset($ids[$id]);
                        }
                    } elseif ($v->count) {
                        $properties[$scope][$name][$id] = $v->id;
                        $ids[$id] = true;
                    } else {
                        $m = null;
                        $properties[$scope][$name][$id] = self::replaceRefs($v->value, $m);
                        if (null !== $m) {
                            $ids[$id] = $m;
                        } else {
                            unset($ids[$id]);
                        }
                    }
                }
                $resolve[$scope][$name] = $ids;
            }
        }

        foreach ($states as $k => $v) {
            if (\is_array($v)) {
                $m = null;
                $states[$k][1] = self::replaceRefs($v[1], $m);
                if ($m) {
                    $states[$k][2] = isset($v[2]) ? $v[2] + $m : $m;
                }
            }
        }

        // After unwrapping unshared hard refs, the value may have become static
        if (!$objectMeta && !$refs && null === $preparedMask) {
            $this->value = $prepared;

            return;
        }

        $this->prepared = $prepared;
        $this->preparedMask = $preparedMask;
        $this->objectMeta = $objectMeta;
        $this->properties = $properties;
        $this->resolve = $resolve;
        $this->states = $states;
        $this->refs = $refs;
        $this->refMasks = $refMasks;
    }

    /**
     * Deep-clones a PHP value.
     *
     * @template U
     *
     * @param U $value
     *
     * @return U
     */
    public static function deepClone(mixed $value): mixed
    {
        return (new self($value))->clone();
    }

    /**
     * Returns true when the value doesn't need cloning (scalars, null, enums, scalar-only arrays).
     */
    public function isStaticValue(): bool
    {
        return !isset($this->prepared);
    }

    /**
     * Creates a deep clone of the value.
     *
     * @return T
     */
    public function clone(): mixed
    {
        if (!isset($this->prepared)) {
            return $this->value;
        }

        if (isset($this->originals)) {
            // Originals available, use them directly
            return self::reconstruct($this->prepared, $this->objectMeta, $this->properties, $this->resolve, $this->states, $this->refs, $this->originals, $this->preparedMask ?? null, $this->refMasks ?? []);
        }

        // First clone: full hydration, then cache objects as originals for future clone-and-patch
        $objects = null;
        $result = self::reconstruct($this->prepared, $this->objectMeta, $this->properties, $this->resolve, $this->states, $this->refs, [], $this->preparedMask ?? null, $this->refMasks ?? [], $objects);

        // Check if clone-and-patch is viable for future clones
        $canCache = true;
        foreach ($this->objectMeta as [$class, $wakeup]) {
            if (0 !== $wakeup || ':' === ($class[1] ?? null) || !Registry::$cloneable[$class]) {
                $canCache = false;
                break;
            }
        }
        if ($canCache) {
            foreach ($this->resolve as $scope => $names) {
                foreach ($names as $name => $ids) {
                    if (((InternalHydrator::$propertyScopes[$scope] ??= InternalHydrator::getPropertyScopes($scope))[$name][4] ?? null)?->isReadOnly()) {
                        $canCache = false;
                        break 2;
                    }
                }
            }
        }

        if ($canCache) {
            $originals = [];
            foreach ($objects as $id => $obj) {
                $originals[$id] = clone $obj;
            }
            $this->originals = $originals;
        } else {
            $this->originals = [];
        }

        return $result;
    }

    /**
     * Creates a deep clone of the root object using a different class.
     *
     * The target class must be compatible with the original (typically in the same hierarchy).
     *
     * @template U of object
     *
     * @param class-string<U> $class
     *
     * @return U
     */
    public function cloneAs(string $class): object
    {
        $prepared = $this->prepared ?? null;
        $rootId = \is_int($prepared) ? $prepared : null;

        if (null === $rootId) {
            throw new LogicException('DeepCloner::cloneAs() requires the value to be an object.');
        }

        $objectMeta = $this->objectMeta;
        $objectMeta[$rootId][0] = $class;

        return self::reconstruct($prepared, $objectMeta, $this->properties, $this->resolve, $this->states, $this->refs, [], $this->preparedMask ?? null, $this->refMasks ?? []);
    }

    /**
     * Exports the cloner state as a pure array (no objects, only scalars and arrays).
     *
     * The returned array can be passed to {@see fromArray()} to restore the cloner.
     */
    public function toArray(): array
    {
        return $this->__serialize();
    }

    /**
     * Restores a DeepCloner from an array previously created by {@see toArray()}.
     *
     * @template U
     *
     * @return self<U>
     */
    public static function fromArray(array $data): self
    {
        $cloner = new self(null);
        $cloner->__unserialize($data);

        return $cloner;
    }

    public function __serialize(): array
    {
        if (!isset($this->prepared)) {
            return ['value' => $this->value];
        }

        // Deduplicate class names in objectMeta
        $classes = [];
        $classMap = [];
        $objectMeta = [];
        foreach ($this->objectMeta as $id => [$class, $wakeup]) {
            if (!isset($classMap[$class])) {
                $classMap[$class] = \count($classes);
                $classes[] = $class;
            }
            $objectMeta[$id] = 0 !== $wakeup ? [$classMap[$class], $wakeup] : $classMap[$class];
        }

        // When all entries share class index 0 with wakeup 0, store just the count
        $n = \count($objectMeta);
        foreach ($objectMeta as $v) {
            if (0 !== $v) {
                $n = $objectMeta;
                break;
            }
        }

        // The internal state is already fully scalar (no Reference/NamedClosure objects),
        // so we just need to deduplicate class names and output directly.
        $data = [
            'classes' => 1 === \count($classes) ? $classes[0] : ($classes ?: ''),
            'objectMeta' => $n,
            'prepared' => $this->prepared,
        ];

        if (null !== $mask = $this->preparedMask ?? null) {
            $data['mask'] = $mask;
        }
        if ($this->properties) {
            $data['properties'] = $this->properties;
        }
        if ($this->resolve) {
            $data['resolve'] = $this->resolve;
        }
        if ($this->states) {
            $data['states'] = $this->states;
        }
        if ($this->refs) {
            $data['refs'] = $this->refs;
        }
        if ($refMasks = $this->refMasks ?? []) {
            $data['refMasks'] = $refMasks;
        }

        return $data;
    }

    public function __unserialize(array $data): void
    {
        if (\array_key_exists('value', $data)) {
            $this->value = $data['value'];

            return;
        }

        if (!\array_key_exists('classes', $data)) {
            throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) is missing required "classes" key.');
        }
        if (!\array_key_exists('objectMeta', $data)) {
            throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) is missing required "objectMeta" key.');
        }
        if (!\array_key_exists('prepared', $data)) {
            throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) is missing required "prepared" key.');
        }
        $classes = $data['classes'];
        if (!\is_string($classes) && !\is_array($classes)) {
            throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) "classes" must be of type string|array, '.get_debug_type($classes).' given.');
        }
        $meta = $data['objectMeta'];
        if (!\is_int($meta) && !\is_array($meta)) {
            throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) "objectMeta" must be of type int|array, '.get_debug_type($meta).' given.');
        }
        foreach (['mask' => false, 'properties' => 'array', 'resolve' => 'array', 'states' => 'array', 'refs' => 'array', 'refMasks' => 'array'] as $key => $expected) {
            if (\array_key_exists($key, $data) && false !== $expected && \gettype($data[$key]) !== $expected) {
                throw new \ValueError(\sprintf('DeepCloner::fromArray(): Argument #1 ($data) "%s" must be of type '.$expected.', '.get_debug_type($data[$key]).' given.', $key));
            }
        }

        // Rebuild class names from deduplicated list
        if (!\is_array($classes)) {
            $classes = '' !== $classes ? [$classes] : [];
        }
        $numClasses = \count($classes);
        if (\is_int($meta)) {
            if ($meta < 0) {
                throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) "objectMeta" count must be non-negative, '.$meta.' given.');
            }
            if ($meta > 0 && $numClasses < 1) {
                throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) "objectMeta" references class index 0 but "classes" is empty.');
            }
            $objectMeta = $meta ? array_fill(0, $meta, [$classes[0], 0]) : [];
        } else {
            $objectMeta = [];
            foreach ($meta as $id => $v) {
                if (\is_array($v)) {
                    if (!isset($v[0], $v[1]) || !\is_int($v[0]) || !\is_int($v[1])) {
                        throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) "objectMeta" entry '.$id.' must be [int, int].');
                    }
                    $cidx = $v[0];
                    $wakeup = $v[1];
                } elseif (\is_int($v)) {
                    $cidx = $v;
                    $wakeup = 0;
                } else {
                    throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) "objectMeta" entry '.$id.' must be of type int|array, '.get_debug_type($v).' given.');
                }
                if ($cidx < 0 || $cidx >= $numClasses) {
                    throw new \ValueError('DeepCloner::fromArray(): Argument #1 ($data) "objectMeta" entry '.$id.' has out-of-range class index '.$cidx.'.');
                }
                $objectMeta[$id] = [$classes[$cidx], $wakeup];
            }
        }

        $this->prepared = $data['prepared'];
        $this->preparedMask = $data['mask'] ?? null;
        $this->objectMeta = $objectMeta;
        $this->properties = $data['properties'] ?? [];
        $this->resolve = $data['resolve'] ?? [];
        $this->states = $data['states'] ?? [];
        $this->refs = $data['refs'] ?? [];
        $this->refMasks = $data['refMasks'] ?? [];
    }

    private static function reconstruct($prepared, $objectMeta, $properties, $resolve, $states, $refs, $originals = [], $preparedMask = null, $refMasks = [], ?array &$createdObjects = null)
    {
        // Create all object instances
        $objects = [];

        if ($originals) {
            // Clone-and-patch: clone originals (COW-shares all scalar properties)
            foreach ($originals as $id => $v) {
                $objects[$id] = clone $v;
            }
        } else {
            foreach ($objectMeta as $id => [$class]) {
                if (':' === ($class[1] ?? null)) {
                    $objects[$id] = unserialize($class);
                    continue;
                }
                Registry::$reflectors[$class] ??= Registry::getClassReflector($class);

                if (Registry::$cloneable[$class]) {
                    $objects[$id] = clone Registry::$prototypes[$class];
                } elseif (Registry::$instantiableWithoutConstructor[$class]) {
                    $objects[$id] = Registry::$reflectors[$class]->newInstanceWithoutConstructor();
                } elseif (null === Registry::$prototypes[$class]) {
                    throw new NotInstantiableTypeException($class);
                } elseif (Registry::$reflectors[$class]->implementsInterface('Serializable') && !method_exists($class, '__unserialize')) {
                    $objects[$id] = unserialize('C:'.\strlen($class).':"'.$class.'":0:{}');
                } else {
                    $objects[$id] = unserialize('O:'.\strlen($class).':"'.$class.'":0:{}');
                }
            }
        }

        // Resolve hard references (only those with masks need resolution)
        foreach ($refMasks as $k => $m) {
            $refs[$k] = self::resolveWithMask($refs[$k], $m, $objects, $refs);
        }

        if ($originals) {
            // Clone-and-patch: only resolve and hydrate object-reference properties
            foreach ($resolve as $scope => $names) {
                $scopeProps = [];
                foreach ($names as $name => $ids) {
                    foreach ($ids as $id => $marker) {
                        if (true === $marker) {
                            $v = $properties[$scope][$name][$id];
                            $scopeProps[$name][$id] = $v >= 0 ? $objects[$v] : $refs[-$v];
                        } elseif (0 === $marker) {
                            $scopeProps[$name][$id] = self::resolveNamedClosureScalar($properties[$scope][$name][$id], $objects, $refs);
                        } else {
                            $scopeProps[$name][$id] = self::resolveWithMask($properties[$scope][$name][$id], $marker, $objects, $refs);
                        }
                    }
                }
                (InternalHydrator::$hydrators[$scope] ??= InternalHydrator::getHydrator($scope))($scopeProps, $objects);
            }
        } else {
            // Full hydration: resolve and hydrate in a single per-scope pass
            foreach ($properties as $scope => $scopeProps) {
                if (isset($resolve[$scope])) {
                    foreach ($resolve[$scope] as $name => $ids) {
                        foreach ($ids as $id => $marker) {
                            if (true === $marker) {
                                $v = $scopeProps[$name][$id];
                                $scopeProps[$name][$id] = $v >= 0 ? $objects[$v] : $refs[-$v];
                            } elseif (0 === $marker) {
                                $scopeProps[$name][$id] = self::resolveNamedClosureScalar($scopeProps[$name][$id], $objects, $refs);
                            } else {
                                $scopeProps[$name][$id] = self::resolveWithMask($scopeProps[$name][$id], $marker, $objects, $refs);
                            }
                        }
                    }
                }
                (InternalHydrator::$hydrators[$scope] ??= InternalHydrator::getHydrator($scope))($scopeProps, $objects);
            }
        }

        foreach ($states as $v) {
            if (\is_array($v)) {
                $objects[$v[0]]->__unserialize(isset($v[2]) ? self::resolveWithMask($v[1], $v[2], $objects, $refs) : $v[1]);
            } else {
                $objects[$v]->__wakeup();
            }
        }

        $createdObjects = $objects;

        if (\is_int($prepared)) {
            return $prepared >= 0 ? $objects[$prepared] : $refs[-$prepared];
        }

        if (null !== $preparedMask) {
            return self::resolveWithMask($prepared, $preparedMask, $objects, $refs);
        }

        return $prepared;
    }

    private static function resolveWithMask($value, $mask, $objects, &$refs)
    {
        if (true === $mask) {
            return $objects[$value];
        }

        if (false === $mask) {
            return $refs[-$value];
        }

        if (0 === $mask) {
            return self::resolveNamedClosureScalar($value, $objects, $refs);
        }

        if ('e' === $mask) {
            return \constant($value);
        }

        if (!\is_array($mask)) {
            return $value;
        }

        foreach ($mask as $k => $m) {
            if (false === $m) {
                $value[$k] = &$refs[-$value[$k]];
            } else {
                $value[$k] = self::resolveWithMask($value[$k], $m, $objects, $refs);
            }
        }

        return $value;
    }

    private static function resolveNamedClosureScalar(array $value, $objects, $refs)
    {
        $method = null;

        if (\is_array($value[0])) {
            $callable = $value[0];
            $method = new \ReflectionMethod($value[1], $value[2]);
        } else {
            $callable = $value;
        }

        if (\is_int($obj = $callable[0])) {
            $obj = $obj >= 0 ? $objects[$obj] : $refs[-$obj];
        }
        $name = $callable[1];

        if (!($method?->isPublic() ?? true)) {
            return $method->getClosure(\is_object($obj) ? $obj : null);
        }

        if (!$obj) {
            return $name(...);
        }

        return \is_object($obj) ? $obj->$name(...) : $obj::$name(...);
    }

    private static function replaceRefs($value, &$mask)
    {
        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                if ($v instanceof Reference || \is_array($v)) {
                    $m = null;
                    $value[$k] = self::replaceRefs($v, $m);
                    if (null !== $m) {
                        $mask[$k] = $m;
                    }
                }
            }
        }

        if (!$value instanceof Reference) {
            return $value;
        }

        if ($value->id >= 0) {
            $mask = true;

            return $value->id;
        }
        if ($value->count) {
            $mask = false;

            return $value->id;
        }

        // Unshared hard ref (count=0): unwrap the inner value
        return self::replaceRefs($value->value, $mask);
    }
}
