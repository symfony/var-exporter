<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'Symfony\\Component\\VarExporter\\Tests\\TestClass',
    'objectMeta' => 1,
    'prepared' => [0, 'testMethod'],
    'mask' => 0,
])->clone();
