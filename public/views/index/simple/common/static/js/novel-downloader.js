/**
 * 小说后台下载器（独立模块，全页面可用）
 *
 * 特性：
 *  - 每个浏览器生成稳定 client_id，下载/转存任务持久化到 localStorage 仅本机可见
 *  - 多本书并发，按 bookUrl 去重，进度由 SSE 实时驱动
 *  - 任务面板可关闭/重开，悬浮入口持久可见
 *  - 下载模式由小说服务后台配置自动决定（开启网盘同步→下载并转存生成网盘链接；
 *    未开启→仅下载），点击即入队，无需用户手选网盘账户
 *  - 每条任务都暴露「下载到浏览器」「打开网盘链接」「移除记录」三个动作
 */
(function () {
    'use strict';

    if (window.novelDownloader) return;

    // ---------- 客户端标识 ----------
    var CLIENT_ID_KEY = 'novelDownloader.clientId';
    function getClientId() {
        try {
            var cid = localStorage.getItem(CLIENT_ID_KEY);
            if (cid) return cid;
            cid = 'c_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem(CLIENT_ID_KEY, cid);
            return cid;
        } catch (e) {
            return 'c_anon';
        }
    }
    var clientId = getClientId();

    // ---------- 账户 ID → 网盘名 ----------
    // 后端透传的 accounts 字段形如 "uc_19193091"（driveType_userId），
    // 列表展示只显示网盘名，不暴露底层 ID；如需显示账户维度（如多账户选一），
    // 文案仅以"网盘名"为单位聚合。driveType → 中文名的映射需与 pan-backend Adapter 的
    // DRIVE_TYPE 常量保持一致（与目录名不完全一致，如目录 pan123 → DRIVE_TYPE=123pan）。
    var DRIVE_NAME_MAP = {
        quark: '夸克网盘', aliyun: '阿里云盘', baidu: '百度网盘', uc: 'UC网盘',
        xunlei: '迅雷网盘', '115': '115网盘', cloud115: '115网盘',
        '123pan': '123云盘', pan123: '123云盘',
        cloud189: '天翼云盘', cloud139: '移动云盘', guangya: '光鸭网盘'
    };
    function accountDisplayName(acc) {
        if (!acc) return '';
        var idx = String(acc).indexOf('_');
        if (idx <= 0) return String(acc);
        var drive = String(acc).substring(0, idx);
        return DRIVE_NAME_MAP[drive] || drive;
    }
    // 把账户 ID 列表去重映射为"网盘名"列表（同名账户折叠为一个）
    function accountNamesForList(accs) {
        if (!Array.isArray(accs) || !accs.length) return [];
        var seen = {}, out = [];
        for (var i = 0; i < accs.length; i++) {
            var n = accountDisplayName(accs[i]);
            if (n && !seen[n]) { seen[n] = true; out.push(n); }
        }
        return out;
    }

    // ---------- 任务存储 ----------
    var STORAGE_KEY = 'novelDownloader.tasks.' + clientId;
    var tasks = {}; // bookUrl -> task

    function persist() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(tasks)); } catch (e) {}
    }
    function load() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return {};
            var data = JSON.parse(raw);
            return (data && typeof data === 'object') ? data : {};
        } catch (e) {
            return {};
        }
    }
    tasks = load();

    // ---------- 工具 ----------
    function uid() { return Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
    function escapeHtml(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = String(s);
        return div.innerHTML;
    }
    function coverProxy(bookUrl) {
        return '/api/other/novel_cover?url=' + encodeURIComponent(bookUrl);
    }

    function ensureTask(bookUrl) {
        if (!tasks[bookUrl]) {
            tasks[bookUrl] = {
                id: uid(),
                bookUrl: bookUrl,
                bookName: '', cover: '',
                latestChapter: '',
                stage: 'queued', // queued / downloading / uploading / done / fail
                progress: 0, total: 0,
                message: '', accounts: [],
                shareUrl: '', shareUrlWithPasscode: '', passcode: '',
                filename: '',
                createdAt: Date.now(), updatedAt: Date.now(),
                owner: clientId,
            };
        }
        // 永远用默认封面兜底（搜索页有 cover 会覆盖）
        if (!tasks[bookUrl].cover) tasks[bookUrl].cover = coverProxy(bookUrl);
        return tasks[bookUrl];
    }
    function updateTask(bookUrl, patch) {
        var t = ensureTask(bookUrl);
        for (var k in patch) if (Object.prototype.hasOwnProperty.call(patch, k)) t[k] = patch[k];
        t.updatedAt = Date.now();
        // 记录每任务“最后一次收到 SSE 事件”的时间戳，用于前端超时判断
        t.lastEventAt = Date.now();
        persist();
        notifyChange();
    }

    // ---------- 前端超时检测 ----------
    // 后端网盘转存阶段可能在某个账户上传/创建分享时长时间挂起（极端情况可达数分钟，
    // 期间不会再推送任何 pan-upload-* 事件），此时 SSE 仍连接但用户看不到进度。
    // 此处做兜底：进入 uploading 阶段后超过 UPLOAD_TIMEOUT_MS 没收到任何事件，
    // 自动把任务标记为 upload-stuck-fail，让用户可移除记录或重新下载/转存。
    // 注意：必须保留已下载文件（filename）以便用户仍能下到本地，并允许重试转存。
    var UPLOAD_TIMEOUT_MS = 120 * 1000;
    function sweepUploadTimeout() {
        var now = Date.now();
        var changed = false;
        for (var url in tasks) {
            if (!Object.prototype.hasOwnProperty.call(tasks, url)) continue;
            var t = tasks[url];
            if (t.stage !== 'uploading') continue;
            var lastAt = t.lastEventAt || t.updatedAt || t.createdAt || now;
            if (now - lastAt >= UPLOAD_TIMEOUT_MS) {
                t.stage = 'fail';
                t.uploadStuck = true;
                t.message = '获取链接超时，请重试或移除后重新搜索下载';
                t.updatedAt = now;
                changed = true;
            }
        }
        if (changed) {
            persist();
            notifyChange();
        }
    }
    var uploadSweeperTimer = null;
    function startUploadSweeper() {
        if (uploadSweeperTimer) return;
        uploadSweeperTimer = setInterval(sweepUploadTimeout, 15 * 1000);
        uploadSweeperTimer.unref && uploadSweeperTimer.unref();
    }
    function removeTask(bookUrl) {
        delete tasks[bookUrl];
        persist();
        notifyChange();
    }

    // ---------- 监听器（订阅面板 UI 刷新） ----------
    var listeners = [];
    function onChange(fn) { listeners.push(fn); }
    function notifyChange() {
        for (var i = 0; i < listeners.length; i++) {
            try { listeners[i](getList()); } catch (e) {}
        }
    }

    function getList() {
        return Object.keys(tasks).map(function (k) { return tasks[k]; })
            .sort(function (a, b) { return (b.createdAt || 0) - (a.createdAt || 0); });
    }

    // ---------- SSE 订阅 ----------
    var sse = null;
    function connect() {
        if (sse) return;
        try {
            sse = new EventSource('/api/other/novel_progress?clientId=' + encodeURIComponent(clientId));
        } catch (e) { sse = null; return; }
        sse.onmessage = function (e) {
            var evt = null;
            try { evt = JSON.parse(e.data); } catch (err) { return; }
            handleProgress(evt);
        };
        // EventSource 自动重连，无需手动处理 onerror
    }
    function handleProgress(evt) {
        if (!evt || !evt.bookUrl) return;
        // 兜底隔离：带 clientId 的事件若非本机发起，直接忽略（不建任务不显示）
        if (evt.clientId && evt.clientId !== clientId) return;
        var t = ensureTask(evt.bookUrl);
        if (evt.bookName) t.bookName = t.bookName || evt.bookName;
        if (evt.author) t.author = evt.author;
        switch (evt.type) {
            case 'download-progress':
                t.stage = 'downloading';
                t.progress = evt.index || 0;
                t.total = evt.total || 0;
                t.message = '已下载 ' + t.progress + '/' + t.total + ' 章';
                break;
            case 'download-done':
                t.stage = t.accounts && t.accounts.length ? 'uploading' : 'done';
                if (evt.filename) t.filename = evt.filename;
                t.message = t.stage === 'done' ? '下载完成' : '正在准备获取链接…';
                break;
            case 'download-fail':
                t.stage = 'fail';
                t.message = evt.message || '下载失败';
                break;
            case 'pan-upload-start':
                t.stage = 'uploading';
                t.message = evt.message || '正在准备获取链接…';
                break;
            case 'pan-upload-progress':
                t.stage = 'uploading';
                t.message = evt.message || '正在获取分享链接…';
                break;
            case 'pan-upload-done':
                t.stage = 'done';
                t.shareUrl = evt.shareUrl || '';
                t.shareUrlWithPasscode = evt.shareUrlWithPasscode || '';
                t.passcode = evt.passcode || '';
                if (evt.filename) t.filename = evt.filename;
                t.message = evt.message || '资源链接已就绪';
                break;
            case 'pan-upload-fail':
                // 文件本体已下载完成（download-done 已到），仅网盘转存/分享失败：
                // 保持 done 让用户仍可从文件入口取书，文案提示转存失败原因
                t.stage = 'done';
                t.uploadFail = true;
                if (evt.filename) t.filename = evt.filename;
                t.message = '下载完成，获取链接失败：' + (evt.message || '请稍后重试');
                break;
        }
        t.updatedAt = Date.now();
        persist();
        notifyChange();
    }

    // ---------- 下载模式配置（后台下发：transfer=下载并转存设定网盘 / download=仅下载） ----------
    var dlCfgCache = null;
    var dlCfgPromise = null;
    function getDownloadConfig() {
        if (dlCfgCache) return Promise.resolve(dlCfgCache);
        if (dlCfgPromise) return dlCfgPromise;
        dlCfgPromise = axios.get('/api/other/novel_download_config', { timeout: 8000 }).then(function (res) {
            dlCfgCache = (res.data && res.data.data) || { mode: 'download', panName: '' };
            return dlCfgCache;
        }).catch(function () {
            dlCfgCache = { mode: 'download', panName: '' };
            return dlCfgCache;
        });
        return dlCfgPromise;
    }
    function getDownloadConfigSync() { return dlCfgCache; }

    /**
     * 发起小说下载/转存任务
     * @param {object} item 搜索结果项 {title, url, author, cover, latestChapter}
     * @param {object} options { accounts?: string[] 指定账户（不传则由后端按后台配置自动分流） }
     * @returns {Promise}
     */
    function startTask(item, options) {
        options = options || {};
        if (!item || !item.url) return Promise.reject(new Error('缺少书籍链接'));

        var task = ensureTask(item.url);
        task.bookName = task.bookName || item.title || '';
        if (item.author) task.author = task.author || item.author;
        if (item.cover) task.cover = item.cover;
        // 透传搜索结果的最新章节，重试时也能命中本地缓存
        if (item.latestChapter) task.latestChapter = task.latestChapter || item.latestChapter;
        task.stage = 'queued';
        task.message = '正在准备…';
        // 重新发起下载时清空上一次的产物，避免旧分享链接/文件名残留误导
        task.shareUrl = '';
        task.shareUrlWithPasscode = '';
        task.passcode = '';
        task.filename = '';
        // 重新发起下载时刷新创建时间：最新下载的任务排在列表最前
        task.createdAt = Date.now();
        task.updatedAt = Date.now();
        persist();
        notifyChange();

        // 转存选择器：用户没指定 accounts 且后端能提供账户时，弹出选择器
        var doStart = function (accounts) {
            task.accounts = accounts || [];
            task.stage = 'downloading';
            task.message = accounts && accounts.length ? '下载并准备获取链接…' : '下载中…';
            task.updatedAt = Date.now();
            persist();
            notifyChange();
            axios.post('/api/other/novel_transfer', null, {
                params: {
                    url: item.url,
                    accounts: accounts && accounts.length ? accounts.join(',') : '',
                    clientId: clientId,
                    // 三元组用于服务端本地转存缓存复用命中（秒回）；
                    // 重试时 item.latestChapter 已清空，回退到 task 上保存的原始章节
                    bookName: item.title || task.bookName || '',
                    author: item.author || task.author || '',
                    latestChapter: item.latestChapter || task.latestChapter || ''
                },
                timeout: 35000
            }).then(function (res) {
                var data = (res.data && res.data.data) || {};
                if (res.data.code === 200 && data.started) {
                    // 缓存秒回场景：任务在 PHP 20s 超时窗口内完成时，本响应自带完整结果，
                    // 且 SSE 完成事件与响应同时（或先）到达——SSE 已把阶段推到 done，
                    // 这里直接采信响应结果落账，绝不能把已完成任务回退成"下载中"，
                    // 否则之后再无事件 → 面板永远卡在下载中
                    var result = data.result || null;
                    if (result) {
                        var rs = Array.isArray(result.results) ? result.results : [];
                        var hit = null;
                        for (var i = 0; i < rs.length; i++) {
                            var r = rs[i];
                            if (r && !r.error && (r.shareUrl || r.shareUrlWithPasscode)) { hit = r; break; }
                        }
                        if (hit) {
                            task.stage = 'done';
                            task.shareUrl = hit.shareUrl || '';
                            task.shareUrlWithPasscode = hit.shareUrlWithPasscode || '';
                            task.passcode = hit.passcode || '';
                            task.message = result.cached ? '已复用历史分享链接' : '资源链接已就绪';
                        } else if (result.filename && !(task.accounts && task.accounts.length)) {
                            // 纯下载模式（无转存账户）：响应自带产物文件名即已完成
                            task.stage = 'done';
                            task.message = '下载完成';
                        } else if (task.stage !== 'done' && task.stage !== 'fail') {
                            // 有分享失败且 SSE 未推进时保持等待（上传失败事件会兜底标记）
                            task.stage = 'downloading';
                            task.message = '已加入下载队列，准备获取链接';
                        }
                        if (result.filename) task.filename = result.filename;
                    } else if (task.stage !== 'done' && task.stage !== 'fail' && task.stage !== 'uploading') {
                        // 长任务：PHP 20s 超时仅返回 started；SSE 可能已推进阶段
                        // （uploading/done），未推进时才回落到 downloading
                        task.stage = 'downloading';
                        task.message = data.duplicate ? '已在下载队列中' : (task.accounts.length ? '已加入下载队列，准备获取链接' : '已加入下载队列');
                    }
                    if (data.accounts && data.accounts.length) task.accounts = data.accounts;
                    persist();
                    notifyChange();
                    return;
                }
                task.stage = 'fail';
                task.message = (res.data && res.data.message) || '任务发起失败';
                persist();
                notifyChange();
            }).catch(function () {
                task.stage = 'fail';
                task.message = '网络错误，请稍后重试';
                persist();
                notifyChange();
            });
        };

        if (Array.isArray(options.accounts)) {
            return Promise.resolve().then(function () { doStart(options.accounts); });
        }
        // 未指定账户：后端按小说服务后台配置自动分流
        // （开启网盘同步→下载+上传生成网盘链接；未开启→仅下载）
        return Promise.resolve().then(function () { doStart([]); });
    }

    function downloadToBrowser(task) {
        if (!task || !task.filename) {
            window.alert('文件尚未就绪');
            return;
        }
        window.open('/api/other/novel_file?filename=' + encodeURIComponent(task.filename));
    }

    // 重新下载任务：复用原 accounts（若有）+ 复用原 bookName/cover/author，
    // 走 startTask 主流程：会清掉旧 shareUrl/filename/createdAt 重新发起。
    // 注：底层 startTask 内部用 ensureTask，不会丢失历史元数据。
    function retryTask(task) {
        if (!task || !task.bookUrl) return;
        startTask({
            url: task.bookUrl,
            title: task.bookName || '',
            author: task.author || '',
            cover: task.cover || '',
            latestChapter: task.latestChapter || ''
        }, { accounts: Array.isArray(task.accounts) ? task.accounts.slice() : [] });
    }

    // ---------- 网盘链接二维码弹窗 ----------
    // 打开网盘链接改为弹二维码弹窗（手机扫码 / 复制链接 / 浏览器打开）
    var uiQrDialog = null;
    var uiQrLinkEl = null;
    var uiQrPassEl = null;
    var uiQrPassRow = null;
    var uiQrCanvasBox = null;

    function ensureQrDialog() {
        if (uiQrDialog) return;
        // 样式：复用项目主题色变量（与简单模板的 qrcode-wrapper / url-box / dialog-actions 风格一致）
        if (!document.getElementById('novelDlQrStyles')) {
            var s = document.createElement('style');
            s.id = 'novelDlQrStyles';
            s.textContent = ''
                + '#novel-downloader-root .novel-dl-qr-mask{position:fixed;inset:0;z-index:1300;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:16px;}'
                + '#novel-downloader-root .novel-dl-qr-box{width:360px;max-width:100%;background:var(--theme-surface,#141E2E);color:var(--theme-text,#F2EEDC);border:1px solid var(--theme-border,#2A3A52);border-radius:14px;box-shadow:0 18px 40px rgba(0,0,0,.5);overflow:hidden;display:flex;flex-direction:column;max-height:90vh;}'
                + '#novel-downloader-root .novel-dl-qr-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--theme-border,#2A3A52);font-size:15px;font-weight:700;}'
                + '#novel-downloader-root .novel-dl-qr-close{background:transparent;border:none;color:var(--theme-text-secondary,#8B9BB4);font-size:20px;line-height:1;cursor:pointer;padding:4px 8px;}'
                + '#novel-downloader-root .novel-dl-qr-close:hover{color:var(--theme-text,#F2EEDC);}'
                + '#novel-downloader-root .novel-dl-qr-body{padding:16px;display:flex;flex-direction:column;gap:12px;overflow-y:auto;}'
                + '#novel-downloader-root .novel-dl-qr-tip{font-size:13px;color:var(--theme-text-secondary,#8B9BB4);text-align:center;}'
                + '#novel-downloader-root .novel-dl-qr-canvas-wrap{display:flex;justify-content:center;}'
                + '#novel-downloader-root .novel-dl-qr-canvas{width:180px;height:180px;border:1px solid var(--theme-border,#2A3A52);border-radius:8px;padding:8px;display:flex;align-items:center;justify-content:center;background:#fff;}'
                + '#novel-downloader-root .novel-dl-qr-canvas canvas{width:100%;height:100%;}'
                + '#novel-downloader-root .novel-dl-qr-fail{font-size:12px;color:#f56c6c;text-align:center;}'
                + '#novel-downloader-root .novel-dl-qr-url{background:var(--theme-background,#0E1620);border:1px solid var(--theme-border,#2A3A52);border-radius:8px;padding:10px 12px;font-size:12px;line-height:1.5;word-break:break-all;user-select:all;color:var(--theme-text,#F2EEDC);max-height:96px;overflow-y:auto;}'
                + '#novel-downloader-root .novel-dl-qr-passrow{display:flex;align-items:center;gap:8px;background:var(--theme-background,#0E1620);border:1px solid var(--theme-border,#2A3A52);border-radius:8px;padding:10px 12px;}'
                + '#novel-downloader-root .novel-dl-qr-passrow .lbl{font-size:12px;color:var(--theme-text-secondary,#8B9BB4);flex-shrink:0;}'
                + '#novel-downloader-root .novel-dl-qr-passrow .val{font-size:13px;font-weight:600;color:#e8a547;flex:1;min-width:0;word-break:break-all;user-select:all;}'
                + '#novel-downloader-root .novel-dl-qr-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;}'
                + '#novel-downloader-root .novel-dl-qr-btn{background:transparent;border:1px solid var(--theme-border,#2A3A52);color:var(--theme-text-secondary,#8B9BB4);border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;transition:all .2s ease;}'
                + '#novel-downloader-root .novel-dl-qr-btn:hover{color:#e8a547;border-color:#e8a547;}'
                + '#novel-downloader-root .novel-dl-qr-btn.primary{color:#1a1a1a;background:linear-gradient(135deg,#e8a547,#f6c66a);border-color:transparent;font-weight:600;}'
                + '@media (max-width:480px){#novel-downloader-root .novel-dl-qr-box{width:100%;}#novel-downloader-root .novel-dl-qr-canvas{width:160px;height:160px;}}';
            document.head.appendChild(s);
        }
        uiQrDialog = document.createElement('div');
        uiQrDialog.className = 'novel-dl-qr-mask';
        uiQrDialog.style.display = 'none';
        uiQrDialog.innerHTML = ''
            + '<div class="novel-dl-qr-box" role="dialog" aria-modal="true">'
            +   '<div class="novel-dl-qr-head"><span>网盘链接</span><button class="novel-dl-qr-close" type="button" aria-label="关闭">×</button></div>'
            +   '<div class="novel-dl-qr-body">'
            +     '<div class="novel-dl-qr-tip">使用对应网盘 APP 扫描，或点击下方按钮复制 / 在浏览器中打开</div>'
            +     '<div class="novel-dl-qr-canvas-wrap"><div class="novel-dl-qr-canvas"></div></div>'
            +     '<div class="novel-dl-qr-url"></div>'
            +     '<div class="novel-dl-qr-passrow" style="display:none;"><span class="lbl">提取码：</span><span class="val"></span></div>'
            +     '<div class="novel-dl-qr-actions">'
            +       '<button class="novel-dl-qr-btn primary" type="button" data-act="copyLink">复制链接</button>'
            +       '<button class="novel-dl-qr-btn" type="button" data-act="openLink">浏览器打开</button>'
            +       '<button class="novel-dl-qr-btn" type="button" data-act="closeBtn">关闭</button>'
            +     '</div>'
            +   '</div>'
            + '</div>';
        // 遮罩点击关闭
        uiQrDialog.addEventListener('click', function (e) {
            if (e.target === uiQrDialog) closeQrDialog();
        });
        // 关闭按钮 / 动作按钮
        uiQrDialog.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('[data-act]');
            if (!btn) return;
            var act = btn.getAttribute('data-act');
            if (act === 'closeBtn') return closeQrDialog();
            if (act === 'copyLink') return copyCurrentLink(btn);
            if (act === 'openLink') {
                var u = uiQrLinkEl && uiQrLinkEl.textContent;
                if (u) window.open(u, '_blank');
            }
        });
        uiQrCanvasBox = uiQrDialog.querySelector('.novel-dl-qr-canvas');
        uiQrLinkEl = uiQrDialog.querySelector('.novel-dl-qr-url');
        uiQrPassRow = uiQrDialog.querySelector('.novel-dl-qr-passrow');
        uiQrPassEl = uiQrDialog.querySelector('.novel-dl-qr-passrow .val');
        // 关闭按钮（×）
        var closeBtn = uiQrDialog.querySelector('.novel-dl-qr-close');
        if (closeBtn) closeBtn.addEventListener('click', closeQrDialog);
        uiRoot.appendChild(uiQrDialog);
    }

    function closeQrDialog() {
        if (uiQrDialog) uiQrDialog.style.display = 'none';
    }

    function renderQrTo(link) {
        if (!uiQrCanvasBox) return;
        uiQrCanvasBox.innerHTML = '';
        if (!link) return;
        // 优先用全局 qrcanvas（已在 simple 模板 scripts.html 全局加载）
        if (typeof qrcanvas !== 'undefined' && qrcanvas.qrcanvas) {
            try {
                var canvas = qrcanvas.qrcanvas({ data: link, size: 160 });
                uiQrCanvasBox.appendChild(canvas);
                return;
            } catch (e) { /* fallthrough */ }
        }
        // 兜底：动态加载 qrcanvas@3.js（其它模板/独立页面用到时也能工作）
        if (typeof qrcanvas !== 'undefined' && qrcanvas.qrcanvas) {
            uiQrCanvasBox.innerHTML = '<div class="novel-dl-qr-fail">二维码生成失败</div>';
            return;
        }
        if (window.__novelDlQrLoading) return;
        window.__novelDlQrLoading = true;
        var script = document.createElement('script');
        script.src = '/views/index/simple/common/static/js/qrcanvas@3.js';
        script.onload = function () {
            window.__novelDlQrLoading = false;
            renderQrTo(link);
        };
        script.onerror = function () {
            window.__novelDlQrLoading = false;
            uiQrCanvasBox.innerHTML = '<div class="novel-dl-qr-fail">二维码生成失败</div>';
        };
        document.head.appendChild(script);
    }

    function copyCurrentLink(btn) {
        var link = uiQrLinkEl && uiQrLinkEl.textContent;
        if (!link) return;
        var done = function () { if (btn) flashHint(btn, '已复制'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(done, done);
        } else {
            // 旧浏览器兜底
            try {
                var ta = document.createElement('textarea');
                ta.value = link;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                done();
            } catch (e) {}
        }
    }

    function openShare(task) {
        var link = task.shareUrlWithPasscode || task.shareUrl;
        if (!link) {
            window.alert('网盘链接尚未就绪');
            return;
        }
        if (!uiRoot) ensureUI();
        ensureQrDialog();
        uiQrLinkEl.textContent = link;
        if (task.passcode) {
            uiQrPassRow.style.display = '';
            uiQrPassEl.textContent = task.passcode;
        } else {
            uiQrPassRow.style.display = 'none';
            uiQrPassEl.textContent = '';
        }
        renderQrTo(link);
        uiQrDialog.style.display = '';
    }

    function copyShare(task) {
        var link = task.shareUrlWithPasscode || task.shareUrl;
        if (!link) return Promise.resolve(false);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(link).then(function () { return true; });
        }
        return Promise.resolve(false);
    }

    function copyPasscode(task) {
        if (!task.passcode) return Promise.resolve(false);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(task.passcode).then(function () { return true; });
        }
        return Promise.resolve(false);
    }

    // ---------- 浮窗 + 面板（增量更新渲染：DOM 稳定，避免滚动重置与封面闪烁） ----------
    var uiRoot = null;
    var uiPanel = null;
    var uiFab = null;
    var uiListEl = null;
    var itemNodes = {}; // bookUrl -> 任务条目 DOM 节点
    var STAGE_LABELS = { queued: '排队中', downloading: '下载中', uploading: '正在获取链接', done: '完成', fail: '失败' };

    function ensureUI() {
        if (uiRoot) { injectCoreStyles(); injectPanelScrollbarStyles(); startUploadSweeper(); return; }
        uiRoot = document.createElement('div');
        uiRoot.id = 'novel-downloader-root';
        document.body.appendChild(uiRoot);
        injectCoreStyles();
        injectPanelScrollbarStyles();
        startUploadSweeper();
        render();
    }

    // 注入悬浮入口 + 任务面板核心样式。
    // 原先这套样式只写在 novel.html 页内，其他板块页引入本模块后入口/面板无样式（不可见），故收进模块自注入
    function injectCoreStyles() {
        if (document.getElementById('novelDlCoreStyles')) return;
        var s = document.createElement('style');
        s.id = 'novelDlCoreStyles';
        s.textContent = ''
            + '@keyframes novel-dl-spin { to { transform: rotate(360deg); } }'
            + '#novel-downloader-root { position: fixed; z-index: 1200; }'
            + '#novel-downloader-root .novel-dl-fab { position: fixed; right: 26px; bottom: 90px; z-index: 1200; display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border: none; border-radius: 999px; background: linear-gradient(135deg, #e8a547, #f6c66a); color: #1a1a1a; font-size: 14px; font-weight: 700; cursor: pointer; box-shadow: 0 10px 26px rgba(232, 165, 71, 0.4); transition: transform .2s ease, box-shadow .2s ease; }'
            + '#novel-downloader-root .novel-dl-fab:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(232, 165, 71, 0.5); }'
            + '#novel-downloader-root .novel-dl-badge { min-width: 20px; height: 20px; padding: 0 6px; display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; background: #ff4d4f; color: #fff; font-size: 12px; font-weight: 700; }'
            + '#novel-downloader-root .novel-dl-icon { display: inline-block; width: 14px; height: 14px; border: 2.5px solid #1a1a1a; border-top-color: transparent; border-radius: 50%; animation: novel-dl-spin 0.9s linear infinite; }'
            + '#novel-downloader-root .novel-dl-panel { position: fixed; right: 26px; bottom: 160px; z-index: 1201; width: 480px; max-width: calc(100vw - 32px); max-height: 70vh; background: var(--theme-surface, #141E2E); color: var(--theme-text, #F2EEDC); border: 1px solid var(--theme-border, #2A3A52); border-radius: 14px; box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45); overflow: hidden; display: flex; flex-direction: column; }'
            + '#novel-downloader-root .novel-dl-panel-mask { display: none; }'
            + '#novel-downloader-root .novel-dl-panel-box { display: flex; flex-direction: column; max-height: inherit; }'
            + '#novel-downloader-root .novel-dl-panel-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--theme-border, #2A3A52); font-size: 15px; font-weight: 700; }'
            + '#novel-downloader-root .novel-dl-close { background: transparent; border: none; color: var(--theme-text-secondary, #8B9BB4); font-size: 20px; line-height: 1; cursor: pointer; }'
            + '#novel-downloader-root .novel-dl-list { padding: 12px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }'
            + '#novel-downloader-root .novel-dl-item { display: flex; gap: 12px; padding: 12px; background: var(--theme-card, #1A2538); border: 1px solid var(--theme-border, #2A3A52); border-radius: 10px; }'
            + '#novel-downloader-root .novel-dl-cover { width: 52px; height: 70px; flex-shrink: 0; border-radius: 6px; overflow: hidden; background: var(--theme-background, #0E1620); border: 1px solid var(--theme-border, #2A3A52); position: relative; display: flex; align-items: center; justify-content: center; }'
            + '#novel-downloader-root .novel-dl-cover img { width: 100%; height: 100%; object-fit: cover; }'
            + '#novel-downloader-root .novel-dl-cover.no-cover .novel-dl-cover-fallback, #novel-downloader-root .novel-dl-cover-fallback { font-size: 11px; color: var(--theme-text-secondary, #8B9BB4); }'
            + '#novel-downloader-root .novel-dl-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }'
            + '#novel-downloader-root .novel-dl-title { font-size: 14px; font-weight: 600; color: var(--theme-text, #F2EEDC); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }'
            + '#novel-downloader-root .novel-dl-meta { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--theme-text-secondary, #8B9BB4); flex-wrap: wrap; }'
            + '#novel-downloader-root .novel-dl-stage { font-weight: 600; padding: 1px 8px; border-radius: 999px; background: rgba(255, 255, 255, 0.08); }'
            + '#novel-downloader-root .novel-dl-stage.stage-downloading, #novel-downloader-root .novel-dl-stage.stage-uploading, #novel-downloader-root .novel-dl-stage.stage-queued { color: #e8a547; }'
            + '#novel-downloader-root .novel-dl-stage.stage-done { color: #67c23a; }'
            + '#novel-downloader-root .novel-dl-stage.stage-fail { color: #f56c6c; }'
            + '#novel-downloader-root .novel-dl-acc { font-size: 11px; color: var(--theme-text-secondary, #8B9BB4); background: rgba(232, 165, 71, 0.12); padding: 1px 6px; border-radius: 4px; }'
            + '#novel-downloader-root .novel-dl-bar { height: 6px; background: rgba(255, 255, 255, 0.08); border-radius: 999px; overflow: hidden; }'
            + '#novel-downloader-root .novel-dl-bar-fill { height: 100%; background: linear-gradient(90deg, #e8a547, #f6c66a); transition: width 0.3s ease; }'
            + '#novel-downloader-root .novel-dl-msg { font-size: 12px; color: var(--theme-text-secondary, #8B9BB4); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }'
            + '#novel-downloader-root .novel-dl-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }'
            + '#novel-downloader-root .novel-dl-btn { background: transparent; border: 1px solid var(--theme-border, #2A3A52); color: var(--theme-text-secondary, #8B9BB4); border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer; transition: all .2s ease; }'
            + '#novel-downloader-root .novel-dl-btn:hover { color: #e8a547; border-color: #e8a547; }'
            + '#novel-downloader-root .novel-dl-btn.primary { color: #1a1a1a; background: linear-gradient(135deg, #e8a547, #f6c66a); border-color: transparent; font-weight: 600; }'
            + '#novel-downloader-root .novel-dl-btn.danger { color: #f56c6c; border-color: rgba(245, 108, 108, 0.3); }'
            + '#novel-downloader-root .novel-dl-btn.danger:hover { color: #fff; background: rgba(245, 108, 108, 0.2); border-color: #f56c6c; }'
            + '@media (max-width: 640px) { #novel-downloader-root .novel-dl-panel { right: 16px; left: 16px; width: auto; } #novel-downloader-root .novel-dl-fab { right: 16px; bottom: 16px; } }';
        document.head.appendChild(s);
    }

    // 注入任务面板的滚动条美化样式（与搜索弹窗统一：细圆角，适配深色主题）
    function injectPanelScrollbarStyles() {
        if (document.getElementById('novelDlScrollbarStyles')) return;
        var s = document.createElement('style');
        s.id = 'novelDlScrollbarStyles';
        s.textContent = ''
            + '#novel-downloader-root .novel-dl-list { scrollbar-width: thin; scrollbar-color: var(--theme-border, #2A3A52) transparent; }'
            + '#novel-downloader-root .novel-dl-list::-webkit-scrollbar { width: 8px; }'
            + '#novel-downloader-root .novel-dl-list::-webkit-scrollbar-track { background: transparent; }'
            + '#novel-downloader-root .novel-dl-list::-webkit-scrollbar-thumb { background: var(--theme-border, #2A3A52); border-radius: 8px; }'
            + '#novel-downloader-root .novel-dl-list::-webkit-scrollbar-thumb:hover { background: var(--theme-text-secondary, #8B9BB4); }';
        document.head.appendChild(s);
    }

    function ensureFab() {
        if (uiFab) return;
        uiFab = document.createElement('button');
        uiFab.className = 'novel-dl-fab';
        uiFab.type = 'button';
        uiFab.addEventListener('click', function () { togglePanel(); });
        uiRoot.appendChild(uiFab);
    }

    function ensurePanel() {
        if (uiPanel) return;
        uiPanel = document.createElement('div');
        uiPanel.className = 'novel-dl-panel';
        uiPanel.style.display = 'none';
        // 骨架只创建一次；滚动容器 .novel-dl-list 长期复用，进度刷新不再重置滚动位置
        uiPanel.innerHTML = '<div class="novel-dl-panel-mask" data-act="close"></div>' +
            '<div class="novel-dl-panel-box">' +
            '<div class="novel-dl-panel-head"><span>小说下载任务</span><button class="novel-dl-close" data-act="close" aria-label="关闭">×</button></div>' +
            '<div class="novel-dl-list"></div>' +
            '</div>';
        uiListEl = uiPanel.querySelector('.novel-dl-list');
        // 事件委托绑定一次，后续增量更新无需重绑
        uiPanel.addEventListener('click', onPanelClick);
        uiRoot.appendChild(uiPanel);
    }

    function render() {
        if (!uiRoot) return;
        var list = getList();
        if (!list.length) {
            if (uiFab) uiFab.style.display = 'none';
            if (uiPanel) uiPanel.style.display = 'none';
            return;
        }

        // 悬浮按钮（内容变化才重写，避免动画/徽标无谓重建）
        ensureFab();
        var downloading = list.filter(function (t) { return ['queued', 'downloading', 'uploading'].indexOf(t.stage) !== -1; }).length;
        uiFab.style.display = '';
        // 有进行中任务显示「下载中」+ 转圈 + 数量徽标；全部结束只显示「下载」
        var fabHtml = '<span class="novel-dl-icon" style="' + (downloading ? '' : 'display:none') + '"></span>' +
            '<span>' + (downloading ? '下载中' : '下载') + '</span>' +
            '<span class="novel-dl-badge" style="' + (downloading ? '' : 'display:none') + '">' + downloading + '</span>';
        if (uiFab._html !== fabHtml) { uiFab._html = fabHtml; uiFab.innerHTML = fabHtml; }

        ensurePanel();
        syncPanel(list);

        // 面板开合只由用户手动点击控制（悬浮入口/关闭按钮/发起下载后展开），页面加载时始终保持关闭
    }

    function syncPanel(list) {
        if (!uiListEl) return;
        var seen = {};
        for (var i = 0; i < list.length; i++) {
            var t = list[i];
            seen[t.bookUrl] = true;
            var node = itemNodes[t.bookUrl];
            if (!node) {
                node = createItemNode(t);
                itemNodes[t.bookUrl] = node;
            }
            if (uiListEl.children[i] !== node) {
                uiListEl.insertBefore(node, uiListEl.children[i] || null);
            }
            syncItemNode(node, t);
        }
        for (var url in itemNodes) {
            if (Object.prototype.hasOwnProperty.call(itemNodes, url) && !seen[url]) {
                var n = itemNodes[url];
                if (n.parentNode) n.parentNode.removeChild(n);
                delete itemNodes[url];
            }
        }
    }

    function createItemNode(t) {
        var tmp = document.createElement('div');
        tmp.innerHTML = buildItemHTML(t);
        var node = tmp.firstChild;
        node._sig = itemSignature(t);
        // 覆盖内联 onerror：记录失败 src，避免后续更新反复重建失败的 img
        var img = node.querySelector('.novel-dl-cover img');
        if (img) {
            img.onerror = function () {
                node._coverFailed = img.getAttribute('src');
                var cover = node.querySelector('.novel-dl-cover');
                if (cover) cover.classList.add('no-cover');
                if (img.parentNode) img.parentNode.removeChild(img);
            };
        }
        return node;
    }

    function itemSignature(t) {
        return [t.stage, t.progress, t.total, t.message, t.bookName, t.author,
            (t.accounts || []).join(','), t.shareUrl, t.shareUrlWithPasscode, t.passcode,
            t.filename, t.uploadFail ? 1 : 0].join('|');
    }

    function syncItemNode(node, t) {
        // 封面节点长期复用：仅 src 变化时才更新，杜绝重复请求造成的闪烁
        var cover = node.querySelector('.novel-dl-cover');
        if (cover) {
            var wantSrc = t.cover || coverProxy(t.bookUrl);
            var img = cover.querySelector('img');
            if (!img) {
                // 该 src 已确认加载失败则保持“无封面”兜底，不再重复请求；
                // 仅当封面地址发生变化时才重建一次
                if (node._coverFailed !== wantSrc) {
                    img = document.createElement('img');
                    img.alt = '';
                    img.onerror = function () {
                        node._coverFailed = img.getAttribute('src');
                        cover.classList.add('no-cover');
                        if (img.parentNode) img.parentNode.removeChild(img);
                    };
                    img.src = wantSrc;
                    cover.insertBefore(img, cover.firstChild);
                }
            } else if (img.getAttribute('src') !== wantSrc) {
                img.src = wantSrc;
            }
        }

        // 内容区：签名未变化则完全不触碰 DOM（高频进度事件下零开销、零闪烁）
        var sig = itemSignature(t);
        if (node._sig === sig) return;
        node._sig = sig;
        var body = node.querySelector('.novel-dl-body');
        if (body) body.innerHTML = buildItemBodyHTML(t);
    }

    function buildItemHTML(t) {
        var html = '<div class="novel-dl-item" data-url="' + escapeHtml(t.bookUrl) + '">';
        html += '<div class="novel-dl-cover"><img src="' + escapeHtml(t.cover || coverProxy(t.bookUrl)) + '" alt="" onerror="this.parentNode.classList.add(\'no-cover\');this.remove();"/><span class="novel-dl-cover-fallback">无封面</span></div>';
        html += '<div class="novel-dl-body">';
        html += buildItemBodyHTML(t);
        html += '</div></div>';
        return html;
    }

    function buildItemBodyHTML(t) {
        var stageLabel = STAGE_LABELS[t.stage] || '';
        var pct = (t.stage === 'uploading' || t.stage === 'done') ? 100 :
                   (t.total > 0 ? Math.min(100, Math.round(t.progress / t.total * 100)) : 0);
        var accountHtml = '';
    if (t.accounts && t.accounts.length) {
            var accNames = accountNamesForList(t.accounts);
            if (accNames.length) accountHtml = '<span class="novel-dl-acc">' + escapeHtml(accNames.join('、')) + '</span>';
    }
        var progressHtml = (t.stage === 'downloading' && t.total > 0)
            ? '<div class="novel-dl-bar"><div class="novel-dl-bar-fill" style="width:' + pct + '%"></div></div>' : '';
        var shareHtml = (t.stage === 'done' && t.shareUrl)
            ? '<button class="novel-dl-btn primary" data-act="openShare" data-url="' + escapeHtml(t.bookUrl) + '">打开网盘链接</button>' +
              (t.passcode ? '<button class="novel-dl-btn" data-act="copyPass" data-url="' + escapeHtml(t.bookUrl) + '">复制提取码</button>' : '')
            : '';
        // 仅“下载到本地”模式（无 accounts/非 transfer）才显示“下载到浏览器”按钮；
        // 转存网盘模式（accounts 非空）下资源已落到网盘，主导操作是打开网盘链接/复制，
        // “下载到浏览器”会暗示用户把文件再下一遍，意义不大且冗余，故隐藏
        var hasUpload = Array.isArray(t.accounts) && t.accounts.length > 0;
        var fileBtnHtml = (t.stage === 'done' && !hasUpload)
            ? '<button class="novel-dl-btn primary" data-act="download" data-url="' + escapeHtml(t.bookUrl) + '">下载到浏览器</button>' : '';

        var html = '';
        html += '<div class="novel-dl-title" title="' + escapeHtml(t.bookName || t.bookUrl) + '">' + escapeHtml(t.bookName || t.bookUrl) + '</div>';
        html += '<div class="novel-dl-meta">';
        html += '<span class="novel-dl-stage stage-' + t.stage + '">' + stageLabel + '</span>';
        if (t.author) html += '<span class="novel-dl-author">' + escapeHtml(t.author) + '</span>';
        html += accountHtml;
        html += '</div>';
        if (progressHtml) html += progressHtml;
        if (t.message) html += '<div class="novel-dl-msg" title="' + escapeHtml(t.message) + '">' + escapeHtml(t.message) + '</div>';
        html += '<div class="novel-dl-actions">';
        html += fileBtnHtml;
        html += shareHtml;
        if (t.stage === 'done' || t.stage === 'fail') {
            html += '<button class="novel-dl-btn danger" data-act="remove" data-url="' + escapeHtml(t.bookUrl) + '">移除记录</button>';
        }
        // 失败时如仍持有已下载的文件名，允许直接下到本地（不依赖网盘转存是否成功）；
        // 转存网盘模式不展示，避免与“重新下载”产生重复动作
        if (t.stage === 'fail' && t.filename && !hasUpload) {
            html += '<button class="novel-dl-btn primary" data-act="download" data-url="' + escapeHtml(t.bookUrl) + '">下载到浏览器</button>';
        }
        // 失败时无论是否仍有文件，都提供“重新下载”入口
        if (t.stage === 'fail') {
            html += '<button class="novel-dl-btn" data-act="retry" data-url="' + escapeHtml(t.bookUrl) + '">重新下载</button>';
        }
        html += '</div>';
        return html;
    }

    function onPanelClick(e) {
        var target = e.target;
        var n = target && target.closest ? target.closest('[data-act]') : null;
        if (!n || !uiPanel || !uiPanel.contains(n)) return;
        e.stopPropagation();
        var act = n.getAttribute('data-act');
        var url = n.getAttribute('data-url');
        if (act === 'close') { togglePanel(false); return; }
        if (!url) return;
        var t = tasks[url];
        if (!t) return;
        if (act === 'download') downloadToBrowser(t);
        else if (act === 'openShare') openShare(t);
        else if (act === 'copyShare') copyShare(t).then(function (ok) { if (ok) flashHint(n, '已复制'); });
        else if (act === 'copyPass') copyPasscode(t).then(function (ok) { if (ok) flashHint(n, '已复制'); });
        else if (act === 'retry') retryTask(t);
        else if (act === 'remove') {
            if (window.confirm('确定要移除该记录吗？')) removeTask(url);
        }
    }

    function flashHint(btn, msg) {
        var orig = btn.textContent;
        btn.textContent = msg;
        setTimeout(function () { btn.textContent = orig; }, 1200);
    }

    function togglePanel(force) {
        if (!uiPanel) ensureUI();
        var visible = force == null ? uiPanel.style.display === 'none' : !!force;
        uiPanel.style.display = visible ? '' : 'none';
    }

    onChange(function () { render(); });

    function boot() {
        ensureUI();
        connect();
        render();
        // 预加载下载模式配置（后台下发），供搜索页按钮文案使用
        getDownloadConfig().then(function () { notifyChange(); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // 暴露接口
    window.novelDownloader = {
        getList: getList,
        startTask: startTask,
        getDownloadConfig: getDownloadConfig,
        getDownloadConfigSync: getDownloadConfigSync,
        openPanel: function () { togglePanel(true); },
        closePanel: function () { togglePanel(false); },
        togglePanel: togglePanel,
        removeTask: removeTask,
        downloadToBrowser: downloadToBrowser,
        onChange: onChange,
        getClientId: function () { return clientId; }
    };
})();