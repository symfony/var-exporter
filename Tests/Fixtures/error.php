<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Error',
    'objectMeta' => [
        [0, 1],
    ],
    'prepared' => 0,
    'properties' => [
        'Error' => [
            'file' => [\dirname(__DIR__).\DIRECTORY_SEPARATOR.'VarExporterTest.php'],
            'line' => [234],
            'trace' => [
                ['file' => \dirname(__DIR__).\DIRECTORY_SEPARATOR.'VarExporterTest.php', 'line' => 123],
            ],
        ],
    ],
    'states' => [1 => 0],
])->clone();
