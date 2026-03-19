<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Symfony\\Component\\VarExporter\\Tests\\PrivateConstructor',
    'objectMeta' => 1,
    'prepared' => 0,
    'properties' => [
        'stdClass' => [
            'prop' => ['bar'],
        ],
    ],
])->clone();
