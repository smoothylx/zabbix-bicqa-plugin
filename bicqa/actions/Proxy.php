<?php
declare(strict_types=1);

namespace Modules\Bicqa\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\Bicqa\Includes\BicqaApi;
use Modules\Bicqa\Includes\BicqaConfig;
use Throwable;

/**
 * API 代理：前端 JS 调用本 action，由服务端转发到 BIC-QA。
 *
 * 目的：
 *   1. API Key 始终留在服务端，不暴露给浏览器；
 *   2. 规避浏览器跨域（CORS）限制。
 *
 * 入参（POST 表单编码）：
 *   mode    = chat | analyze
 *   message = 问题文本或告警文本
 *
 * 响应说明：同 ConfigSave，必须用 'main_block' 包一层（layout.json.php 直接 echo）。
 */
class Proxy extends CController
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
        try {
            $mode = (string) getRequest('mode', 'chat');
            $message = trim((string) getRequest('message', ''));

            if ($message === '') {
                $this->jsonResponse([
                    'ok' => false,
                    'error' => '消息内容不能为空。'
                ]);

                return;
            }

            $config = new BicqaConfig();
            $api = new BicqaApi($config->get());

            if ($mode === 'analyze') {
                // 告警分析：每次独立新会话，避免上下文串扰。
                $this->jsonResponse([
                    'ok' => true,
                    'mode' => 'analyze',
                    'sections' => $api->analyze($message)
                ]);

                return;
            }

            // 对话：按用户持久化会话 ID，实现多轮上下文续接。
            $userKey = $this->getUserKey();
            $conversationId = $config->getConversationId($userKey);
            $result = $api->chat($message, $conversationId);

            if ($result['conversationId'] !== $conversationId) {
                $config->saveConversationId($userKey, $result['conversationId']);
            }

            $this->jsonResponse([
                'ok' => true,
                'mode' => 'chat',
                'answer' => $result['answer']
            ]);
        }
        catch (Throwable $e) {
            $this->jsonResponse([
                'ok' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 当前登录用户的隔离键。
     */
    private function getUserKey(): string
    {
        $userid = (string) (CWebUser::$data['userid'] ?? '');

        return $userid !== '' ? $userid : 'guest';
    }

    /**
     * 把数据塞进 'main_block'，让 layout.json.php 直接 echo。
     */
    private function jsonResponse(array $payload): void
    {
        $this->setResponse(new CControllerResponseData([
            'main_block' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]));
    }
}
