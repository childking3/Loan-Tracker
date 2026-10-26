<?php

$common = require __DIR__ . '/common.php';

$config = [
    'id' => 'loantracker-console',
    'basePath' => dirname(__DIR__),
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@app' => dirname(__DIR__),
    ],
    'params' => require __DIR__ . '/params.php',
];

return \yii\helpers\ArrayHelper::merge($common, $config);
