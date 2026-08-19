# TuraIDC Cloudflare Turnstile 人机验证插件
>AI提示：本项目使用了AI辅助阅读源码/AI自动补全

为 [TuraIDC](https://github.com/25Cloud/TuraIDC) 用户中心提供 Cloudflare Turnstile 人机验证能力的插件。采用**弹窗式**交互（对齐 GeeTest 的 bind 模式），点击触发后弹出居中验证窗口，用户可清晰看到验证组件与验证结果，不再出现"看不到验证、不知道成没成功"的体验问题。

## 程序源码仓库

- 插件运行的主程序源码：[https://github.com/25Cloud/TuraIDC](https://github.com/25Cloud/TuraIDC)
- 本插件仓库：[FurLLORG/TuraIDC-Turnstile](https://github.com/FurLLORG/TuraIDC-Turnstile)

## 功能特性

- 弹窗式人机验证：点击登录/发送验证码后弹出居中弹层，用户直观看到验证组件
- 加载动画：SDK 加载期间弹层内显示旋转加载动画与"正在加载验证组件..."提示，不再空白
- 自动重试：SDK 加载失败自动重试 3 次（含 15s 超时），应对网络抖动
- 结果可视化：验证通过后弹层短暂停留再自动关闭，用户明确感知验证成功
- 失败可感知：加载失败时保留弹层并红字提示"验证组件加载失败，请关闭弹窗后重试"，杜绝一闪而过
- 服务端校验：`siteverify` 校验 token，携带 `remoteip` 增强风控；密钥以密文存储，日志经脱敏处理
- 零侵入：全部逻辑收敛在插件目录内，不注册系统路由、调度或全局中间件

## 目录结构

```
TuraIDC-Turnstile/
└── plugins/captcha/turnstile/
    ├── config.php                  # 插件清单与配置项声明（domain=captcha, slug=turnstile）
    ├── TurnstilePlugin.php         # 插件入口类
    └── lib/
        └── TurnstileCaptchaService.php  # 验证服务：config / verify / script 三个动作
```

## 安装与启用

1. **拷贝插件**：将本仓库的 `plugins/captcha/turnstile` 目录拷贝到 TuraIDC 后端：

   ```bash
   cp -r plugins/captcha/turnstile <TuraIDC>/backend/plugins/captcha/turnstile
   ```

2. **权限**：确保文件对 PHP-FPM 运行用户可读（建议 `664`）：

   ```bash
   chmod -R 664 <TuraIDC>/backend/plugins/captcha/turnstile
   ```

3. **管理端安装**：登录 TuraIDC 管理后台 → 「集成插件」→ 域名选择 `captcha` → 扫描/刷新，看到「Cloudflare Turnstile 人机验证」后点击安装并启用。

4. **绑定驱动**：将人机验证驱动绑定为 `turnstile`（`captcha_driver` → `turnstile`）。

5. **填写密钥**：在 Cloudflare Dashboard → Turnstile 创建站点，获得 Site Key 与 Secret Key，填入插件配置：
   - `site_key`（必填）：Cloudflare Turnstile Site Key
   - `secret_key`（必填）：Cloudflare Turnstile Secret Key（密文存储）

6. **生效**：前端登录页、注册页、验证码发送等场景会自动带上人机验证（`/api/v2/client/auth/captcha-config` 返回 `enabled: true` 即生效）。

## 与 TuraIDC 的集成方式

插件遵循 TuraIDC 插件体系（`PluginRuntimeRegistry`），通过 `captcha` 域的三个动作工作：

| 动作 | 说明 |
|------|------|
| `captcha.config` | 返回 `captcha_id`（Site Key），驱动 `captcha-config` 接口 |
| `captcha.verify` | 调用 `https://challenges.cloudflare.com/turnstile/siteverify` 校验 token，返回 `verified` |
| `captcha.script` | 返回前端适配器脚本，暴露 `window.initGeetest4` 兼容接口（弹窗式） |

前端无需改动即可接入：现有 `useGeeTestCaptcha` 组合式函数按 GeeTest 协议加载脚本并弹出验证窗口。

## 兼容性

- TuraIDC 后端（Laravel 12 / PHP 8.4+）
- Cloudflare Turnstile（v0 API，`render=explicit` 显式渲染）

## 注意事项

- Turnstile 的 SDK 与挑战 iframe **必须由浏览器直连** `challenges.cloudflare.com`（SDK 自带脚本标签校验，无法代理或内联）。网络受限环境下可能加载失败——插件已实现自动重试并在失败时明确提示。
- 服务端 `siteverify` 同样调用 `challenges.cloudflare.com`，极端网络抖动时可能返回"行为验证服务暂时不可用"，用户重试即可。
- `captcha-script` 接口响应被浏览器缓存 12 小时（与 GeeTest 一致）；升级插件后建议强制刷新（Ctrl+Shift+R）验证新行为。

## License

随 TuraIDC 项目授权条款使用。
