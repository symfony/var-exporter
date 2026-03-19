<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'stdClass',
    'objectMeta' => 1,
    'prepared' => [-1, -1, 0],
    'mask' => [false, false, true],
    'refs' => [1 => 0],
    'refMasks' => [1 => true],
])->clone();
