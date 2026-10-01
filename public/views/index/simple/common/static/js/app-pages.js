/**
 * 公共页面函数 - 在 list/detail/category 等页面共享
 * 依赖: axios, qrcanvas, app (Vue 实例)
 */

// 安全跳转（避免 referrer 泄露）
function safeJump(url, target = '_blank') {
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noreferrer';
    a.target = target;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// 迅雷下载磁力链接
function thunderDownload(magnetUrl) {
    if (!magnetUrl || !magnetUrl.startsWith('magnet:')) {
        app.showMessage('无效的磁力链接', 'error');
        return;
    }
    var thunderUrl = 'thunder://' + btoa('AA' + magnetUrl + 'ZZ');
    safeJump(thunderUrl);
}

// 显示资源弹窗和二维码
function showUrlFun(item) {
    if (item.showUrl && item.showUrl.startsWith('magnet:')) {
        item.thunderUrl = 'thunder://' + btoa('AA' + item.showUrl + 'ZZ');
    }
    app.dialogItem = item;
    if (item.showUrl) {
        var canvas = qrcanvas.qrcanvas({
            data: item.showUrl,
            size: 120
        });
        setTimeout(function() {
            var el = document.getElementById('qrcode');
            if (el) el.appendChild(canvas);
        }, 200);
    }
}
