<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Symfony\\Component\\VarExporter\\Tests\\Fixtures\\FooReadonly',
    'objectMeta' => 1,
    'prepared' => 0,
    'properties' => [
        'Symfony\\Component\\VarExporter\\Tests\\Fixtures\\FooReadonly' => [
            'name' => ['k'],
            'value' => ['v'],
        ],
    ],
])->clone();
