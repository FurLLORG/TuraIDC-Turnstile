# TuraIDC-Turnstile

为TuraIDC用户中心提供Cloudflare Turnstile人机验证的插件，采用弹窗式交互，带加载动画与自动重试。

## 安装

```bash
cp -r plugins/captcha/turnstile <TuraIDC>/backend/plugins/captcha/turnstile
chmod -R 664 <TuraIDC>/backend/plugins/captcha/turnstile
```

登录TuraIDC管理后台，在「集成插件」中选择`captcha`域，安装并启用「Cloudflare Turnstile人机验证」。

把验证码驱动设为`turnstile`。

## 配置

| 配置项 | 说明 |
| --- | --- |
| `site_key` | Cloudflare Turnstile Site Key |
| `secret_key` | Cloudflare Turnstile Secret Key，密文存储 |

在Cloudflare Dashboard的Turnstile中创建站点获取密钥。

## 接口

| 动作 | 说明 |
| --- | --- |
| `captcha.config` | 返回Site Key |
| `captcha.verify` | 调用siteverify校验token |
| `captcha.script` | 返回前端适配脚本 |

## 说明

Turnstile的SDK与挑战iframe必须由浏览器直连`challenges.cloudflare.com`。网络受限时可能加载失败，插件会重试3次并提示。

## 兼容

TuraIDC后端（Laravel 12/PHP 8.4+），Turnstile v0 API。

## License

随TuraIDC项目授权条款使用。
