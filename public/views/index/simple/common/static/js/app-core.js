/**
 * 核心公共逻辑 - 导航 active 状态、联系弹窗
 * 在所有页面共享，不依赖 Vue
 */

// 动态设置导航 active 状态（兼容后台自定义链接）
function setNavActive() {
    var currentPath = window.location.pathname;
    document.querySelectorAll('.top-nav-menu a').forEach(function(link) {
        var href = link.getAttribute('href') || '';
        if (href === currentPath || (href !== '/' && currentPath.indexOf(href) === 0)) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
    document.querySelectorAll('.nav-drawer-menu a').forEach(function(link) {
        var href = link.getAttribute('href') || '';
        if (href === currentPath || (href !== '/' && currentPath.indexOf(href) === 0)) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
}

// 页面加载完成后初始化
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setNavActive);
} else {
    setNavActive();
}

// 全局显示联系我们弹窗（供后台自定义导航调用）
function showQcode() {
    var appEl = document.querySelector('#app');
    if (appEl && appEl.__vue_app__) {
        var vm = appEl.__vue_app__._instance;
        if (vm && vm.setupState && typeof vm.setupState.qcodeVisible !== 'undefined') {
            vm.setupState.qcodeVisible = true;
            return;
        }
        if (vm && vm.ctx && typeof vm.ctx.qcodeVisible !== 'undefined') {
            vm.ctx.qcodeVisible = true;
            return;
        }
    }
    var overlays = document.querySelectorAll('.el-overlay');
    for (var i = 0; i < overlays.length; i++) {
        var dialog = overlays[i].querySelector('.qrcode-dialog, .el-dialog');
        if (dialog || overlays[i].innerHTML.includes('联系我们')) {
            overlays[i].style.display = 'block';
            return;
        }
    }
}
