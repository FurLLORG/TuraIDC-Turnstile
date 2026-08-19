<?php

declare(strict_types=1);

use TuraIDC\Plugins\Captcha\Turnstile\TurnstilePlugin;

return [
    'info' => [
        'domain' => 'captcha',
        'slug' => 'turnstile',
        'key' => 'turnstile',
        'name' => 'Cloudflare Turnstile 人机验证',
        'version' => '1.0.0',
        'entry' => TurnstilePlugin::class,
        'capabilities' => ['config', 'verify', 'script'],
        'extra' => [
            'driver_binding' => [
                'binding_key' => 'captcha_driver',
                'provider_key' => 'turnstile',
            ],
        ],
    ],
    'config' => [
        'basic_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '请在 Cloudflare 控制台创建 Turnstile 站点并填写 Site Key 与 Secret Key。Site Key 用于前端渲染组件，Secret Key 只保存在服务端，保存后不会明文回显。',
        ],
        'site_key' => [
            'title' => 'Site Key',
            'type' => 'text',
            'value' => '',
            'required' => true,
            'placeholder' => '请输入 Turnstile Site Key',
            'description' => '来自 Cloudflare 控制台的公开站点密钥，用于前端初始化组件。',
        ],
        'secret_key' => [
            'title' => 'Secret Key',
            'type' => 'password',
            'value' => '',
            'required' => true,
            'secret' => true,
            'placeholder' => '请输入 Turnstile Secret Key',
            'description' => '来自 Cloudflare 控制台的服务端密钥，仅用于后端二次校验。',
        ],
    ],
];
