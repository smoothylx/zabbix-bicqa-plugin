<?php
declare(strict_types=1);

namespace Modules\Bicqa\Includes;

use RuntimeException;
use Throwable;

/**
 * BIC-QA Open API 客户端（对接官方文档 v1.1，API 版本 v1）。
 *
 * 对接流程：
 *   1. POST /open-api/v1/session/create  创建会话，拿到 conversationId；
 *   2. POST /open-api/v1/chat            流式（SSE）问答，答案 = 累加 delta 事件的
 *                                        delta.content，直到 done 事件。
 *
 * 鉴权：所有请求带 Header `Authorization: Bearer <API_KEY>`。
 * API 地址（config.api_url）填写的是基地址，例如 https://api.bic-qa.com
 *（不含 /open-api/v1 前缀，前缀由本类拼装）。
 *
 * 未配置 API Key（或 Key 为 "MOCK"）时自动走 mock 模式，返回演示数据，
 * 方便在没拿到真实密钥前离线调试 UI 与完整链路。
 */
class BicqaApi
{
    private const API_PREFIX = '/open-api/v1';

    private string $baseUrl;
    private string $apiKey;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim(trim((string) ($config['api_url'] ?? '')), '/');
        $this->apiKey = trim((string) ($config['api_key'] ?? ''));
    }

    public function isMock(): bool
    {
        return $this->apiKey === '' || strtoupper($this->apiKey) === 'MOCK';
    }

    /**
     * 对话问答（多轮）。
     *
     * @param string $message        用户问题
     * @param string $conversationId 已有会话 ID；为空则新建会话
     *
     * @return array{answer: string, conversationId: string}
     */
    public function chat(string $message, string $conversationId = ''): array
    {
        if ($this->isMock()) {
            return [
                'answer' => $this->mockChat($message),
                'conversationId' => $conversationId
            ];
        }

        if ($conversationId === '') {
            $conversationId = $this->createSession('Zabbix 智能问答');

            return [
                'answer' => $this->streamChat($message, $conversationId),
                'conversationId' => $conversationId
            ];
        }

        try {
            $answer = $this->streamChat($message, $conversationId);
        }
        catch (Throwable $e) {
            // 会话可能已失效/被删除，重建一次再试
            $conversationId = $this->createSession('Zabbix 智能问答');
            $answer = $this->streamChat($message, $conversationId);
        }

        return [
            'answer' => $answer,
            'conversationId' => $conversationId
        ];
    }

    /**
     * 告警根因分析（每次独立新会话，避免上下文串扰）。
     *
     * @return array{phenomenon: string, root_causes: string, commands: string, advice: string}
     */
    public function analyze(string $alertText): array
    {
        if ($this->isMock()) {
            return $this->mockAnalyze($alertText);
        }

        $conversationId = $this->createSession('告警根因分析');
        $raw = $this->streamChat($this->buildAnalyzePrompt($alertText), $conversationId);

        return $this->parseAnalyze($raw);
    }

    /**
     * 创建会话，返回 conversationId。
     */
    public function createSession(string $title): string
    {
        $data = $this->httpJson('POST', '/session/create', ['title' => $title]);

        $conversationId = $data['data']['conversationId'] ?? '';
        if ($conversationId === '') {
            throw new RuntimeException('创建会话失败：未返回 conversationId。');
        }

        return (string) $conversationId;
    }

    /**
     * 告警分析的提问模板：把原始告警文本套上结构化提问。
     */
    private function buildAnalyzePrompt(string $alertText): string
    {
        return <<<PROMPT
你是一名资深数据库运维专家。请对下面这条 Zabbix 告警进行根因分析，严格按照以下四个段落输出，每段以【标题】单独一行开头，其余为正文：

【现象解读】
【根因排序】
【定位命令/SQL】
【处置建议】

其中：
- 现象解读：说明这条告警到底在表达什么；
- 根因排序：按可能性从高到低列出候选根因；
- 定位命令/SQL：给出可直接复制执行的排查命令；
- 处置建议：短期如何止血，长期如何根治。

告警内容：
$alertText
PROMPT;
    }

    /**
     * 流式聊天：发起 SSE 请求，累加 delta.content 返回完整答案。
     */
    private function streamChat(string $question, string $conversationId): string
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Accept-Language: zh-CN',
            'Cache-Control: no-cache'
        ];

        $payload = [
            'question' => $question,
            'conversationId' => $conversationId
        ];

        $ch = curl_init($this->baseUrl . self::API_PREFIX . '/chat');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('请求 BIC-QA 失败：' . $err);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException($this->extractErrorMessage($response, $httpCode));
        }

        // 建流失败时可能返回 JSON 错误信封而非 SSE
        if (stripos($contentType, 'application/json') !== false) {
            throw new RuntimeException($this->extractErrorMessage($response, $httpCode));
        }

        return $this->parseSse((string) $response);
    }

    /**
     * 解析 SSE 事件流，累加 delta.content。
     */
    private function parseSse(string $body): string
    {
        $answer = '';
        $blocks = preg_split('/\r?\n\r?\n/', $body);

        if ($blocks === false) {
            return '';
        }

        foreach ($blocks as $block) {
            $dataLines = [];

            foreach (preg_split('/\r?\n/', $block) as $line) {
                if (strncmp($line, 'data:', 5) === 0) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }

            if (!$dataLines) {
                continue;
            }

            $event = json_decode(implode("\n", $dataLines), true);
            if (!is_array($event)) {
                continue;
            }

            $type = (string) ($event['type'] ?? '');

            if ($type === 'delta' && isset($event['delta']['content']) && is_string($event['delta']['content'])) {
                $answer .= $event['delta']['content'];
            }
            elseif ($type === 'error') {
                $code = (string) ($event['errorCode'] ?? 'STREAM_ERROR');
                $content = (string) ($event['content'] ?? '');
                throw new RuntimeException('BIC-QA 流式返回错误：' . ($content !== '' ? $content : $code));
            }
            // meta / file / done 忽略
        }

        return $answer;
    }

    /**
     * 通用 JSON 请求（非 SSE），返回解码后的响应体。
     */
    private function httpJson(string $method, string $path, array $payload = []): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Language: zh-CN'
        ];

        $ch = curl_init($this->baseUrl . self::API_PREFIX . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        else {
            $opts[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('请求 BIC-QA 失败：' . $err);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('BIC-QA 返回非 JSON 响应：' . mb_substr((string) $response, 0, 300));
        }

        if ($httpCode >= 400 || ($decoded['status'] ?? '') === 'error') {
            throw new RuntimeException($this->extractErrorMessage((string) $response, $httpCode));
        }

        return $decoded;
    }

    /**
     * 从错误响应里提取可读信息（优先 message，其次 code）。
     */
    private function extractErrorMessage(string $response, int $httpCode): string
    {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $message = trim((string) ($decoded['message'] ?? ''));
            if ($message !== '') {
                return 'BIC-QA 错误：' . $message;
            }
            $code = trim((string) ($decoded['code'] ?? ''));
            if ($code !== '') {
                return 'BIC-QA 错误：' . $code;
            }
        }

        return 'BIC-QA 返回 HTTP ' . $httpCode;
    }

    /**
     * 把四段式文本解析为结构化数组。解析失败时退化为整段放入「现象解读」。
     */
    private function parseAnalyze(string $raw): array
    {
        $sections = [
            'phenomenon' => '',
            'root_causes' => '',
            'commands' => '',
            'advice' => ''
        ];

        $map = [
            '【现象解读】' => 'phenomenon',
            '【根因排序】' => 'root_causes',
            '【定位命令/SQL】' => 'commands',
            '【处置建议】' => 'advice'
        ];

        $pattern = '/(【现象解读】|【根因排序】|【定位命令\/SQL】|【处置建议】)/u';
        $parts = preg_split($pattern, $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $matched = false;
        $count = is_array($parts) ? count($parts) : 0;
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $marker = $parts[$i] ?? '';
            $content = trim((string) ($parts[$i + 1] ?? ''));
            if (isset($map[$marker])) {
                $sections[$map[$marker]] = $content;
                $matched = true;
            }
        }

        if (!$matched) {
            $sections['phenomenon'] = trim($raw);
        }

        return $sections;
    }

    private function mockChat(string $message): string
    {
        return "（mock 模式）你问的是：「{$message}」\n\n"
            . "当前未配置 BIC-QA 的 API Key，这里返回的是演示数据，用于验证插件 UI 与完整链路。\n"
            . "在配置页填入真实 API 地址（如 https://api.bic-qa.com）与 Key 后，此处将返回知识库的真实回答。";
    }

    private function mockAnalyze(string $alertText): array
    {
        return [
            'phenomenon' => '该告警表明 MySQL 实例内存占用触达阈值，疑似触发 OOM 风险，物理内存已无法承载当前 buffer pool 配置。',
            'root_causes' => "1. innodb_buffer_pool_size 配置过大（可能性最高）\n"
                . "2. 并发连接数过高，每连接独立内存叠加\n"
                . "3. 备份 / 大查询等其它进程挤占内存",
            'commands' => "SHOW VARIABLES LIKE 'innodb_buffer_pool_size';\n"
                . "SHOW GLOBAL STATUS LIKE 'Threads_connected';\n"
                . "free -m\n"
                . "ps aux --sort=-%mem | head -20",
            'advice' => "短期：调低 innodb_buffer_pool_size 或临时限流，避免 OOM 导致宕机。\n"
                . "长期：按物理内存 60%~70% 重新规划 buffer pool，并配置内存/OOM 监控告警。"
        ];
    }
}
