<?php
declare(strict_types=1);

namespace Modules\Bicqa\Includes;

/**
 * 运行时配置存取：API 地址 + API Key。
 *
 * 默认存储在模块 data/config.json。生产环境强烈建议把 STORAGE_PATH
 * 改到 web 根目录之外（例如 /var/lib/zabbix/bicqa/config.json），
 * 并确保 data 目录不会被浏览器直接访问（见 README 安全加固一节）。
 */
class BicqaConfig
{
    /**
     * 配置存储路径。可按部署环境修改为 web 根目录之外的绝对路径。
     */
    public const STORAGE_PATH = __DIR__ . '/../data/config.json';

    /**
     * 默认 API 地址：BIC-QA Open API 基地址（不含 /open-api/v1 前缀）。
     */
    public const DEFAULT_API_URL = 'https://api.bic-qa.com';

    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? self::STORAGE_PATH;
    }

    /**
     * 读取配置，缺失字段用默认值补齐。
     *
     * @return array{api_url: string, api_key: string, conversations: array<string,string>}
     */
    public function get(): array
    {
        $defaults = [
            'api_url' => self::DEFAULT_API_URL,
            'api_key' => '',
            'conversations' => []
        ];

        if (!is_file($this->file)) {
            return $defaults;
        }

        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            return $defaults;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $defaults;
        }

        return array_merge($defaults, $data);
    }

    /**
     * 保存配置。
     */
    public function save(string $apiUrl, string $apiKey): bool
    {
        $data = $this->get();
        $data['api_url'] = trim($apiUrl);
        $data['api_key'] = trim($apiKey);

        return $this->write($data);
    }

    /**
     * 读取某用户（按 userid）的持久会话 ID，用于多轮对话续接上下文。
     */
    public function getConversationId(string $userKey): string
    {
        $data = $this->get();

        return (string) ($data['conversations'][$userKey] ?? '');
    }

    /**
     * 保存某用户的持久会话 ID。
     */
    public function saveConversationId(string $userKey, string $conversationId): bool
    {
        $data = $this->get();
        $data['conversations'] = is_array($data['conversations'] ?? null) ? $data['conversations'] : [];
        $data['conversations'][$userKey] = $conversationId;

        return $this->write($data);
    }

    /**
     * 写回配置文件。
     */
    private function write(array $data): bool
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $written = @file_put_contents(
            $this->file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        return $written !== false;
    }

    /**
     * 打码显示 Key：只露出首 4 位 + 末 4 位，中间用 * 代替。
     */
    public static function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        if (mb_strlen($key) <= 8) {
            return str_repeat('*', mb_strlen($key));
        }

        return mb_substr($key, 0, 4) . str_repeat('*', 8) . mb_substr($key, -4);
    }
}
