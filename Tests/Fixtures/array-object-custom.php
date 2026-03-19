<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Symfony\\Component\\VarExporter\\Tests\\MyArrayObject',
    'objectMeta' => [
        [0, -1],
    ],
    'prepared' => 0,
    'states' => [
        1 => [
            0,
            [
                1,
                [234],
                ["\0".'Symfony\\Component\\VarExporter\\Tests\\MyArrayObject'."\0".'unused' => 123],
                null,
            ],
        ],
    ],
])->clone();
