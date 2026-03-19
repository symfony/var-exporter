<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => ['Symfony\\Component\\VarExporter\\Tests\\MyCloneable', 'Symfony\\Component\\VarExporter\\Tests\\MyNotCloneable'],
    'objectMeta' => [0, 1],
    'prepared' => [0, 1],
    'mask' => [true, true],
])->clone();
