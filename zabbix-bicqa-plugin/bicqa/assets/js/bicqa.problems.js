/*
 * BIC-QA - Problems 页一键 AI 分析按钮注入
 *
 * 该脚本由 Module.php 的 onTerminate() 钩子内联注入到每个 HTML 页面。
 * 脚本内部先自检 URL 是否为「监控→问题」页（action=problem.view），
 * 非目标页自动 return，因此即使注入到所有页面也不会干扰其他页面。
 *
 * 按钮样式用内联样式（style.cssText）实现，不依赖任何外部 CSS 是否被加载，
 * 保证在 Zabbix 各版本、各主题下都能正常显示。
 */
(function () {
    'use strict';

    // 仅当 URL 指向「监控 → 问题」页时才注入。
    if (!/action=problem\.view/.test(window.location.href || '')) {
        return;
    }

    function buildMainUrl(text) {
        return window.location.pathname
            + '?action=bicqa.main&tab=analyze&text='
            + encodeURIComponent(text);
    }

    function isInjectTarget(tr) {
        if (tr.classList.contains('row-disabled')) {
            return false;       // 日期分隔行
        }
        if (tr.querySelector('th')) {
            return false;       // 表头
        }
        if (tr.querySelector('.bicqa-ai-btn')) {
            return false;       // 已注入，避免重复
        }
        // 只注入真正的「问题行」：必须包含问题名称节点或指向问题的链接。
        // Zabbix 的「今天 / 昨天 / 九月」等时间分组表头行（既非 row-disabled
        // 也无 th）没有这两个节点，会被这里拦住，避免按钮出现在分组行上。
        if (!tr.querySelector('.problem-name') && !tr.querySelector('a[href*="eventid"]')) {
            return false;
        }
        return true;
    }

    function extractProblemText(tr) {
        // 优先取问题名称节点文本。
        var nameEl = tr.querySelector('.problem-name');
        if (nameEl && nameEl.textContent.trim()) {
            return nameEl.textContent.trim();
        }

        // 退化为拼接整行可见文本（严重性 + 主机 + 问题名）。
        var cells = tr.querySelectorAll('td');
        var parts = [];
        for (var i = 0; i < cells.length; i++) {
            var text = cells[i].textContent.trim();
            if (text) {
                parts.push(text);
            }
        }
        return parts.join(' | ');
    }

    function styleButton(btn) {
        btn.style.cssText = 'display:inline-block;margin-left:6px;padding:2px 6px;'
            + 'border:1px solid #c6c6c6;border-radius:3px;background:#ededed;'
            + 'cursor:pointer;font-size:12px;line-height:1.4;color:#4b4b4b;vertical-align:middle;';
    }

    function injectButton(tr, text) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bicqa-ai-btn';
        btn.title = '用 BIC-QA 分析此告警';
        btn.textContent = '🤖 AI';
        styleButton(btn);
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            window.location.href = buildMainUrl(text);
        });

        // 优先插入问题名称单元格。
        var nameCell = tr.querySelector('.problem-name');
        if (nameCell) {
            nameCell.appendChild(btn);
            return;
        }

        // 其次：问题名称链接所在的单元格。
        var nameLink = tr.querySelector('a[href*="eventid"]');
        if (nameLink && nameLink.closest) {
            var td = nameLink.closest('td');
            if (td) {
                td.appendChild(btn);
                return;
            }
        }

        // 兜底：追加到行的最后一个单元格。
        var lastCell = tr.querySelector('td:last-of-type');
        if (lastCell) {
            lastCell.appendChild(btn);
        }
    }

    function injectButtons() {
        var rows = document.querySelectorAll('.list-table tbody tr, table tbody tr');
        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            if (!isInjectTarget(tr)) {
                continue;
            }
            var text = extractProblemText(tr);
            if (!text) {
                continue;
            }
            injectButton(tr, text);
        }
    }

    injectButtons();

    // Zabbix 用 AJAX 刷新表格，监听 DOM 变化持续注入。
    var observer = new MutationObserver(function () {
        injectButtons();
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
