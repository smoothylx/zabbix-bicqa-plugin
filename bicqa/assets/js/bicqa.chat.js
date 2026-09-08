/*
 * BIC-QA 智能分析 - 三 Tab 页面交互
 * 通过 manifest.json 的 assets.js 全局加载，仅在存在 #bicqa-app 挂载点时运行。
 */
(function () {
    'use strict';

    var app = document.getElementById('bicqa-app');
    if (!app) {
        return;
    }

    function actionUrl(action) {
        return window.location.pathname + '?action=' + action;
    }

    function postForm(url, params) {
        var body = new URLSearchParams();
        for (var key in params) {
            if (Object.prototype.hasOwnProperty.call(params, key)) {
                body.append(key, params[key]);
            }
        }
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (res) {
            return res.json();
        });
    }

    function showToast(message) {
        var toast = document.createElement('div');
        toast.className = 'bicqa-toast';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function () {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 3000);
    }

    var state = {
        prefillText: app.getAttribute('data-prefill-text') || '',
        tab: app.getAttribute('data-tab') || 'chat',
        apiUrl: app.getAttribute('data-api-url') || '',
        apiKeyMasked: app.getAttribute('data-api-key-masked') || '',
        hasKey: app.getAttribute('data-has-key') === '1',
        isMock: app.getAttribute('data-is-mock') === '1'
    };

    /* ---- 构建 UI ---- */
    var keyPlaceholder = state.hasKey
        ? ('已保存（' + state.apiKeyMasked + '），留空表示不修改')
        : '输入 API Key（留空进入演示模式）';

    app.innerHTML = [
        '<div class="bicqa-tabs">',
        '  <button type="button" class="bicqa-tab" data-tab="chat">对话</button>',
        '  <button type="button" class="bicqa-tab" data-tab="analyze">告警分析</button>',
        '  <button type="button" class="bicqa-tab" data-tab="config">配置</button>',
        '</div>',
        '<div class="bicqa-panel" data-panel="chat">',
        '  <div class="bicqa-chat-log"></div>',
        '  <div class="bicqa-chat-input">',
        '    <textarea class="bicqa-input" placeholder="输入数据库运维问题，Ctrl+Enter 发送"></textarea>',
        '    <button type="button" class="bicqa-send">发送</button>',
        '  </div>',
        '</div>',
        '<div class="bicqa-panel" data-panel="analyze" style="display:none">',
        '  <div class="bicqa-analyze-hint">粘贴 Zabbix 告警文本，点击「分析根因」返回四段式分析（现象解读 / 根因排序 / 定位命令 / 处置建议）。</div>',
        '  <textarea class="bicqa-input bicqa-alert-text" placeholder="在此粘贴告警文本…"></textarea>',
        '  <button type="button" class="bicqa-analyze-btn">分析根因</button>',
        '  <div class="bicqa-result"></div>',
        '</div>',
        '<div class="bicqa-panel" data-panel="config" style="display:none">',
        '  <div class="bicqa-config-form">',
        '    <label>API 地址</label>',
        '    <input type="text" class="bicqa-input bicqa-api-url" />',
        '    <label>API Key</label>',
        '    <input type="password" class="bicqa-input bicqa-api-key" placeholder="' + keyPlaceholder + '" />',
        '    <div class="bicqa-mock-hint" style="display:' + (state.isMock ? 'block' : 'none') + '">当前未配置 Key，处于演示（mock）模式。填入真实 API 地址与 Key 后生效。</div>',
        '    <button type="button" class="bicqa-save">保存配置</button>',
        '  </div>',
        '</div>'
    ].join('');

    var apiUrlInput = app.querySelector('.bicqa-api-url');
    if (apiUrlInput) {
        apiUrlInput.value = state.apiUrl;
    }

    var alertTextArea = app.querySelector('.bicqa-alert-text');
    if (alertTextArea && state.prefillText) {
        alertTextArea.value = state.prefillText;
    }

    /* ---- Tab 切换 ---- */
    function switchTab(tab) {
        state.tab = tab;
        var tabs = app.querySelectorAll('.bicqa-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === tab);
        }
        var panels = app.querySelectorAll('.bicqa-panel');
        for (var j = 0; j < panels.length; j++) {
            panels[j].style.display = (panels[j].getAttribute('data-panel') === tab) ? '' : 'none';
        }
    }

    var tabButtons = app.querySelectorAll('.bicqa-tab');
    for (var t = 0; t < tabButtons.length; t++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                switchTab(btn.getAttribute('data-tab'));
            });
        })(tabButtons[t]);
    }
    switchTab(state.tab);

    /* ---- 聊天 ---- */
    var chatLog = app.querySelector('.bicqa-chat-log');
    var chatInput = app.querySelector('.bicqa-chat-input textarea');
    var sendBtn = app.querySelector('.bicqa-send');

    function addBubble(role, text) {
        var bubble = document.createElement('div');
        bubble.className = 'bicqa-bubble bicqa-' + role;
        bubble.textContent = text;
        chatLog.appendChild(bubble);
        chatLog.scrollTop = chatLog.scrollHeight;
    }

    function sendChat() {
        var message = chatInput.value.trim();
        if (!message) {
            return;
        }
        addBubble('user', message);
        chatInput.value = '';

        var pending = document.createElement('div');
        pending.className = 'bicqa-bubble bicqa-assistant bicqa-pending';
        pending.textContent = '思考中…';
        chatLog.appendChild(pending);
        chatLog.scrollTop = chatLog.scrollHeight;

        postForm(actionUrl('bicqa.proxy'), { mode: 'chat', message: message })
            .then(function (data) {
                if (pending.parentNode) {
                    pending.parentNode.removeChild(pending);
                }
                addBubble('assistant', data.ok ? data.answer : ('出错了：' + (data.error || '未知错误')));
            })
            .catch(function (err) {
                if (pending.parentNode) {
                    pending.parentNode.removeChild(pending);
                }
                addBubble('assistant', '请求失败：' + err.message);
            });
    }

    sendBtn.addEventListener('click', sendChat);
    chatInput.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            sendChat();
        }
    });

    /* ---- 告警分析 ---- */
    var analyzeBtn = app.querySelector('.bicqa-analyze-btn');
    var resultBox = app.querySelector('.bicqa-result');

    function renderSections(sections) {
        var blocks = [
            ['现象解读', sections.phenomenon],
            ['根因排序', sections.root_causes],
            ['定位命令/SQL', sections.commands],
            ['处置建议', sections.advice]
        ];
        resultBox.innerHTML = '';
        blocks.forEach(function (block) {
            if (!block[1]) {
                return;
            }
            var section = document.createElement('div');
            section.className = 'bicqa-section';

            var title = document.createElement('div');
            title.className = 'bicqa-section-title';
            title.textContent = block[0];

            var body = document.createElement('div');
            body.className = 'bicqa-section-body';
            body.textContent = block[1];

            section.appendChild(title);
            section.appendChild(body);
            resultBox.appendChild(section);
        });
    }

    function runAnalyze() {
        var text = alertTextArea.value.trim();
        if (!text) {
            showToast('请先粘贴告警文本');
            return;
        }
        analyzeBtn.disabled = true;
        analyzeBtn.textContent = '分析中…';

        postForm(actionUrl('bicqa.proxy'), { mode: 'analyze', message: text })
            .then(function (data) {
                analyzeBtn.disabled = false;
                analyzeBtn.textContent = '分析根因';
                if (data.ok) {
                    renderSections(data.sections);
                } else {
                    resultBox.innerHTML = '<div class="bicqa-error">' + (data.error || '未知错误') + '</div>';
                }
            })
            .catch(function (err) {
                analyzeBtn.disabled = false;
                analyzeBtn.textContent = '分析根因';
                resultBox.innerHTML = '<div class="bicqa-error">请求失败：' + err.message + '</div>';
            });
    }

    analyzeBtn.addEventListener('click', runAnalyze);

    /* 从 Problems 页点 AI 按钮跳转过来时，自动预填并触发分析 */
    if (state.tab === 'analyze' && state.prefillText) {
        runAnalyze();
    }

    /* ---- 配置保存 ---- */
    var saveBtn = app.querySelector('.bicqa-save');
    var apiKeyInput = app.querySelector('.bicqa-api-key');

    saveBtn.addEventListener('click', function () {
        var url = apiUrlInput.value.trim();
        var key = apiKeyInput.value.trim();
        if (!url) {
            showToast('API 地址不能为空');
            return;
        }
        postForm(actionUrl('bicqa.config.save'), { api_url: url, api_key: key })
            .then(function (data) {
                if (data.ok) {
                    state.apiUrl = url;
                    state.apiKeyMasked = data.api_key_masked;
                    state.hasKey = true;
                    state.isMock = false;
                    apiKeyInput.value = '';
                    apiKeyInput.placeholder = '已保存（' + data.api_key_masked + '），留空表示不修改';
                    showToast('配置保存成功');
                } else {
                    showToast('保存失败：' + (data.error || '未知错误'));
                }
            })
            .catch(function (err) {
                showToast('保存失败：' + err.message);
            });
    });
})();
