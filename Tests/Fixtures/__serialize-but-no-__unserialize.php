<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Symfony\\Component\\VarExporter\\Tests\\__SerializeButNo__Unserialize',
    'objectMeta' => 1,
    'prepared' => 0,
    'properties' => [
        'Symfony\\Component\\VarExporter\\Tests\\ParentOf__SerializeButNo__Unserialize' => [
            'foo' => ['foo'],
        ],
        'stdClass' => [
            'baz' => ['ccc'],
        ],
        'Symfony\\Component\\VarExporter\\Tests\\__SerializeButNo__Unserialize' => [
            'bar' => ['ddd'],
        ],
    ],
])->clone();
