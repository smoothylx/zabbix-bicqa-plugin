<?php
declare(strict_types=1);

namespace Modules\Bicqa;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;
use CController as CAction;
use Throwable;

class Module extends CModule
{
    /**
     * 模块初始化：在 Monitoring 菜单下注册 "BIC-QA 智能分析" 入口。
     */
    public function init(): void
    {
        APP::Component()->get('menu.main')
            ->findOrAdd(_('Monitoring'))
            ->getSubmenu()
            ->add((new CMenuItem(_('BIC-QA 智能分析')))->setAction('bicqa.main'));
    }

    /**
     * 在每个 HTML 页面末尾注入「监控→问题」页的 🤖 AI 按钮脚本。
     *
     * 关键背景：Zabbix 模块 manifest 里的 assets.js 仅为 widget（部件）生效，
     * 普通页面不会加载它（这正是早前 bicqa.chat.js / bicqa.problems.js
     * 在浏览器 Network 里从未出现请求的原因）。所以要在任意页面（含 Problems 页）
     * 注入脚本，必须用模块的 onTerminate() 钩子，把 JS 内联进最终 HTML。
     *
     * bicqa.problems.js 内部会自检是否为「监控→问题」页（action=problem.view），
     * 非目标页自动 return，因此即使注入到所有页面也不会干扰别的页面。
     * JSON（AJAX 接口）与 widget 布局除外，避免污染接口响应。
     */
    public function onTerminate(CAction $action): void
    {
        $action_page = $action->getAction();

        // jsrpc.php（AJAX RPC）或未识别动作时不注入。
        if ($action_page === '' || $action_page === 'jsrpc.php') {
            return;
        }

        try {
            $layout = APP::Component()->get('router')->getLayout();
        }
        catch (Throwable $e) {
            // 取不到布局时默认按 HTML 页面处理（脚本内部会自检目标页）。
            $layout = 'layout.htmlpage';
        }

        // 只注入 HTML 页面骨架；JSON / widget 布局跳过，避免污染接口响应。
        if ($layout === 'layout.widget' || $layout === 'layout.json') {
            return;
        }

        $js = @file_get_contents(__DIR__.'/assets/js/bicqa.problems.js');
        if ($js === false) {
            return;
        }

        echo '<script>'."\n".$js."\n".'</script>';
    }
}
