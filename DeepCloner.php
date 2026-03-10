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
use Symfony\Component\VarExporter\Internal\NamedClosure;
use Symfony\Component\VarExporter\Internal\Reference;
use Symfony\Component\VarExporter\Internal\Registry;

/**
 * Deep-clones PHP values while preserving copy-on-write benefits for strings and arrays.
 *
 * Unlike unserialize(serialize()), this approach does not reallocate strings and scalar-only
 * arrays, allowing PHP's copy-on-write mechanism to share memory for these values.
 *
 * DeepCloner instances are serializable: the serialized form uses a compact representation
 * that deduplicates class and property names, typically producing a payload smaller than
 * serialize($value) itself.
 *
 * @template T
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class DeepCloner
{
    private readonly mixed $value;
    private readonly mixed $prepared;
    private readonly array $objectMeta;
    private readonly array $properties;
    private readonly array $resolve;
    private readonly array $states;
    private readonly array $refs;
    private readonly array $originals;

    /**
     * @param T $value
     */
    public function __construct(mixed $value)
    {
        if (!\is_object($value) && !(\is_array($value) && $value) || $value instanceof \UnitEnum) {
            $this->value = $value;

            return;
        }

        $objectsPool = new \SplObjectStorage();
        $refsPool = [];
        $objectsCount = 0;
        $isStatic = true;
        $refs = [];

        try {
            $prepared = Exporter::prepare([$value], $objectsPool, $refsPool, $objectsCount, $isStatic)[0];
        } finally {
            foreach ($refsPool as $i => $v) {
                if ($v[0]->count) {
                    $refs[1 + $i] = $v[2];
                }
                $v[0] = $v[1];
            }
        }

        if ($isStatic) {
            $this->value = $value;

            return;
        }

        $canCloneAll = true;
        $originals = [];
        $objectMeta = [];
        $properties = [];
        $resolve = [];
        $states = [];

        foreach ($objectsPool as $v) {
            [$id, $class, $props, $wakeup] = $objectsPool[$v];

            if (':' !== ($class[1] ?? null)) {
                // Pre-warm Registry caches so reconstruct() only reads them
                Registry::$reflectors[$class] ??= Registry::getClassReflector($class);
            }

            $objectMeta[$id] = [$class, $wakeup];

            if (0 < $wakeup) {
                $states[$wakeup] = $id;
                $canCloneAll = false;
            } elseif (0 > $wakeup) {
                $states[-$wakeup] = [$id, $props];
                $props = [];
                $canCloneAll = false;
            }

            if ($canCloneAll && (':' === ($class[1] ?? null) || !Registry::$cloneable[$class])) {
                $canCloneAll = false;
            }

            if ($canCloneAll) {
                $originals[$id] = clone $v;
            }

            foreach ($props as $scope => $scopeProps) {
                foreach ($scopeProps as $name => $propValue) {
                    $properties[$scope][$name][$id] = $propValue;
                    if ($propValue instanceof Reference || $propValue instanceof NamedClosure || \is_array($propValue) && self::hasReference($propValue)) {
                        $resolve[$scope][$name][] = $id;

                        if ($canCloneAll && ((InternalHydrator::$propertyScopes[$scope] ??= InternalHydrator::getPropertyScopes($scope))[$name][4] ?? null)?->isReadOnly()) {
                            $canCloneAll = false;
                        }
                    }
                }
            }
        }

        ksort($states);

        $this->prepared = $prepared instanceof Reference && $prepared->id >= 0 && !$prepared->count ? $prepared->id : $prepared;
        $this->objectMeta = $objectMeta;
        $this->properties = $properties;
        $this->resolve = $resolve;
        $this->states = $states;
        $this->refs = $refs;
        $this->originals = $canCloneAll ? $originals : [];
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

        return self::reconstruct($this->prepared, $this->objectMeta, $this->properties, $this->resolve, $this->states, $this->refs, $this->originals ?? []);
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
        $rootId = \is_int($prepared) ? $prepared : ($prepared instanceof Reference && $prepared->id >= 0 ? $prepared->id : null);

        if (null === $rootId) {
            throw new LogicException('DeepCloner::cloneAs() requires the value to be an object.');
        }

        $objectMeta = $this->objectMeta;
        $objectMeta[$rootId][0] = $class;

        return self::reconstruct($prepared, $objectMeta, $this->properties, $this->resolve, $this->states, $this->refs);
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

        // Replace References in prepared with int ids, tracking positions via mask
        $mask = null;
        $prepared = self::replaceRefs($this->prepared, $mask);

        $data = [
            'classes' => 1 === \count($classes) ? $classes[0] : $classes,
            'objectMeta' => $n,
            'prepared' => $prepared,
        ];

        if ($mask) {
            $data['mask'] = $mask;
        }

        // Replace direct References in properties with their int id (using resolve map)
        $properties = $this->properties ?? [];
        foreach (($this->resolve ?? []) as $scope => $names) {
            foreach ($names as $name => $ids) {
                foreach ($ids as $id) {
                    if ($properties[$scope][$name][$id] instanceof Reference) {
                        $properties[$scope][$name][$id] = $properties[$scope][$name][$id]->id;
                    }
                }
            }
        }

        if ($properties) {
            $data['properties'] = $properties;
        }
        if ($this->resolve ?? []) {
            $data['resolve'] = $this->resolve;
        }
        if ($this->states ?? []) {
            $data['states'] = $this->states;
        }
        if ($this->refs ?? []) {
            $data['refs'] = $this->refs;
        }

        return $data;
    }

    public function __unserialize(array $data): void
    {
        if (\array_key_exists('value', $data)) {
            $this->value = $data['value'];

            return;
        }

        // Rebuild class names from deduplicated list
        $classes = $data['classes'];
        if (!\is_array($classes)) {
            $classes = [$classes];
        }
        $meta = $data['objectMeta'];
        if (\is_int($meta)) {
            $objectMeta = array_fill(0, $meta, [$classes[0], 0]);
        } else {
            $objectMeta = [];
            foreach ($meta as $id => $v) {
                $objectMeta[$id] = \is_array($v) ? [$classes[$v[0]], $v[1]] : [$classes[$v], 0];
            }
        }

        $prepared = $data['prepared'];
        if (isset($data['mask'])) {
            $prepared = self::restoreRefs($prepared, $data['mask']);
        }
        $this->prepared = $prepared;
        $this->objectMeta = $objectMeta;

        // Restore References in properties using the resolve map
        $properties = $data['properties'] ?? [];
        $resolve = $data['resolve'] ?? [];
        foreach ($resolve as $scope => $names) {
            foreach ($names as $name => $ids) {
                foreach ($ids as $id) {
                    if (\is_int($properties[$scope][$name][$id])) {
                        $properties[$scope][$name][$id] = new Reference($properties[$scope][$name][$id]);
                    }
                }
            }
        }

        $this->properties = $properties;
        $this->resolve = $resolve;
        $this->states = $data['states'] ?? [];
        $this->refs = $data['refs'] ?? [];
    }

    private static function reconstruct($prepared, $objectMeta, $properties, $resolve, $states, $refs, $originals = [])
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

        // Resolve hard references
        foreach ($refs as &$ref) {
            $ref = self::resolve($ref, $objects, $refs);
        }
        unset($ref);

        if ($originals) {
            // Clone-and-patch: only resolve and hydrate object-reference properties
            foreach ($resolve as $scope => $names) {
                $scopeProps = [];
                foreach ($names as $name => $ids) {
                    foreach ($ids as $id) {
                        $scopeProps[$name][$id] = self::resolve($properties[$scope][$name][$id], $objects, $refs);
                    }
                }
                (InternalHydrator::$hydrators[$scope] ??= InternalHydrator::getHydrator($scope))($scopeProps, $objects);
            }
        } else {
            // Full hydration: resolve object refs in-place, then hydrate all properties
            foreach ($resolve as $scope => $names) {
                foreach ($names as $name => $ids) {
                    foreach ($ids as $id) {
                        $properties[$scope][$name][$id] = self::resolve($properties[$scope][$name][$id], $objects, $refs);
                    }
                }
            }
            foreach ($properties as $scope => $scopeProps) {
                (InternalHydrator::$hydrators[$scope] ??= InternalHydrator::getHydrator($scope))($scopeProps, $objects);
            }
        }

        foreach ($states as $v) {
            if (\is_array($v)) {
                $objects[$v[0]]->__unserialize(self::resolve($v[1], $objects, $refs));
            } else {
                $objects[$v]->__wakeup();
            }
        }

        if (\is_int($prepared)) {
            return $objects[$prepared];
        }

        if ($prepared instanceof Reference) {
            return $prepared->id >= 0 ? $objects[$prepared->id] : ($prepared->count ? $refs[-$prepared->id] : self::resolve($prepared->value, $objects, $refs));
        }

        return self::resolve($prepared, $objects, $refs);
    }

    private static function hasReference($value)
    {
        foreach ($value as $v) {
            if ($v instanceof Reference || $v instanceof NamedClosure || \is_array($v) && self::hasReference($v)) {
                return true;
            }
        }

        return false;
    }

    private static function resolve($value, $objects, $refs)
    {
        if ($value instanceof Reference) {
            if ($value->id >= 0) {
                return $objects[$value->id];
            }
            if (!$value->count) {
                return self::resolve($value->value, $objects, $refs);
            }

            return $refs[-$value->id];
        }

        if ($value instanceof NamedClosure) {
            $callable = self::resolve($value->callable, $objects, $refs);
            if ($value->method?->isPublic() ?? true) {
                return $callable[0] ? $callable[0]->$callable[1](...) : $callable[1](...);
            }

            return $value->method->getClosure(\is_object($callable[0]) ? $callable[0] : null);
        }

        if (\is_array($value)) {
            foreach ($value as $k => $v) {
                if ($v instanceof Reference || $v instanceof NamedClosure || \is_array($v)) {
                    $value[$k] = self::resolve($v, $objects, $refs);
                }
            }
        }

        return $value;
    }

    private static function replaceRefs($value, &$mask)
    {
        if ($value instanceof Reference) {
            if ($value->id < 0) {
                return $value; // Hard ref - serialize natively
            }
            $mask = true;

            return $value->id;
        }

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

        return $value;
    }

    private static function restoreRefs($value, $mask)
    {
        if (true === $mask) {
            return new Reference($value);
        }

        if (\is_array($mask)) {
            foreach ($mask as $k => $m) {
                $value[$k] = self::restoreRefs($value[$k], $m);
            }
        }

        return $value;
    }
}
