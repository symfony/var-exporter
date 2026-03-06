<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\VarExporter\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\VarExporter\DeepCloner;
use Symfony\Component\VarExporter\Exception\LogicException;
use Symfony\Component\VarExporter\Tests\Fixtures\FooReadonly;
use Symfony\Component\VarExporter\Tests\Fixtures\FooUnitEnum;
use Symfony\Component\VarExporter\Tests\Fixtures\GoodNight;
use Symfony\Component\VarExporter\Tests\Fixtures\MyWakeup;

class DeepCloneTest extends TestCase
{
    public function testScalars()
    {
        $this->assertSame(42, DeepCloner::deepClone(42));
        $this->assertSame('hello', DeepCloner::deepClone('hello'));
        $this->assertSame(3.14, DeepCloner::deepClone(3.14));
        $this->assertTrue(DeepCloner::deepClone(true));
        $this->assertNull(DeepCloner::deepClone(null));
    }

    public function testSimpleArray()
    {
        $arr = ['a', 'b', [1, 2, 3]];
        $clone = DeepCloner::deepClone($arr);
        $this->assertSame($arr, $clone);
    }

    public function testUnitEnum()
    {
        $enum = FooUnitEnum::Bar;
        $this->assertSame($enum, DeepCloner::deepClone($enum));
    }

    public function testSimpleObject()
    {
        $obj = new \stdClass();
        $obj->foo = 'bar';
        $obj->baz = 123;

        $clone = DeepCloner::deepClone($obj);

        $this->assertNotSame($obj, $clone);
        $this->assertEquals($obj, $clone);
        $this->assertSame('bar', $clone->foo);
        $this->assertSame(123, $clone->baz);
    }

    public function testNestedObjects()
    {
        $inner = new \stdClass();
        $inner->value = 'inner';

        $outer = new \stdClass();
        $outer->child = $inner;
        $outer->name = 'outer';

        $clone = DeepCloner::deepClone($outer);

        $this->assertNotSame($outer, $clone);
        $this->assertNotSame($inner, $clone->child);
        $this->assertSame('inner', $clone->child->value);
        $this->assertSame('outer', $clone->name);

        // Mutating original doesn't affect clone
        $inner->value = 'changed';
        $this->assertSame('inner', $clone->child->value);
    }

    public function testCircularReference()
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->ref = $b;
        $b->ref = $a;

        $clone = DeepCloner::deepClone($a);

        $this->assertNotSame($a, $clone);
        $this->assertNotSame($b, $clone->ref);
        $this->assertSame($clone, $clone->ref->ref);
    }

    public function testArrayWithObjects()
    {
        $obj = new \stdClass();
        $obj->x = 42;

        $arr = ['key' => $obj, 'str' => 'hello'];
        $clone = DeepCloner::deepClone($arr);

        $this->assertNotSame($obj, $clone['key']);
        $this->assertSame(42, $clone['key']->x);
        $this->assertSame('hello', $clone['str']);

        // Mutating original doesn't affect clone
        $obj->x = 99;
        $this->assertSame(42, $clone['key']->x);
    }

    public function testSameObjectMultipleReferences()
    {
        $shared = new \stdClass();
        $shared->val = 'shared';

        $root = new \stdClass();
        $root->a = $shared;
        $root->b = $shared;

        $clone = DeepCloner::deepClone($root);

        $this->assertNotSame($shared, $clone->a);
        $this->assertSame($clone->a, $clone->b);
    }

    public function testSleepWakeup()
    {
        $obj = new MyWakeup();
        $obj->sub = 123;
        $obj->bis = 'ignored_by_sleep';
        $obj->baz = 'baz_value';
        $obj->def = 456;

        $clone = DeepCloner::deepClone($obj);

        $this->assertNotSame($obj, $clone);
        // __sleep returns ['sub', 'baz'], so 'bis' and 'def' should be reset
        $this->assertSame(123, $clone->sub);
        // __wakeup sets bis=123 and baz=123 when sub===123
        $this->assertSame(123, $clone->bis);
        $this->assertSame(123, $clone->baz);
        // def is not in __sleep, so it gets its default value 234
        $this->assertSame(234, $clone->def);
    }

    public function testSerializeUnserialize()
    {
        $obj = new class {
            public string $name = '';
            public array $data = [];

            public function __serialize(): array
            {
                return ['name' => $this->name, 'data' => $this->data];
            }

            public function __unserialize(array $data): void
            {
                $this->name = $data['name'];
                $this->data = $data['data'];
            }
        };

        $obj->name = 'test';
        $obj->data = ['a', 'b', 'c'];

        $clone = DeepCloner::deepClone($obj);

        $this->assertNotSame($obj, $clone);
        $this->assertSame('test', $clone->name);
        $this->assertSame(['a', 'b', 'c'], $clone->data);
    }

    public function testReadonlyProperties()
    {
        $obj = new FooReadonly('hello', 'world');

        $clone = DeepCloner::deepClone($obj);

        $this->assertNotSame($obj, $clone);
        $this->assertSame('hello', $clone->name);
        $this->assertSame('world', $clone->value);
    }

    public function testPrivateAndProtectedProperties()
    {
        $obj = new GoodNight();
        // __construct: unset($this->good), $this->foo='afternoon', $this->bar='morning'

        $clone = DeepCloner::deepClone($obj);

        $this->assertNotSame($obj, $clone);
        $this->assertEquals($obj, $clone);
    }

    public function testDateTime()
    {
        $dt = new \DateTime('2024-01-15 10:30:00', new \DateTimeZone('UTC'));

        $clone = DeepCloner::deepClone($dt);

        $this->assertNotSame($dt, $clone);
        $this->assertEquals($dt, $clone);

        // Mutating original doesn't affect clone
        $dt->modify('+1 day');
        $this->assertNotEquals($dt, $clone);
    }

    public function testSplObjectStorage()
    {
        $s = new \SplObjectStorage();
        $o1 = new \stdClass();
        $o1->id = 1;
        $o2 = new \stdClass();
        $o2->id = 2;
        $s[$o1] = 'info1';
        $s[$o2] = 'info2';

        $clone = DeepCloner::deepClone($s);

        $this->assertNotSame($s, $clone);
        $this->assertCount(2, $clone);
    }

    public function testDeepCloneMatchesSerializeUnserialize()
    {
        $inner = new \stdClass();
        $inner->value = str_repeat('x', 1000);

        $outer = new \stdClass();
        $outer->child = $inner;
        $outer->items = ['a', 'b', $inner];
        $outer->number = 42;

        $cloneA = unserialize(serialize($outer));
        $cloneB = DeepCloner::deepClone($outer);

        $this->assertEquals($cloneA, $cloneB);
    }

    public function testNamedClosure()
    {
        $fn = strlen(...);
        $clone = DeepCloner::deepClone($fn);

        $this->assertSame(5, $clone('hello'));
    }

    public function testRepeatedClones()
    {
        $obj = new \stdClass();
        $obj->value = 'original';

        $cloner = new DeepCloner($obj);

        $clone1 = $cloner->clone();
        $clone2 = $cloner->clone();

        $this->assertNotSame($obj, $clone1);
        $this->assertNotSame($obj, $clone2);
        $this->assertNotSame($clone1, $clone2);
        $this->assertEquals($obj, $clone1);
        $this->assertEquals($obj, $clone2);

        $clone1->value = 'changed';
        $this->assertSame('original', $clone2->value);
        $this->assertSame('original', $obj->value);
    }

    public function testRepeatedClonesWithNestedGraph()
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->ref = $b;
        $b->ref = $a;
        $a->data = str_repeat('x', 1000);

        $cloner = new DeepCloner($a);

        for ($i = 0; $i < 3; ++$i) {
            $clone = $cloner->clone();
            $this->assertNotSame($a, $clone);
            $this->assertSame($clone, $clone->ref->ref);
            $this->assertSame(str_repeat('x', 1000), $clone->data);
        }
    }

    public function testStaticValues()
    {
        $cloner = new DeepCloner(42);
        $this->assertSame(42, $cloner->clone());

        $cloner = new DeepCloner(['a', 'b']);
        $this->assertSame(['a', 'b'], $cloner->clone());
    }

    public function testCloneAs()
    {
        $obj = new FooReadonly('hello', 'world');

        $cloner = new DeepCloner($obj);
        $clone = $cloner->cloneAs(FooReadonly::class);

        $this->assertInstanceOf(FooReadonly::class, $clone);
        $this->assertNotSame($obj, $clone);
        $this->assertSame('hello', $clone->name);
        $this->assertSame('world', $clone->value);
    }

    public function testCloneAsRequiresObject()
    {
        $this->expectException(LogicException::class);
        $cloner = new DeepCloner([1, 2, new \stdClass()]);
        $cloner->cloneAs(\stdClass::class);
    }

    public function testOriginalMutationDoesNotAffectClone()
    {
        $obj = new \stdClass();
        $obj->foo = 'original';
        $obj->child = new \stdClass();
        $obj->child->bar = 'inner';

        $cloner = new DeepCloner($obj);

        // Mutate original after creating the cloner
        $obj->foo = 'mutated';
        $obj->child->bar = 'mutated-inner';

        $clone = $cloner->clone();
        $this->assertSame('original', $clone->foo);
        $this->assertSame('inner', $clone->child->bar);

        // Second clone should also be unaffected
        $clone2 = $cloner->clone();
        $this->assertSame('original', $clone2->foo);
        $this->assertSame('inner', $clone2->child->bar);
    }

    public function testSerializeClonerStaticValue()
    {
        $cloner = new DeepCloner(42);
        $restored = unserialize(serialize($cloner));

        $this->assertTrue($restored->isStaticValue());
        $this->assertSame(42, $restored->clone());
    }

    public function testSerializeClonerWithObjects()
    {
        $obj = new \stdClass();
        $obj->foo = 'bar';
        $obj->child = new \stdClass();
        $obj->child->val = 'inner';

        $cloner = new DeepCloner($obj);
        $data = serialize($cloner);

        // originals should not be in serialized form
        $this->assertStringNotContainsString('originals', $data);

        $restored = unserialize($data);
        $this->assertFalse($restored->isStaticValue());

        $clone = $restored->clone();
        $this->assertSame('bar', $clone->foo);
        $this->assertSame('inner', $clone->child->val);
        $this->assertNotSame($clone, $restored->clone());
    }

    public function testSerializeClonerStripsEmptyProperties()
    {
        $obj = new \stdClass();
        $obj->foo = 'bar';

        $cloner = new DeepCloner($obj);
        $data = serialize($cloner);

        // No refs, no states, no resolve for this simple case - should be absent
        $this->assertStringNotContainsString('states', $data);
        $this->assertStringNotContainsString('refs', $data);
    }

    public function testSerializeClonerWithCircularRef()
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->ref = $b;
        $b->ref = $a;

        $cloner = new DeepCloner($a);
        $restored = unserialize(serialize($cloner));

        $clone = $restored->clone();
        $this->assertSame($clone, $clone->ref->ref);
    }

    public function testSerializeClonerWithNullByteKey()
    {
        $obj = new \stdClass();
        $obj->data = ["\0" => 'nul-key', 'normal' => new \stdClass()];
        $obj->data['normal']->x = 42;

        $cloner = new DeepCloner($obj);
        $restored = unserialize(serialize($cloner));

        $clone = $restored->clone();
        $this->assertSame('nul-key', $clone->data["\0"]);
        $this->assertSame(42, $clone->data['normal']->x);
        $this->assertNotSame($obj->data['normal'], $clone->data['normal']);
    }

    public function testIsStaticValue()
    {
        $this->assertTrue((new DeepCloner(42))->isStaticValue());
        $this->assertTrue((new DeepCloner('hello'))->isStaticValue());
        $this->assertTrue((new DeepCloner(null))->isStaticValue());
        $this->assertTrue((new DeepCloner(true))->isStaticValue());
        $this->assertTrue((new DeepCloner([1, 'a', [2]]))->isStaticValue());
        $this->assertTrue((new DeepCloner([]))->isStaticValue());
        $this->assertTrue((new DeepCloner(FooUnitEnum::Bar))->isStaticValue());

        $this->assertFalse((new DeepCloner(new \stdClass()))->isStaticValue());
        $this->assertFalse((new DeepCloner(['key' => new \stdClass()]))->isStaticValue());
    }
}
