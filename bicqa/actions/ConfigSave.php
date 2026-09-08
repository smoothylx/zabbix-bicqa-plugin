<?php
declare(strict_types=1);

namespace Modules\Bicqa\Actions;

use CController;
use CControllerResponseData;
use Modules\Bicqa\Includes\BicqaConfig;
use Throwable;

/**
 * 保存配置：API 地址 + API Key。
 *
 * 入参（POST 表单编码）：
 *   api_url = BIC-QA API 地址
 *   api_key = API Key（留空表示不修改已有 Key）
 *
 * 响应说明：本 action 的 manifest layout 是 "layout.json"，
 * 而 Zabbix 的 layout.json.php 直接 echo $data['main_block']，
 * 因此响应数据必须用 'main_block' 包一层 json_encode。
 * 把任意键（如 ok / error）放进 main_block 里，浏览器端 res.json() 才能解析。
 */
class ConfigSave extends CController
{
    public function init(): void
    {
        // POST 写操作，前端 fetch 不带 CSRF token 时会校验失败。
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool
    {
        return true;
    }

    protected function checkPermissions(): bool
    {
        // 仅允许管理员修改配置。
        return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
    }

    protected function doAction(): void
    {
        // 整个 doAction 包 try/catch：任何未预期异常都翻译成 JSON 错误。
        try {
            $apiUrl = trim((string) getRequest('api_url', ''));
            $apiKey = trim((string) getRequest('api_key', ''));

            if ($apiUrl === '' || filter_var($apiUrl, FILTER_VALIDATE_URL) === false) {
                $this->jsonResponse([
                    'ok' => false,
                    'error' => 'API 地址不能为空，且必须是合法的 URL。'
                ]);

                return;
            }

            $config = new BicqaConfig();

            // Key 留空时保留原值，避免保存其它字段时清空已配置的 Key。
            if ($apiKey === '') {
                $apiKey = $config->get()['api_key'];
            }

            if (!$config->save($apiUrl, $apiKey)) {
                $this->jsonResponse([
                    'ok' => false,
                    'error' => '保存失败：无法写入配置文件，请检查 ' . BicqaConfig::STORAGE_PATH
                              . ' 所在目录的写权限（chown apache:apache data/ && chmod 775 data/）。'
                ]);

                return;
            }

            $this->jsonResponse([
                'ok' => true,
                'has_key' => ($apiKey !== ''),
                'api_key_masked' => BicqaConfig::maskKey($apiKey)
            ]);
        }
        catch (Throwable $e) {
            $this->jsonResponse([
                'ok' => false,
                'error' => '保存失败（' . $e->getMessage() . '）'
            ]);
        }
    }

    /**
     * 把数据塞进 'main_block'，让 layout.json.php 直接 echo。
     * （参见 ZBase::processResponseFinal 的 else 分支 + layout.json.php）
     */
    private function jsonResponse(array $payload): void
    {
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]));
    }
}
