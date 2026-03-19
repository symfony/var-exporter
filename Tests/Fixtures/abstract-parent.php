<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Symfony\\Component\\VarExporter\\Tests\\ConcreteClass',
    'objectMeta' => 1,
    'prepared' => 0,
    'properties' => [
        'Symfony\\Component\\VarExporter\\Tests\\AbstractClass' => [
            'foo' => [123],
            'bar' => [234],
        ],
    ],
])->clone();
