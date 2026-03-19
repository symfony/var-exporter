<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Symfony\\Component\\VarExporter\\Tests\\FinalError',
    'objectMeta' => [
        [0, 1],
    ],
    'prepared' => 0,
    'properties' => [
        'Error' => [
            'file' => [\dirname(__DIR__).\DIRECTORY_SEPARATOR.'VarExporterTest.php'],
            'line' => [123],
            'trace' => [
                [],
            ],
        ],
    ],
    'states' => [1 => 0],
])->clone();
