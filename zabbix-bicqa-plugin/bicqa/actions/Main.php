<?php
declare(strict_types=1);

namespace Modules\Bicqa\Actions;

use CController;
use CControllerResponseData;
use Modules\Bicqa\Includes\BicqaConfig;

/**
 * 主页面：渲染三 Tab（对话 / 告警分析 / 配置）。
 *
 * 支持从 Problems 页跳转时通过 URL 参数预填告警文本：
 *   ?action=bicqa.main&tab=analyze&text=<urlencoded 告警文本>
 */
class Main extends CController
{
    public function init(): void
    {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool
    {
        return true;
    }

    protected function checkPermissions(): bool
    {
        return true;
    }

    protected function doAction(): void
    {
        $config = (new BicqaConfig())->get();

        $prefillText = trim((string) getRequest('text', ''));
        $tab = (string) getRequest('tab', 'chat');

        $apiKey = trim((string) $config['api_key']);
        $isMock = ($apiKey === '' || strtoupper($apiKey) === 'MOCK');

        $this->setResponse(new CControllerResponseData([
            'api_url' => (string) $config['api_url'],
            'api_key_masked' => BicqaConfig::maskKey($apiKey),
            'has_key' => ($apiKey !== '' && !$isMock),
            'is_mock' => $isMock,
            'prefill_text' => $prefillText,
            'tab' => in_array($tab, ['chat', 'analyze', 'config'], true) ? $tab : 'chat'
        ]));
    }
}
