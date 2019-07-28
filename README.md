# SlackTarget
Edit logs like this:

'log' => [
    'traceLevel' => YII_DEBUG ? 3 : 0,
    'targets' => [
        [
            'enabled' => true,
            'class' => 'app\components\SlackTarget',
            'channel' => '#channelname',
            'async' => true,
            'hook' => 'https://hooks.slack.com/services/HOOKPATH',
            'logVars' => [],
            'levels' => ['error', 'warning'],
            'except' => [
                'yii\web\HttpException:404',
                'yii\web\HttpException:400',
                'yii\web\BadRequestHttpException:400',
            ],
        ],
        [
            'class' => 'yii\log\FileTarget',
            'levels' => ['error', 'warning'],
        ],
    ],
],