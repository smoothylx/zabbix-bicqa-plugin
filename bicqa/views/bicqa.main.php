<?php
/**
 * @var CView $this
 * @var array $data
 *
 * 主页面视图：三 Tab（对话 / 告警分析 / 配置）。
 *
 * 关键点（Zabbix 7.0 官方规范）：
 *  - 模块 view 是「body 内容片段」，用 CHtmlPage 输出（官方教程同款写法）；
 *  - 页面标题由 CHtmlPage->setTitle 设置，不要在控制器里对 CControllerResponseData
 *    链式调用 setTitle()（该方法不返回 $this，会得到 null 导致 setResponse(null)）；
 *  - 模块 JS 通过 $this->includeJsFile() 加载 views/js/ 下的 .js.php（其中输出 <script>），
 *    不能用 addJsFile() 或 manifest assets.js（那对普通 action 页面不生效）。
 */

$prefill = htmlspecialchars((string) $data['prefill_text'], ENT_QUOTES, 'UTF-8');
$apiUrl = htmlspecialchars((string) $data['api_url'], ENT_QUOTES, 'UTF-8');
$apiKeyMasked = htmlspecialchars((string) $data['api_key_masked'], ENT_QUOTES, 'UTF-8');
$tab = htmlspecialchars((string) $data['tab'], ENT_QUOTES, 'UTF-8');
$hasKey = $data['has_key'] ? '1' : '0';
$isMock = $data['is_mock'] ? '1' : '0';

// 视图内联 CSS：不依赖 manifest assets.css（对部分 action 页面加载不稳定），
// 与 JS 内联同理，确保 .bicqa-* 样式一定能被渲染。
$cssPath = __DIR__ . '/../assets/css/bicqa.css';
$inlineCss = is_file($cssPath) ? (string) @file_get_contents($cssPath) : '';

$this->includeJsFile('bicqa.chat.js.php');
?>
<?php if ($inlineCss !== ''): ?>
<style><?= $inlineCss ?></style>
<?php endif; ?>
<?php
(new CHtmlPage())
    ->setTitle(_('BIC-QA 智能分析'))
    ->addItem(
        (new CDiv())
            ->setId('bicqa-app')
            ->setAttribute('data-tab', $tab)
            ->setAttribute('data-prefill-text', $prefill)
            ->setAttribute('data-api-url', $apiUrl)
            ->setAttribute('data-api-key-masked', $apiKeyMasked)
            ->setAttribute('data-has-key', $hasKey)
            ->setAttribute('data-is-mock', $isMock)
    )
    ->show();
