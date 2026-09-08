# BIC-QA 智能分析 - Zabbix 前端插件

把 BIC-QA 数据库知识库嵌入 Zabbix 告警现场：告警一出来，运维同学在面板上顺手就能拿到根因分析与处置命令，把「找知识」的 85% 时间省下来。

## 功能

- **三 Tab 页面**（Monitoring → BIC-QA 智能分析）：
  - 对话：像聊天一样问数据库运维问题（Oracle AWR、MySQL 慢查询、金仓 work_mem 等）；
  - 告警分析：粘贴 Zabbix 告警文本，返回四段式根因分析（现象解读 / 根因排序 / 定位命令 / 处置建议）；
  - 配置：填写 API 地址与 API Key，Key 打码显示、不留空裸奔。
- **Problems 页一键 AI 分析**：每个告警行注入 🤖 AI 按钮，点一下自动把告警文本预填并触发分析。

## 目录结构

```
bicqa/
├── manifest.json          # 模块元数据 + action/assets 注册（manifest_version 2.0）
├── Module.php             # 注册 Monitoring 子菜单
├── actions/
│   ├── Main.php           # 主页面（三 Tab）
│   ├── Proxy.php          # API 代理（Key 留在服务端，规避 CORS）
│   └── ConfigSave.php     # 保存配置
├── includes/
│   ├── BicqaApi.php       # BIC-QA Open API 客户端（session + SSE）+ mock + 四段式解析
│   └── BicqaConfig.php    # 配置读写 + 会话 ID 持久化 + Key 打码
├── views/
│   ├── bicqa.main.php         # 视图：CHtmlPage 输出三 Tab 容器，并 includeJsFile 加载 JS
│   └── js/
│       └── bicqa.chat.js.php  # 三 Tab 交互（PHP 输出 <script>，用 includeJsFile 加载）
├── assets/
│   ├── js/
│   │   └── bicqa.problems.js  # Problems 页 AI 按钮注入（由 Module::onTerminate() 内联注入）
│   └── css/bicqa.css          # 样式（明/暗主题适配）
└── data/
    ├── .htaccess          # 防止 config.json 被浏览器直读
    └── config.json        # 运行时配置（首次保存时自动生成）
```

## 模块 JS 加载机制（重要）

Zabbix 模块 manifest 里的 `assets.js` **仅为 widget（部件）生效，普通页面不会加载**它（这是之前主页面空白、JS「从未请求」的根因）。因此本模块的 JS 分两条路径注入：

| 要注入的位置 | 方式 | 实现 |
|---|---|---|
| 模块自己的 action 页（三 Tab 主页面） | 视图里 `$this->includeJsFile('bicqa.chat.js.php')`（加载 `views/js/*.js.php`） | `views/bicqa.main.php` |
| 任意其他页面（「监控→问题」页的 🤖 按钮） | `Module::onTerminate()` 钩子把 JS 内联到每个 HTML 页面末尾 | `Module.php` |

> `bicqa.problems.js` 内部会先自检 URL 是否为 `action=problem.view`，非目标页自动跳过，因此即使注入到所有页面也不会干扰其他页面；JSON / widget 布局已排除。

## 安装（Zabbix 7.0 LTS）

### 方式一：一键脚本（CentOS 8 + Nginx）

针对 RPM 安装 + Nginx 的环境，已附 `deploy-centos8-nginx.sh` 部署脚本：

1. 把 `bicqa/` 目录与脚本一起传到服务器并解压；
2. 以 root 执行：`bash deploy-centos8-nginx.sh`（自动定位前端目录、复制模块、检测 PHP-FPM 用户并授权 data 目录）；
3. 按脚本末尾提示，手动完成 Nginx deny 规则与后台启用两步。

### 方式二：手动部署（通用）

1. 把 `bicqa/` 整个目录复制到 Zabbix 前端模块目录：

   ```bash
   cp -r bicqa /usr/share/zabbix/modules/
   # 源码安装路径：<zabbix源码>/ui/modules/
   ```

2. 给 data 目录写权限（供 PHP-FPM 进程写 config.json）。PHP-FPM 用户因发行版而异：

   | 发行版 | PHP-FPM 用户 |
   |---|---|
   | Debian / Ubuntu | www-data |
   | CentOS / RHEL（RPM） | apache（或 nginx） |

   ```bash
   # 以 CentOS 8 为例（用户 apache）
   chown -R apache:apache /usr/share/zabbix/modules/bicqa/data
   chmod 775 /usr/share/zabbix/modules/bicqa/data
   ```

3. 登录 Zabbix → **Administration → General → Modules** → 点 **Scan directory** → 找到「BIC-QA 智能分析」→ 点 Disabled 切换为 **Enabled**。

4. 刷新页面，左侧 **Monitoring** 菜单下出现「BIC-QA 智能分析」。

## 接入 BIC-QA API（已完成对接）

本插件已按 BIC-QA Open API 文档（v1.1，API 版本 `v1`）对接完成，核心逻辑在 `includes/BicqaApi.php`。对接流程：

1. `POST /open-api/v1/session/create`（`{"title": "..."}`）→ 取 `data.conversationId`；
2. `POST /open-api/v1/chat`（`{"question": "...", "conversationId": "..."}`，`Accept: text/event-stream`）→ 流式（SSE）返回，累加各 `delta` 事件的 `delta.content` 得到完整答案。

鉴权：所有请求带 `Authorization: Bearer <API_KEY>`。

**配置页里「API 地址」填基地址即可**（不含 `/open-api/v1` 前缀），默认已填 `https://api.bic-qa.com`。填入 API Key 保存后即可使用。

对话模式按登录用户（userid）持久化 `conversationId`，实现多轮上下文续接；告警分析模式每次新建独立会话，避免上下文串扰。未配置 Key（或 Key 填 `MOCK`）时进入 **mock 模式**，返回演示数据方便离线调试。

> 注意：BIC-QA 响应为 SSE 长流，插件侧 cURL 超时设为 300 秒。若 Zabbix 前端的 PHP `max_execution_time` 过小，请适当调大。

## 安全加固（重要）

- **推荐把配置存到 web 根目录之外**：修改 `BicqaConfig::STORAGE_PATH` 为如 `/var/lib/zabbix/bicqa/config.json`，并给对应目录写权限。这样即便 `.htaccess` 失效，API Key 也不会被浏览器直读。
- 本插件已随包附带 `data/.htaccess`（Apache）。**若用 Nginx**，请在 server 配置里加（路径取决于你的 `root` 配置，官方 RPM 默认无 `/zabbix` 前缀）：

  ```nginx
  # root 指向 /usr/share/zabbix（RPM 默认，无 /zabbix 前缀）
  location ^~ /modules/bicqa/data/ { deny all; }
  # 若访问 URL 带 /zabbix 前缀，则写成 /zabbix/modules/bicqa/data/
  ```

- `ConfigSave.php` 与 `Proxy.php` 目前为简化前端调用关闭了 CSRF 校验。生产环境建议：保留 `ConfigSave` 的 CSRF 校验并在前端附带 Zabbix 的 CSRF token（删掉其 `init()` 里的 `disableCsrfValidation()` 即可）。

## 已知限制

- Zabbix 各小版本 Problems 页表格结构略有差异，若 🤖 按钮没出现在预期位置，按实际 DOM 调整 `assets/js/bicqa.problems.js` 中的选择器。
- 前端模块不能注册新 API 或建数据库表，因此配置以文件形式存储（见上）。
