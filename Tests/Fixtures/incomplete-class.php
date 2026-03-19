<?php

return \Symfony\Component\VarExporter\DeepCloner::fromArray([
    'classes' => 'O:20:"SomeNotExistingClass":0:{}',
    'objectMeta' => 1,
    'prepared' => 0,
])->clone();
