# SlackTarget
Edit logs like this:

```php
'log' => [
    'traceLevel' => YII_DEBUG ? 3 : 0,
    'targets' => [
        [
            'enabled' => !YII_DEBUG,
            'class' => '\simonefalcini\SlackTarget\SlackTarget',
            'channel' => '#channelname',
            'username' => 'Message Sender Name',
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
```
