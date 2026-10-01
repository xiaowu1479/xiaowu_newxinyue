/**
 * 独立搜索模块 - 供非首页页面使用
 * 通过 window.searchModule 暴露接口
 * 包含 SSE 全网搜索 + 链接有效性检测
 */
(function () {
    var currentEventSource = null;
    var searchResults = [];
    var maxResults = 60;

    // 本地资源状态
    var localSearchResults = [];
    var hasLocalData = false;

    // 链接检测状态
    var isCheckingLinks = false;
    var shouldStopChecking = false;

    // Vue 响应式变量引用（由各页面通过 setupResourceDialog 注册）
    var vueRefs = null;
    var qrcanvasLoading = false;

    // 网盘类型映射
    var panTypeMap = {
        0: '夸克网盘', 1: '阿里云盘', 2: '百度网盘', 3: 'UC网盘',
        4: '迅雷云盘', 5: '123云盘', 6: '115网盘', 7: '天翼云盘',
        8: '移动云盘', 9: '磁力链接', 10: '光鸭网盘'
    };

    // 当前结果过滤类型（null 表示不过滤）
    var currentResultFilter = null;

    // 各网盘类型结果数量统计
    var resultTypeCounts = Vue.reactive({});

    // 创建统一搜索弹窗状态，首页和非首页页面共用（支持多线路选择）
    function createDialogState(keywordRef, options) {
        options = options || {};
        var searchDialogVisible = Vue.ref(false);
        var searchDialogStage = Vue.ref('select');
        var searchSelectedPanType = Vue.ref([]);
        // 搜索结果过滤 tab：当前选中的网盘类型（null 表示显示全部）
        var resultFilterPanType = Vue.ref(null);
        // 可选的过滤 tab 列表，只包含用户选中的线路，并显示该类型结果数量
        var availableResultTabs = Vue.computed(function () {
            return searchSelectedPanType.value.map(function (type) {
                return {
                    id: String(type),
                    name: panTypeMap[type] || ('线路' + type),
                    count: resultTypeCounts[type] || 0
                };
            });
        });

        function getDefaultPanType() {
            if (typeof options.getDefaultPanType === 'function') {
                var defaults = options.getDefaultPanType();
                if (Array.isArray(defaults)) return defaults;
                if (defaults !== '' && defaults != null) return [defaults];
            }
            return [];
        }

        function handleEmptyKeyword() {
            if (typeof options.onEmptyKeyword === 'function') {
                options.onEmptyKeyword();
            }
        }

        function runSearch(panTypes) {
            if (typeof options.doSearch === 'function') {
                return options.doSearch(panTypes);
            }
            return window.searchModule.doSearch(keywordRef.value, panTypes);
        }

        var openSearchDialog = function () {
            searchSelectedPanType.value = getDefaultPanType();
            searchDialogVisible.value = true;
            searchDialogStage.value = 'select';
        };

        var searchBtn = function () {
            if (options.validateKeywordOnSearch !== false && !keywordRef.value) {
                handleEmptyKeyword();
                return;
            }
            openSearchDialog();
        };

        // 切换某个线路的选中状态
        var togglePanType = function (panType) {
            var idx = searchSelectedPanType.value.indexOf(panType);
            if (idx > -1) {
                searchSelectedPanType.value.splice(idx, 1);
            } else {
                searchSelectedPanType.value.push(panType);
            }
            searchSelectedPanType.value = searchSelectedPanType.value.slice();
            if (typeof options.onSelectPanType === 'function') {
                options.onSelectPanType(searchSelectedPanType.value);
            }
        };

        // 选中所有线路
        var selectAllPanTypes = function (allTypes) {
            searchSelectedPanType.value = (allTypes || []).slice();
            if (typeof options.onSelectPanType === 'function') {
                options.onSelectPanType(searchSelectedPanType.value);
            }
        };

        // 清空选中
        var clearPanTypes = function () {
            searchSelectedPanType.value = [];
            if (typeof options.onSelectPanType === 'function') {
                options.onSelectPanType([]);
            }
        };

        // 使用选中的线路开始搜索（多选入口）
        var startMultiSearch = function () {
            if (!keywordRef.value) {
                handleEmptyKeyword();
                return;
            }
            if (searchSelectedPanType.value.length === 0) {
                if (typeof options.onEmptyPanType === 'function') {
                    options.onEmptyPanType();
                }
                return;
            }
            // 清空历史数量统计，默认选中第一个类型
            Object.keys(resultTypeCounts).forEach(function(key) {
                delete resultTypeCounts[key];
            });
            resultFilterPanType.value = searchSelectedPanType.value.length > 0 ? String(searchSelectedPanType.value[0]) : null;
            window.searchModule.setResultFilter(resultFilterPanType.value);
            searchDialogStage.value = 'results';
            Vue.nextTick(function () {
                runSearch(searchSelectedPanType.value.slice());
            });
        };

        // 切换搜索结果过滤 tab
        var setResultFilterPanType = function(type) {
            resultFilterPanType.value = type;
            window.searchModule.setResultFilter(type);
        };

        // 点击 chip 行为：默认立即搜索（首页兼容），传入 selectPanAndSearchMode: 'toggle' 时只切换选中
        var selectPanAndSearch = function (panType) {
            if (options.selectPanAndSearchMode === 'toggle') {
                togglePanType(panType);
                return;
            }
            // 旧行为：单选并立即搜索
            if (!keywordRef.value) {
                handleEmptyKeyword();
                return;
            }
            searchSelectedPanType.value = [panType];
            searchDialogStage.value = 'results';
            if (typeof options.onSelectPanType === 'function') {
                options.onSelectPanType(panType);
            }
            Vue.nextTick(function () {
                runSearch(panType);
            });
        };

        var backToSelectPanType = function () {
            if (typeof options.stopSearch === 'function') {
                options.stopSearch();
            } else {
                window.searchModule.stopSearch();
            }
            resultFilterPanType.value = null;
            Object.keys(resultTypeCounts).forEach(function(key) {
                delete resultTypeCounts[key];
            });
            window.searchModule.setResultFilter(null);
            searchDialogStage.value = 'select';
        };

        var onDialogKeywordEnter = function () {
            if (!keywordRef.value) {
                handleEmptyKeyword();
                return;
            }
            if (searchDialogStage.value === 'results' && searchSelectedPanType.value.length > 0) {
                startMultiSearch();
            } else if (searchDialogStage.value === 'select') {
                startMultiSearch();
            }
        };

        var switchPanFromResource = function () {
            var title = '';
            if (vueRefs && vueRefs.dialogItem && vueRefs.dialogItem.value) {
                title = vueRefs.dialogItem.value.title || '';
            }
            if (title) {
                keywordRef.value = title;
            }
            if (vueRefs && vueRefs.dialogUrl) {
                vueRefs.dialogUrl.value = false;
            }
            openSearchDialog();
        };

        return {
            searchBtn: searchBtn,
            searchDialogVisible: searchDialogVisible,
            searchDialogStage: searchDialogStage,
            searchSelectedPanType: searchSelectedPanType,
            resultFilterPanType: resultFilterPanType,
            availableResultTabs: availableResultTabs,
            openSearchDialog: openSearchDialog,
            selectPanAndSearch: selectPanAndSearch,
            togglePanType: togglePanType,
            selectAllPanTypes: selectAllPanTypes,
            clearPanTypes: clearPanTypes,
            startMultiSearch: startMultiSearch,
            setResultFilterPanType: setResultFilterPanType,
            backToSelectPanType: backToSelectPanType,
            onDialogKeywordEnter: onDialogKeywordEnter,
            switchPanFromResource: switchPanFromResource
        };
    };

    // 全局资源检测函数（供搜索弹窗按钮调用，手动触发时绕过开关限制）
    window.__checkSearchLinks = function () {
        // 显示"检测中"标识（移除隐藏类）
        document.documentElement.classList.remove('link-check-disabled');
        // 临时开启检测，使 batchCheckLinks 不被 __linkCheckEnabled 拦截
        var wasEnabled = window.__linkCheckEnabled;
        window.__linkCheckEnabled = true;
        try {
            if (typeof window.batchCheckLinksForSearchResults === 'function') {
                window.batchCheckLinksForSearchResults();
            } else if (typeof batchCheckLinks === 'function') {
                batchCheckLinks();
            }
        } finally {
            window.__linkCheckEnabled = wasEnabled;
        }
    };

    // 获取搜索结果容器
    function getContentEl() { return document.getElementById('searchResultContent'); }
    function getCountEl() { return document.getElementById('searchResultCount'); }
    function getSectionEl() { return document.getElementById('searchResultSection'); }

    // 根据网盘类型过滤已渲染的搜索结果（失效项始终隐藏）
    function filterSearchResults(type) {
        var content = getContentEl();
        if (!content) return;
        var items = content.querySelectorAll('.search-result-item');
        items.forEach(function(item) {
            var statusEl = item.querySelector('.link-check-status');
            var isInvalid = statusEl && statusEl.classList.contains('invalid');
            // 失效链接始终隐藏，不受 tab 切换影响
            if (isInvalid) {
                item.style.display = 'none';
                return;
            }
            var itemType = item.getAttribute('data-is_type');
            var show = type === null || String(itemType) === String(type);
            item.style.display = show ? '' : 'none';
        });
        // 总数始终显示全部结果，已隐藏只统计失效链接
        updateSearchResultCount();
        // Tab 切换后检测当前可见项的链接有效性
        setTimeout(function () {
            batchCheckLinks();
        }, 100);
    }

    // 设置当前结果过滤类型
    function setResultFilter(type) {
        currentResultFilter = type;
        filterSearchResults(type);
    }

    // 注入样式（只注入一次）
    function injectStyles() {
        if (!document.getElementById('searchResultAnimations')) {
            var style = document.createElement('style');
            style.id = 'searchResultAnimations';
            style.textContent = '@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }';
            document.head.appendChild(style);
        }
        if (!document.getElementById('linkCheckAnimations')) {
            var linkCheckStyle = document.createElement('style');
            linkCheckStyle.id = 'linkCheckAnimations';
            linkCheckStyle.textContent = ''
                + '@keyframes linkCheckRotate { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }'
                + '.link-check-status { transition: all 0.3s ease; }'
                + '.link-check-status.valid { background: #f6ffed !important; color: #52c41a !important; border: 1px solid #b7eb8f; }'
                + '.link-check-status.valid .check-icon { animation: none !important; border: none !important; width: auto !important; height: auto !important; }'
                + '.link-check-status.valid .check-text::before { content: "\\2713 "; }'
                + '.link-check-status.invalid { background: #fff2f0 !important; color: #ff4d4f !important; border: 1px solid #ffccc7; }'
                + '.link-check-status.invalid .check-icon { animation: none !important; border: none !important; width: auto !important; height: auto !important; }'
                + '.link-check-status.invalid .check-text::before { content: "\\2717 "; }'
                + '.link-check-status.error { background: #fffbe6 !important; color: #faad14 !important; border: 1px solid #ffe58f; }'
                + '.link-check-status.error .check-icon { animation: none !important; border: none !important; width: auto !important; height: auto !important; }'
                + '.link-check-status.error .check-text::before { content: "! "; }';
            document.head.appendChild(linkCheckStyle);
        }
    }

    // 转义HTML
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 渲染单条结果
    function renderResultItem(item, index) {
        var title = escapeHtml(item.title || item.note || '未知资源');
        var desc = item.desc || item.description || '';
        // 将 is_type 数字转换为网盘名称
        var isTypeNum = parseInt(item.is_type, 10);
        var typeLabel = panTypeMap[isTypeNum] || item.pantype || '网盘资源';
        // 时间字段：优先 datetime
        var rawTime = item.datetime || item.time || item.date || item.create_time || '';
        var timeStr = '';
        if (rawTime) {
            timeStr = rawTime.substring(0, 19).replace('T', ' ');
            if (timeStr.indexOf('0000-') === 0 || timeStr.indexOf('0001-') === 0) {
                var now = new Date();
                timeStr = now.getFullYear() + '-' +
                    String(now.getMonth() + 1).padStart(2, '0') + '-' +
                    String(now.getDate()).padStart(2, '0') + ' ' +
                    String(now.getHours()).padStart(2, '0') + ':' +
                    String(now.getMinutes()).padStart(2, '0') + ':' +
                    String(now.getSeconds()).padStart(2, '0');
            }
        }
        if (!timeStr && item.source) {
            timeStr = item.source;
        }

        // 本地资源标签
        var isLocalResource = item.source === '本地资源';
        var localTag = isLocalResource ? '<span class="search-result-tag local" style="background: linear-gradient(135deg, #ff6b6b, #ff8e8e); color: #fff;">本地资源</span>' : '';

        // 链接有效性检测状态：默认"检测中"
        var itemUrl = item.url || '';
        var checkStatusHtml = '<span class="search-result-tag link-check-status" data-url="' + escapeHtml(itemUrl) + '" data-index="' + index + '" style="background:#f5f5f5;color:#666;font-size:11px;padding:2px 8px;">';
        checkStatusHtml += '<span class="check-icon" style="display:inline-block;width:10px;height:10px;margin-right:4px;border:2px solid #999;border-top-color:transparent;border-radius:50%;animation:linkCheckRotate 1s linear infinite;"></span>';
        checkStatusHtml += '<span class="check-text">检测中</span>';
        checkStatusHtml += '</span>';

        var html = '<div class="search-result-item" data-is_type="' + isTypeNum + '" data-index="' + index + '" onclick="window.searchModule.getResource(event,' + index + ')" style="animation: fadeIn 0.3s ease;">';
        html += '<div class="search-result-info">';
        html += '<div class="search-result-name">' + title + '</div>';
        if (desc) {
            html += '<div class="search-result-desc">' + escapeHtml(desc) + '</div>';
        }
        html += '<div class="search-result-meta">';
        html += '<span class="search-result-tag type"><i class="iconfont icon-yunpan" style="font-size:12px;"></i>' + escapeHtml(typeLabel) + '</span>';
        html += localTag;
        html += checkStatusHtml;
        if (timeStr) {
            html += '<span class="search-result-tag source">' + escapeHtml(timeStr) + '</span>';
        }
        html += '</div>';
        html += '</div>';
        html += '<div class="search-result-actions">';
        html += '<span class="search-result-btn get"><i class="iconfont icon-xiazai"></i> 获取资源</span>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    // 渲染结果列表
    function renderResults() {
        var content = getContentEl();
        var countEl = getCountEl();
        if (!content) return;

        if (searchResults.length === 0) {
            content.innerHTML = '<div class="search-result-empty" style="text-align:center;padding:40px 20px;color:#999;">暂无搜索结果</div>';
            if (countEl) countEl.textContent = '';
            return;
        }

        var html = '<div class="search-result-list">';
        for (var i = 0; i < searchResults.length; i++) {
            html += renderResultItem(searchResults[i], i);
        }
        html += '</div>';
        content.innerHTML = html;

        if (countEl) countEl.textContent = '共 ' + searchResults.length + ' 条结果';
    }

    // 增量追加单条结果到列表
    function appendSearchResult(item, index) {
        var content = getContentEl();
        if (!content) return;
        var list = content.querySelector('.search-result-list');
        if (!list) {
            content.innerHTML = '<div class="search-result-list"></div>';
            list = content.querySelector('.search-result-list');
        }
        list.insertAdjacentHTML('beforeend', renderResultItem(item, index));
        // 更新该类型的数量统计
        var typeKey = String(item.is_type);
        resultTypeCounts[typeKey] = (resultTypeCounts[typeKey] || 0) + 1;
        // 如果当前有过滤条件，新结果不匹配则隐藏
        if (currentResultFilter !== null && typeKey !== String(currentResultFilter)) {
            var lastItem = list.lastElementChild;
            if (lastItem) {
                lastItem.style.display = 'none';
            }
        }
    }

    // 检查本地资源（isType 支持单个值或数组）
    function checkLocalResources(query, isType) {
        try {
            var params = { title: query };
            if (isType && isType !== '' && isType !== 'local') {
                if (Array.isArray(isType) && isType.length > 0) {
                    params.is_type = isType;
                } else {
                    params.is_type = isType;
                }
            }
            return axios.get('/api/other/local_search_check', {
                params: params,
                timeout: 5000
            }).then(function (response) {
                if (response.data && response.data.code === 200) {
                    return {
                        hasData: response.data.data.hasData,
                        count: response.data.data.count || 0,
                        items: response.data.data.items || []
                    };
                }
                return { hasData: false, count: 0, items: [] };
            }).catch(function () {
                return { hasData: false, count: 0, items: [] };
            });
        } catch (e) {
            return Promise.resolve({ hasData: false, count: 0, items: [] });
        }
    }

    // ============== 链接有效性检测 ==============

    // 判断链接是否需要解密（非 http/magnet 开头则为加密链接）
    function needsDecryption(url) {
        if (!url) return false;
        if (url.startsWith('http') || url.startsWith('magnet:')) return false;
        return true;
    }

    // 解密链接
    async function decryptUrl(encryptedUrl) {
        try {
            var response = await axios.post('/api/other/save_url', {
                url: encodeURIComponent(encryptedUrl),
                title: ''
            });
            if (response.data.code == 200 && response.data.data && response.data.data.url) {
                return response.data.data.url;
            }
            return null;
        } catch (error) {
            return null;
        }
    }

    // 更新链接状态显示
    function updateLinkStatus(element, result) {
        if (!element) return;
        var textEl = element.querySelector('.check-text');
        if (!textEl) return;

        // 清除所有状态类名
        element.classList.remove('valid', 'invalid', 'error');

        if (result.valid === true) {
            element.classList.add('valid');
            textEl.textContent = '有效';
        } else if (result.valid === false) {
            element.classList.add('invalid');
            textEl.textContent = '失效';
            // 隐藏失效的搜索记录
            var searchItem = element.closest('.search-result-item');
            if (searchItem) {
                searchItem.style.display = 'none';
                updateSearchResultCount();
            }
        } else {
            element.classList.add('error');
            textEl.textContent = result.error || '检测失败';
        }
    }

    // 更新搜索结果数量显示：总数始终为全部结果，已隐藏只统计失效链接
    function updateSearchResultCount() {
        var countEl = getCountEl();
        var content = getContentEl();
        if (!countEl || !content) return;

        var allItems = content.querySelectorAll('.search-result-item');
        var total = allItems.length;
        var invalidCount = 0;
        allItems.forEach(function(item) {
            var statusEl = item.querySelector('.link-check-status');
            if (statusEl && statusEl.classList.contains('invalid')) {
                invalidCount++;
            }
        });

        if (invalidCount > 0) {
            countEl.textContent = '共 ' + total + ' 条结果（已隐藏 ' + invalidCount + ' 条失效）';
        } else {
            countEl.textContent = '共 ' + total + ' 条结果';
        }
    }

    // 检测单条链接
    async function checkSingleLink(url, element) {
        try {
            var checkUrl = url;

            // 检查链接是否需要解密
            if (needsDecryption(url)) {
                var decryptedUrl = await decryptUrl(url);
                if (decryptedUrl) {
                    checkUrl = decryptedUrl;
                } else {
                    updateLinkStatus(element, { valid: null, error: '链接解密失败' });
                    return;
                }
            }

            var response = await fetch('/panchek/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ links: [checkUrl] })
            });

            if (!response.ok) {
                throw new Error('API 请求失败: ' + response.status);
            }

            var data = await response.json();

            if (data.success && data.results && data.results.length > 0) {
                var result = data.results[0];
                updateLinkStatus(element, result);
            } else {
                updateLinkStatus(element, { valid: null, error: '返回数据错误' });
            }
        } catch (error) {
            updateLinkStatus(element, { valid: null, error: '检测失败' });
        }
    }

    // 批量检测搜索结果中当前可见项的链接有效性
    async function batchCheckLinks() {
        // 后台「检测资源」开关关闭时不进行检测
        if (!window.__linkCheckEnabled) return;

        // 如果正在检测，先停止之前的
        if (isCheckingLinks) {
            stopLinkChecking();
            await new Promise(function (resolve) { setTimeout(resolve, 200); });
        }

        // 重置停止标志
        shouldStopChecking = false;

        var content = getContentEl();
        if (!content) return;

        // 只收集当前可见结果项中的检测元素
        var resultItems = content.querySelectorAll('.search-result-item');
        var checkItems = [];
        resultItems.forEach(function (item) {
            if (item.style.display === 'none') return;
            var el = item.querySelector('.link-check-status');
            if (!el) return;
            var url = el.getAttribute('data-url');
            var isChecked = el.classList.contains('valid') ||
                el.classList.contains('invalid') ||
                el.classList.contains('error');
            if (url && !isChecked) {
                checkItems.push({ url: url, element: el });
            }
        });

        if (checkItems.length === 0) return;

        isCheckingLinks = true;

        try {
            for (var i = 0; i < checkItems.length; i++) {
                if (shouldStopChecking) break;

                // 检查是否还在当前页面（防止页面已切换）
                var currentContent = getContentEl();
                if (!currentContent) break;

                var item = checkItems[i];
                await checkSingleLink(item.url, item.element);
                // 添加小延迟，避免请求过快
                await new Promise(function (resolve) { setTimeout(resolve, 100); });
            }
        } finally {
            isCheckingLinks = false;
            shouldStopChecking = false;
        }
    }

    // 停止链接检测
    function stopLinkChecking() {
        if (isCheckingLinks) {
            shouldStopChecking = true;
        }
    }

    // ============== SSE 搜索 ==============

    // 执行搜索
    async function doSearch(keyword, panType) {
        if (!keyword) return;

        // 注入样式（首次搜索时）
        injectStyles();

        // 停止旧的链接检测
        stopLinkChecking();

        // 关闭旧的 SSE 连接
        if (currentEventSource) {
            try { currentEventSource.close(); } catch (e) {}
            currentEventSource = null;
        }

        var section = getSectionEl();
        var content = getContentEl();
        var countEl = getCountEl();

        if (section) section.classList.add('show');
        if (content) {
            content.innerHTML = '<div class="search-result-loading"><div class="loading-wrapper"><div class="loading-pulse"></div><div class="loading-pulse"></div><div class="loading-pulse"></div><i class="iconfont icon-jiazai"></i></div><div class="loading-text">正在全网搜索</div></div>';
        }
        if (countEl) countEl.textContent = '';

        // 重置本地资源状态
        hasLocalData = false;
        localSearchResults = [];

        // 先检查本地资源
        var localResult = await checkLocalResources(keyword, panType);
        if (localResult.hasData && localResult.items.length > 0) {
            hasLocalData = true;
            localSearchResults = localResult.items;
        }

        searchResults = hasLocalData ? [...localSearchResults] : [];
        var localCount = searchResults.length;
        var isClosed = false;

        // 如果有本地资源，先渲染本地资源
        if (hasLocalData && localCount > 0) {
            if (content) {
                content.innerHTML = '<div class="search-result-list"></div>';
                localSearchResults.forEach(function(item, index) {
                    appendSearchResult(item, index);
                });
            }
            if (countEl) countEl.textContent = '已找到 ' + localCount + ' 条本地资源，正在全网搜索...';
        }

        var isTypeParam = '';
        if (Array.isArray(panType) && panType.length > 0) {
            isTypeParam = '&' + panType.map(function(t) { return 'is_type[]=' + encodeURIComponent(t); }).join('&');
        } else if (panType !== '' && panType != null) {
            isTypeParam = '&is_type=' + encodeURIComponent(panType);
        }
        var searchUrl = '/api/other/web_search?title=' + encodeURIComponent(keyword) + isTypeParam;

        try {
            currentEventSource = new EventSource(searchUrl);

            currentEventSource.onmessage = function (event) {
                if (isClosed) return;

                // 处理 [DONE] 或 [DONE] 开头的消息
                if (event.data === '[DONE]' || (typeof event.data === 'string' && event.data.indexOf('[DONE]') === 0)) {
                    isClosed = true;
                    if (currentEventSource) {
                        try { currentEventSource.close(); } catch (e) {}
                        currentEventSource = null;
                    }
                    if (searchResults.length > 0) {
                        var localText = localCount > 0 ? '（含' + localCount + '条本地）' : '';
                        if (countEl) countEl.textContent = '共 ' + searchResults.length + ' 条结果' + localText;
                        setTimeout(function () { batchCheckLinks(); }, 500);
                    } else {
                        if (countEl) countEl.textContent = '';
                        if (content) {
                            content.innerHTML = '<div class="search-result-empty" style="text-align:center;padding:40px 20px;color:#999;">未找到相关资源</div>';
                        }
                    }
                    return;
                }

                try {
                    var data = JSON.parse(event.data);
                    // 服务器返回的是直接的资源对象 {title,url,is_type,...}，无 code/data 包装
                    if (data && data.title) {
                        var currentIndex = searchResults.length;
                        searchResults.push(data);
                        if (currentIndex < maxResults + localCount) {
                            if (currentIndex === 0 || (localCount === 0 && currentIndex === 0)) {
                                if (content) content.innerHTML = '<div class="search-result-list"></div>';
                            }
                            appendSearchResult(data, currentIndex);
                            var webCount = currentIndex + 1 - localCount;
                            var localText = localCount > 0 ? '本地' + localCount + '条 + ' : '';
                            if (countEl) countEl.textContent = '已找到 ' + localText + '全网' + webCount + ' 条';
                        }
                    }
                } catch (e) {
                    // 静默忽略非 JSON 数据（如 "线路：xxx"）
                }
            };

            currentEventSource.onerror = function () {
                if (isClosed) return;
                isClosed = true;
                if (currentEventSource) {
                    try { currentEventSource.close(); } catch (e) {}
                    currentEventSource = null;
                }
                if (searchResults.length === 0) {
                    if (content) {
                        content.innerHTML = '<div class="search-result-empty" style="text-align:center;padding:40px 20px;color:#999;">未找到相关资源</div>';
                    }
                }
                if (countEl && searchResults.length > 0) {
                    var localText = localCount > 0 ? '（含' + localCount + '条本地）' : '';
                    countEl.textContent = '共 ' + searchResults.length + ' 条结果' + localText;
                }
                // 出错时若已有结果，也触发链接检测
                if (searchResults.length > 0) {
                    setTimeout(function () { batchCheckLinks(); }, 500);
                }
            };

            // 检查 SSE 是否完成（部分服务器会发 complete 事件）
            currentEventSource.addEventListener('complete', function () {
                if (isClosed) return;
                isClosed = true;
                if (currentEventSource) {
                    try { currentEventSource.close(); } catch (e) {}
                    currentEventSource = null;
                }
                if (searchResults.length > 0) {
                    var localText = localCount > 0 ? '（含' + localCount + '条本地）' : '';
                    if (countEl) countEl.textContent = '共 ' + searchResults.length + ' 条结果' + localText;
                    setTimeout(function () { batchCheckLinks(); }, 500);
                } else {
                    if (countEl) countEl.textContent = '';
                    if (content) {
                        content.innerHTML = '<div class="search-result-empty" style="text-align:center;padding:40px 20px;color:#999;">未找到相关资源</div>';
                    }
                }
            });

        } catch (e) {
            if (content) {
                content.innerHTML = '<div class="search-result-empty" style="text-align:center;padding:40px 20px;color:#999;">搜索失败，请重试</div>';
            }
        }
    }

    // 打开资源弹窗
    function openResource(item) {
        if (!item || !item.url) return;

        // 未注册 Vue 引用，回退直接打开链接
        if (!vueRefs) {
            window.open(item.url, '_blank');
            return;
        }

        // 增加浏览量
        if (item.id) {
            try {
                axios.post('/api/search/incrementViews', { id: item.id }).catch(function () {});
            } catch (e) {}
        }

        vueRefs.dialogLoading.value = true;
        vueRefs.dialogUrl.value = true;
        vueRefs.dialogItem.value = {};

        var rawUrl = item.url || '';

        if (rawUrl.startsWith('http') || rawUrl.startsWith('magnet:')) {
            item.showUrl = rawUrl;
            showResourceDialog(item);
        } else {
            axios.post('/api/other/save_url', {
                url: encodeURIComponent(rawUrl),
                title: item.title
            }).then(function (res) {
                if (res.data.code == 200) {
                    item.url = res.data.data.url;
                    item.showUrl = res.data.data.url;
                } else {
                    item.showUrl = '';
                    item.message = res.data.message || '获取失败';
                }
                showResourceDialog(item);
            }).catch(function () {
                item.showUrl = '';
                item.message = '网络错误，请重试';
                showResourceDialog(item);
            });
        }
    }

    // 获取资源 - 若页面已注册 Vue 引用则打开弹窗，否则回退直接打开链接
    function getResource(event, index) {
        if (event && event.stopPropagation) {
            event.stopPropagation();
        }
        openResource(searchResults[index]);
    }

    // 显示资源弹窗并生成二维码
    function showResourceDialog(item) {
        if (!vueRefs) return;
        if (typeof vueRefs.onShowResource === 'function') {
            vueRefs.onShowResource(item);
        }
        vueRefs.dialogLoading.value = false;

        if (item.showUrl && item.showUrl.startsWith('magnet:')) {
            item.thunderUrl = 'thunder://' + btoa('AA' + item.showUrl + 'ZZ');
        }

        vueRefs.dialogItem.value = item;

        if (item.showUrl) {
            Vue.nextTick(function () {
                setTimeout(function () {
                    generateQrcode(item.showUrl);
                }, 300);
            });
        }
    }

    // 动态加载 qrcanvas 并生成二维码
    function generateQrcode(data) {
        var el = document.getElementById('qrcode');
        if (!el) {
            setTimeout(function () {
                var el2 = document.getElementById('qrcode');
                if (el2) doGenerateQrcode(el2, data);
            }, 300);
            return;
        }
        doGenerateQrcode(el, data);
    }

    function doGenerateQrcode(el, data) {
        el.innerHTML = '';
        if (typeof qrcanvas !== 'undefined' && qrcanvas.qrcanvas) {
            var canvas = qrcanvas.qrcanvas({ data: data, size: 120 });
            el.appendChild(canvas);
            return;
        }
        // 动态加载 qrcanvas 库
        if (!qrcanvasLoading) {
            qrcanvasLoading = true;
            var script = document.createElement('script');
            script.src = '/views/index/simple/common/static/js/qrcanvas@3.js';
            script.onload = function () {
                qrcanvasLoading = false;
                if (typeof qrcanvas !== 'undefined' && qrcanvas.qrcanvas) {
                    el.innerHTML = '';
                    var canvas = qrcanvas.qrcanvas({ data: data, size: 120 });
                    el.appendChild(canvas);
                }
            };
            document.head.appendChild(script);
        }
    }

    // 停止搜索
    function stopSearch() {
        stopLinkChecking();
        if (currentEventSource) {
            try { currentEventSource.close(); } catch (e) {}
            currentEventSource = null;
        }
    }

    // 暴露接口
    window.searchModule = {
        doSearch: doSearch,
        getResource: getResource,
        stopSearch: stopSearch,
        getResults: function () { return searchResults; },
        createDialogState: createDialogState,
        openResource: openResource,
        showResourceDialog: showResourceDialog,
        // 注册 Vue 响应式变量，启用资源弹窗模式
        setupResourceDialog: function (refs) { vueRefs = refs; },
        // 暴露链接检测接口，方便手动触发或与首页逻辑兼容
        batchCheckLinks: batchCheckLinks,
        stopLinkChecking: stopLinkChecking,
        // 搜索结果按网盘类型过滤
        setResultFilter: setResultFilter,
        getResultFilter: function () { return currentResultFilter; }
    };
})();
