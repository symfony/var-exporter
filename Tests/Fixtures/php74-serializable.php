<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => ['Symfony\\Component\\VarExporter\\Tests\\Fixtures\\Php74Serializable', 'stdClass'],
    'objectMeta' => [
        [0, -2],
        1,
    ],
    'prepared' => 0,
    'states' => [
        2 => [
            0,
            [1],
            [true],
        ],
    ],
])->clone();
