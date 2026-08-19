<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Captcha\Turnstile\Lib;

use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileCaptchaService
{
    private const SITEVERIFY_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/siteverify';

    private const SDK_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

    public function key(): string
    {
        return 'turnstile';
    }

    public function label(): string
    {
        return 'Cloudflare Turnstile 人机验证';
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        return match ($action) {
            'captcha.config' => $this->configResult($action, $config),
            'captcha.verify' => $this->verify($action, $payload, $config),
            'captcha.script' => $this->script($action),
            default => $this->failure($action, '不支持的人机验证动作'),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function configResult(string $action, array $config): array
    {
        return [
            'success' => true,
            'action' => $action,
            'message' => '',
            'data' => [
                'provider' => 'turnstile',
                'enabled' => $this->isConfigured($config),
                'captcha_id' => $this->siteKey($config),
                'site_key' => $this->siteKey($config),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function verify(string $action, array $payload, array $config): array
    {
        if (! $this->isConfigured($config)) {
            return $this->failure($action, 'Cloudflare Turnstile 插件配置不完整');
        }

        $token = $this->stringFrom($payload, ['token', 'cf-turnstile-response', 'turnstile_token']);
        if ($token === '') {
            return $this->failure($action, '行为验证参数不完整');
        }

        $ip = $this->stringFrom($payload, ['_client_ip', 'client_ip', 'ip']);
        $requestPayload = [
            'secret' => $this->secretKey($config),
            'response' => $token,
        ];
        if ($ip !== '') {
            $requestPayload['remoteip'] = $ip;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::SITEVERIFY_ENDPOINT, $requestPayload);

            if (! $response->successful()) {
                Log::warning('[captcha:turnstile] siteverify request failed', [
                    'status' => $response->status(),
                    'body' => SensitiveDataSanitizer::sanitizeText($response->body()),
                ]);

                return $this->failure($action, '行为验证服务暂时不可用，请稍后重试');
            }

            $data = $response->json();
            if (! is_array($data) || ! (bool) ($data['success'] ?? false)) {
                $errorCodes = is_array($data['error-codes'] ?? null) ? $data['error-codes'] : [];
                if ($errorCodes !== []) {
                    Log::warning('[captcha:turnstile] siteverify rejected token', [
                        'error_codes' => $errorCodes,
                    ]);
                }

                return $this->failure($action, '行为验证未通过，请重试', ['verified' => false]);
            }

            return [
                'success' => true,
                'action' => $action,
                'message' => '',
                'data' => ['verified' => true],
                'raw' => $data,
            ];
        } catch (\Throwable $exception) {
            Log::warning('[captcha:turnstile] siteverify exception', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $this->failure($action, '行为验证服务暂时不可用，请稍后重试');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function script(string $action): array
    {
        return [
            'success' => true,
            'action' => $action,
            'message' => '',
            'data' => ['content' => $this->adapterScript()],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function failure(string $action, string $message, array $data = []): array
    {
        return [
            'success' => false,
            'action' => $action,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function isConfigured(array $config): bool
    {
        return $this->siteKey($config) !== '' && $this->secretKey($config) !== '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function siteKey(array $config): string
    {
        return trim((string) ($config['site_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function secretKey(array $config): string
    {
        return trim((string) ($config['secret_key'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function stringFrom(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return trim((string) ($payload[$key] ?? ''));
            }
        }

        return '';
    }

    private function adapterScript(): string
    {
        return <<<'JS'
(function (global) {
    var SDK_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
    var scriptPromise = null;

    function loadSdk() {
        if (global.turnstile) {
            return Promise.resolve(global.turnstile);
        }

        if (scriptPromise) {
            return scriptPromise;
        }

        scriptPromise = loadSdkWithRetry(0);

        return scriptPromise;
    }

    function loadSdkWithRetry(attempt) {
        return loadSdkOnce().catch(function (error) {
            if (attempt < 2) {
                return new Promise(function (resolve) {
                    setTimeout(resolve, 1000);
                }).then(function () {
                    return loadSdkWithRetry(attempt + 1);
                });
            }

            throw error;
        });
    }

    function loadSdkOnce() {
        return new Promise(function (resolve, reject) {
            if (global.turnstile) {
                resolve(global.turnstile);
                return;
            }

            var script = document.createElement('script');
            script.src = SDK_URL;
            script.async = true;
            script.defer = true;
            script.dataset.turnstileSdk = 'api';
            var settled = false;
            var timer = setTimeout(function () {
                if (!settled) {
                    settled = true;
                    script.onload = null;
                    script.onerror = null;
                    reject(new Error('Turnstile 脚本加载超时'));
                }
            }, 15000);

            script.onload = function () {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                if (global.turnstile) {
                    resolve(global.turnstile);
                } else {
                    reject(new Error('Turnstile SDK 未初始化'));
                }
            };

            script.onerror = function () {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timer);
                reject(new Error('Turnstile 脚本加载失败'));
            };

            document.head.appendChild(script);
        });
    }

    function emit(callbacks, value) {
        callbacks.slice().forEach(function (callback) {
            try {
                callback(value);
            } catch (error) {
                setTimeout(function () { throw error; }, 0);
            }
        });
    }

    function normalizeLang(value) {
        var lang = String(value || '').toLowerCase();
        if (lang === 'zho' || lang === 'zh' || lang === 'zh-cn' || lang === 'zh-hans') {
            return 'zh-CN';
        }
        if (lang === 'eng' || lang === 'en' || lang === 'en-us') {
            return 'en';
        }

        return lang || 'auto';
    }

    function ensureDialog(closeHandler) {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.45);z-index:2147483000;display:none;';

        var dialogEl = document.createElement('div');
        dialogEl.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;padding:16px;box-shadow:0 8px 30px rgba(0,0,0,0.2);z-index:2147483001;';

        var box = document.createElement('div');
        box.style.cssText = 'display:flex;justify-content:center;align-items:center;min-height:65px;min-width:300px;';
        dialogEl.appendChild(box);
        overlay.appendChild(dialogEl);
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                hideDialog({ overlay: overlay });
                if (typeof closeHandler === 'function') {
                    closeHandler();
                }
            }
        });

        return { overlay: overlay, dialog: dialogEl, box: box };
    }

    function setStatus(dialog, text, isError, isLoading) {
        if (!dialog || !dialog.box) {
            return;
        }
        dialog.box.innerHTML = '';
        if (!text && !isLoading) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:10px;padding:12px 8px;';

        if (isLoading) {
            var spinner = document.createElement('div');
            spinner.style.cssText = 'width:28px;height:28px;border:3px solid #e5e7eb;border-top-color:#1a7ff2;border-radius:50%;animation:turnstile-spin 0.8s linear infinite;';
            wrap.appendChild(spinner);
            ensureSpinnerKeyframes();
        }

        var textEl = document.createElement('div');
        textEl.style.cssText = 'color:' + (isError ? '#dc2626' : '#666') + ';font-size:14px;text-align:center;';
        textEl.textContent = text || '';
        wrap.appendChild(textEl);

        dialog.box.appendChild(wrap);
    }

    function ensureSpinnerKeyframes() {
        if (document.getElementById('turnstile-spin-keyframes')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'turnstile-spin-keyframes';
        style.textContent = '@keyframes turnstile-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    }

    function showDialog(dialog) {
        if (dialog) {
            dialog.overlay.style.display = 'block';
        }
    }

    function hideDialog(dialog) {
        if (dialog) {
            dialog.overlay.style.display = 'none';
        }
    }

    global.initGeetest4 = function (options, callback) {
        options = options || {};

        var readyCallbacks = [];
        var successCallbacks = [];
        var errorCallbacks = [];
        var closeCallbacks = [];
        var widgetId = null;
        var lastResult = null;
        var dialog = null;

        function renderWidget() {
            return loadSdk().then(function (turnstile) {
                var siteKey = options.captchaId || options.captcha_id || options.siteKey || options.site_key;
                if (!siteKey) {
                    throw new Error('Turnstile Site Key 不能为空');
                }

                if (widgetId !== null) {
                    return turnstile;
                }

                if (!dialog) {
                    dialog = ensureDialog(function () { emit(closeCallbacks); });
                }

                dialog.box.innerHTML = '';
                widgetId = turnstile.render(dialog.box, {
                    sitekey: String(siteKey),
                    theme: options.theme || 'auto',
                    size: options.size || 'normal',
                    language: normalizeLang(options.language || options.lang),
                    execution: 'execute',
                    appearance: options.appearance || 'interaction-only',
                    callback: function (token) {
                        lastResult = { token: String(token), provider: 'turnstile' };
                        emit(successCallbacks);
                    },
                    'error-callback': function () {
                        emit(errorCallbacks, new Error('verification_failed'));
                    },
                    'expired-callback': function () {
                        lastResult = null;
                        emit(errorCallbacks, new Error('verification timeout'));
                    },
                    'timeout-callback': function () {
                        emit(errorCallbacks, new Error('verification timeout'));
                    }
                });

                return turnstile;
            });
        }

        var instance = {
            onReady: function (fn) {
                if (typeof fn === 'function') {
                    readyCallbacks.push(fn);
                    fn();
                }
                return instance;
            },
            onSuccess: function (fn) {
                if (typeof fn === 'function') {
                    successCallbacks.push(fn);
                }
                return instance;
            },
            onError: function (fn) {
                if (typeof fn === 'function') {
                    errorCallbacks.push(fn);
                }
                return instance;
            },
            onClose: function (fn) {
                if (typeof fn === 'function') {
                    closeCallbacks.push(fn);
                }
                return instance;
            },
            showCaptcha: function () {
                if (lastResult) {
                    emit(successCallbacks);
                    return instance;
                }

                if (!dialog) {
                    dialog = ensureDialog(function () { emit(closeCallbacks); });
                }
                setStatus(dialog, '正在加载验证组件...', false, true);
                showDialog(dialog);

                renderWidget()
                    .then(function (turnstile) {
                        if (typeof turnstile.execute === 'function') {
                            turnstile.execute(widgetId);
                        }
                    })
                    .catch(function (error) {
                        // 保留弹层并提示失败，避免加载失败时弹窗一闪而过；
                        // 重置加载 promise，允许用户关闭后再次点击重试。
                        scriptPromise = null;
                        setStatus(dialog, '验证组件加载失败，请关闭弹窗后重试', true);
                        emit(errorCallbacks, error instanceof Error ? error : new Error('行为验证初始化失败'));
                    });

                return instance;
            },
            getValidate: function () {
                return lastResult;
            },
            reset: function () {
                var wasSuccess = lastResult !== null;
                lastResult = null;

                if (wasSuccess) {
                    setTimeout(function () { hideDialog(dialog); }, 600);
                } else {
                    hideDialog(dialog);
                }

                if (widgetId !== null && global.turnstile && typeof global.turnstile.reset === 'function') {
                    global.turnstile.reset(widgetId);
                }
                return instance;
            },
            destroy: function () {
                if (widgetId !== null && global.turnstile && typeof global.turnstile.remove === 'function') {
                    global.turnstile.remove(widgetId);
                }
                widgetId = null;
                if (dialog && dialog.overlay && dialog.overlay.parentNode) {
                    dialog.overlay.parentNode.removeChild(dialog.overlay);
                }
                dialog = null;
                emit(closeCallbacks);
                return instance;
            }
        };

        if (typeof callback === 'function') {
            callback(instance);
        }

        return instance;
    };
})(window);
JS;
    }
}
