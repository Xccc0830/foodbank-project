<?php
/**
 * 每個分頁各自一份登入狀態：登入憑證（分頁代碼）放在網址 _t / 表單 _t / 標頭 X-Tab-Token，
 * 不使用會被所有分頁共用的 Cookie。由伺服器決定正確代碼，這裡負責讓連結、表單與 fetch 都帶上它。
 */
?>
    <meta name="tab-token" content="<?php echo htmlspecialchars(session_id(), ENT_QUOTES, 'UTF-8'); ?>">
    <script>
    (function () {
        var meta = document.querySelector('meta[name="tab-token"]');
        var token = meta ? meta.content : '';
        if (!token) { return; }
        var stored = '';
        try { stored = sessionStorage.getItem('tabToken') || ''; } catch (e) {}
        var url = new URL(window.location.href);
        var inUrl = url.searchParams.get('_t');

        // 網址沒帶分頁代碼，但這個分頁之前登入過：帶著原本的代碼重新載入。
        if (!inUrl && stored && stored !== token && window.history.length >= 0) {
            url.searchParams.set('_t', stored);
            window.location.replace(url.toString());
            return;
        }
        if (inUrl !== token) {
            url.searchParams.set('_t', token);
            window.history.replaceState(null, '', url.toString());
        }
        try { sessionStorage.setItem('tabToken', token); } catch (e) {}

        function withToken(value) {
            try {
                var target = new URL(value, window.location.href);
                if (target.origin !== window.location.origin || target.searchParams.has('_t')) { return value; }
                if (/\.(css|js|png|jpe?g|webp|gif|svg|ico)$/i.test(target.pathname)) { return value; }
                target.searchParams.set('_t', token);
                return target.toString();
            } catch (e) { return value; }
        }

        var nativeFetch = window.fetch;
        if (nativeFetch) {
            window.fetch = function (input, init) {
                try {
                    var target = new URL(typeof input === 'string' ? input : input.url, window.location.href);
                    if (target.origin === window.location.origin) {
                        init = init || {};
                        var headers = new Headers(init.headers || (typeof input !== 'string' ? input.headers : undefined));
                        headers.set('X-Tab-Token', token);
                        init.headers = headers;
                    }
                } catch (e) {}
                return nativeFetch.call(window, input, init);
            };
        }

        function decorateLink(event) {
            var link = event.target.closest && event.target.closest('a[href]');
            if (!link || link.getAttribute('href').charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(link.getAttribute('href'))) { return; }
            link.href = withToken(link.href);
            if (/[?&]action=logout(&|$)/.test(link.search)) {
                try { sessionStorage.removeItem('tabToken'); } catch (e) {}
            }
        }
        document.addEventListener('click', decorateLink, true);
        document.addEventListener('auxclick', decorateLink, true);
        function addTokenField(form) {
            if (!form || form.tagName !== 'FORM' || form.querySelector('input[name="_t"]')) { return; }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_t';
            input.value = token;
            form.appendChild(input);
        }
        document.addEventListener('submit', function (event) { addTokenField(event.target); }, true);
        // 程式直接呼叫 form.submit() 不會觸發 submit 事件（例如登入頁的測試帳號快捷按鈕），所以也要補上
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            addTokenField(this);
            return nativeSubmit.call(this);
        };
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('img[src^="?"], iframe[src^="?"]').forEach(function (el) {
                el.setAttribute('src', withToken(el.getAttribute('src')));
            });
        });
    })();
    </script>
