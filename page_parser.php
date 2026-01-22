<?php
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$scriptFullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
$targetUrl = $_POST['target_url'] ?? '';

// Логика прокси-сервера для "Импорта с сайта клиента"
if ($targetUrl !== '' && filter_var($targetUrl, FILTER_VALIDATE_URL)) {

    $jsSafeTargetUrl = json_encode($targetUrl);
    
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n", 'follow_location' => true, 'timeout' => 15, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    
    $html = @file_get_contents($targetUrl, false, $context);
    
    if ($html === false) {
        http_response_code(502);
        echo "<h1>Не удалось загрузить страницу</h1><p>Сайт может быть защищен или недоступен.</p>";
        echo '<p><a href="' . htmlspecialchars($scriptFullUrl) . '" style="color:#88aaff;">← Вернуться</a></p>';
        exit;
    }
    
    $encoding = mb_detect_encoding($html, ['UTF-8', 'Windows-1251'], true) ?: 'UTF-8';
    $html = mb_convert_encoding($html, 'UTF-8', $encoding);

    // Внедряемый JS-код инспектора
    $inject = <<<JS
<script>
(function(){
    let lastHighlighted = null;
    let selectionPanel = null;
    let lastMoveTime = 0;
    const THROTTLE_DELAY = 50;

    function getTargetElement_parser(e) {
        let el = e.target;
        while (el && el.nodeType !== 1 && el !== document.body) {
            el = el.parentNode || el.parentElement;
        }
        if (el === document.body || el === document.documentElement) return null;
        return el;
    }

    function throttledHighlight_parser(e) {
        const now = Date.now();
        if (now - lastMoveTime < THROTTLE_DELAY) return;
        lastMoveTime = now;
        if (selectionPanel && selectionPanel.contains(e.target)) return;
        const el = getTargetElement_parser(e);
        if (!el) {
            if (lastHighlighted) resetHighlight_parser(lastHighlighted);
            return;
        }
        if (lastHighlighted && lastHighlighted !== el) {
            resetHighlight_parser(lastHighlighted);
        }
        highlightElement_parser(el);
    }

    function highlightElement_parser(el) {
        if (!el || el.nodeType !== 1) return;
        const style = el.style;
        if (!el.dataset.saved) {
            el.dataset.saved = 'true';
            el.dataset.origOutline = style.outline || '';
            el.dataset.origOutlineOffset = style.outlineOffset || '';
            el.dataset.origBoxShadow = style.boxShadow || '';
            el.dataset.origTransition = style.transition || '';
            const inlineBgColor = style.backgroundColor;
            el.dataset.origBackgroundColor = (inlineBgColor && inlineBgColor !== '') ? inlineBgColor : '';
        }
        el.style.outline = '4px solid #00ff55';
        el.style.outlineOffset = '2px';
        el.style.backgroundColor = 'rgba(0, 255, 85, 0.22)';
        el.style.boxShadow = 'inset 0 0 18px rgba(0, 255, 85, 0.55), 0 0 14px rgba(0, 255, 85, 0.4)';
        el.style.transition = 'all 0.14s ease';
        lastHighlighted = el;
    }

    function resetHighlight_parser(el) {
        if (!el || el.nodeType !== 1) return;
        const style = el.style;
        if (el.dataset.saved) {
            style.outline = el.dataset.origOutline || '';
            style.outlineOffset = el.dataset.origOutlineOffset || '';
            style.backgroundColor = el.dataset.origBackgroundColor || '';
            style.boxShadow = el.dataset.origBoxShadow || '';
            style.transition = el.dataset.origTransition || '';
            ['origOutline', 'origOutlineOffset', 'origBackgroundColor', 'origBoxShadow', 'origTransition', 'saved'].forEach(prop => {
                delete el.dataset[prop];
            });
        }
        if (lastHighlighted === el) {
            lastHighlighted = null;
        }
    }

    function sendToN8n_parser(el) {
        const typeSelect = document.getElementById('info-type');
        if (!typeSelect || !typeSelect.value || typeSelect.value === '') {
            alert('Пожалуйста, выберите тип информации перед отправкой.');
            return;
        }
        const type = typeSelect.value;
        const payload = {
            html: el.outerHTML,
            tag: el.tagName,
            classes: el.className.trim() || null,
            id: el.id || null,
            url: $jsSafeTargetUrl,
            n8n_url_type: type
        };

        try {
            sessionStorage.setItem('n8n_importer_payload', JSON.stringify(payload));
            sessionStorage.setItem('n8n_importer_status', 'pending');
            window.open('$scriptFullUrl#from-site', '_blank');

            if (selectionPanel) {
                selectionPanel.innerHTML = '<div style="padding:20px; text-align:center; color:#9f9;">Результат откроется в новой вкладке...</div>';
                setTimeout(() => { if(selectionPanel) selectionPanel.remove(); }, 3000);
            }
        } catch (e) {
            alert('Ошибка сохранения данных: ' + e.message);
        }
    }
    
    function createSelectionPanel_parser(target) {
        const targetEl = getTargetElement_parser({ target: target }) || target;
        if (selectionPanel) selectionPanel.remove();
        
        selectionPanel = document.createElement('div');
        selectionPanel.id = 'importer-panel';
        Object.assign(selectionPanel.style, {
            position: 'fixed', bottom: '20px', right: '20px',
            background: 'rgba(20,20,30,0.96)', color: '#e0e0ff',
            padding: '20px 20px', borderRadius: '10px',
            fontFamily: 'Consolas, monospace', fontSize: '13px',
            zIndex: '2147483647', maxWidth: '92vw', maxHeight: '45vh',
            overflowX: 'auto', overflowY: 'auto', whiteSpace: 'nowrap',
            boxShadow: '0 6px 24px rgba(0,0,0,0.7)', border: '1px solid #555',
            pointerEvents: 'auto', minWidth: '380px'
        });

        const title = document.createElement('div');
        title.textContent = 'Выберите элемент для отправки';
        Object.assign(title.style, { marginBottom: '16px', fontWeight: 'bold', color: '#88ff88', fontSize: '1.1em' });
        selectionPanel.appendChild(title);

        // Более явная кнопка выхода (под заголовком, справа)
        const buttonContainer = document.createElement('div');
        Object.assign(buttonContainer.style, { 
            textAlign: 'right', marginBottom: '12px', 
            display: 'flex', justifyContent: 'flex-end', alignItems: 'center', gap: '10px' 
        });
        const exitBtn = document.createElement('button');
        exitBtn.textContent = 'Выйти из сайта';
        Object.assign(exitBtn.style, { 
            background: 'rgba(100,100,140,0.8)', color: '#e0e0ff', 
            border: '1px solid #555', borderRadius: '4px', padding: '6px 12px', 
            fontSize: '14px', fontWeight: 'bold', cursor: 'pointer', 
            transition: 'background 0.2s, color 0.2s'
        });
        exitBtn.addEventListener('mouseenter', () => { 
            exitBtn.style.background = 'rgba(120,120,160,0.9)'; 
            exitBtn.style.color = '#ffffff'; 
        });
        exitBtn.addEventListener('mouseleave', () => { 
            exitBtn.style.background = 'rgba(100,100,140,0.8)'; 
            exitBtn.style.color = '#e0e0ff'; 
        });
        exitBtn.addEventListener('click', (e) => { 
            e.stopPropagation(); 
            if (lastHighlighted) resetHighlight_parser(lastHighlighted);
            if (selectionPanel) selectionPanel.remove();
            selectionPanel = null;
            window.location.href = '$scriptFullUrl';
        });
        buttonContainer.appendChild(exitBtn);
        selectionPanel.appendChild(buttonContainer);

        const typeContainer = document.createElement('div');
        typeContainer.style.marginBottom = '12px';
        const typeLabel = document.createElement('label');
        typeLabel.textContent = 'Тип информации: ';
        Object.assign(typeLabel.style, { display: 'flex', alignItems: 'center', gap: '8px' });
        const typeSelect = document.createElement('select');
        typeSelect.id = 'info-type';
        Object.assign(typeSelect.style, { background: 'rgba(60,60,80,0.8)', color: '#e0e0ff', border: '1px solid #555', borderRadius: '4px', padding: '4px 8px' });
        typeSelect.innerHTML = '<option value="">-- Выберите тип --</option><option value="dynamic">Динамическая</option><option value="static">Статичная</option>';
        typeLabel.appendChild(typeSelect);
        typeContainer.appendChild(typeLabel);
        selectionPanel.appendChild(typeContainer);

        const chainContainer = document.createElement('div');
        Object.assign(chainContainer.style, { display: 'inline-flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' });
        
        let current = targetEl; const elements = [];
        while (current && current !== document.body && current !== document.documentElement) { elements.push(current); current = current.parentElement; }
        elements.reverse();
        
        elements.forEach((el, idx) => {
            const itemWrapper = document.createElement('div');
            Object.assign(itemWrapper.style, { display: 'inline-flex', alignItems: 'center', gap: '4px' });
            
            const item = document.createElement('div');
            Object.assign(item.style, { padding: '6px 10px', background: 'rgba(60,60,80,0.6)', borderRadius: '4px', cursor: 'pointer', border: '1px solid #555', transition: 'all 0.12s' });
            let label = el.tagName.toLowerCase();
            if (el.id) label += '#' + el.id;
            if (el.className) { const classes = String(el.className).trim().split(/\\s+/).slice(0,3).join('.'); if (classes) label += '.' + classes; }
            if (label.length > 45) label = label.substring(0,42) + '…';
            item.textContent = label;
            
            item.addEventListener('mouseenter', () => { highlightElement_parser(el); item.style.background = 'rgba(80,140,80,0.7)'; item.style.borderColor = '#0f0'; });
            item.addEventListener('mouseleave', () => { resetHighlight_parser(el); item.style.background = 'rgba(60,60,80,0.6)'; item.style.borderColor = '#555'; });
            item.addEventListener('click', (e) => { e.stopPropagation(); const fullHTML = el.outerHTML; if (navigator.clipboard?.writeText) { navigator.clipboard.writeText(fullHTML).then(() => alert('HTML скопирован в буфер.')).catch(() => alert('Ошибка копирования.')); } else { alert('HTML: ' + fullHTML); } });
            itemWrapper.appendChild(item);
            
            const sendBtn = document.createElement('div');
            sendBtn.textContent = '→ n8n';
            Object.assign(sendBtn.style, { padding: '6px 10px', background: 'rgba(40,100,40,0.7)', borderRadius: '4px', cursor: 'pointer', border: '1px solid #2a5', color: '#9f9', fontWeight: 'bold', transition: 'all 0.12s' });
            sendBtn.addEventListener('mouseenter', () => { sendBtn.style.background = 'rgba(60,140,60,0.9)'; sendBtn.style.borderColor = '#0f0'; });
            sendBtn.addEventListener('mouseleave', () => { sendBtn.style.background = 'rgba(40,100,40,0.7)'; sendBtn.style.borderColor = '#2a5'; });
            sendBtn.addEventListener('click', (e) => { e.stopPropagation(); sendToN8n_parser(el); });
            itemWrapper.appendChild(sendBtn);
            
            chainContainer.appendChild(itemWrapper);
            if (idx < elements.length - 1) { const sep = document.createElement('span'); sep.textContent = '>'; Object.assign(sep.style, { color: '#777', padding: '0 4px' }); chainContainer.appendChild(sep); }
        });
        selectionPanel.appendChild(chainContainer);

        const closeBtn = document.createElement('div');
        closeBtn.textContent = '✕';
        Object.assign(closeBtn.style, { position: 'absolute', top: '8px', right: '12px', cursor: 'pointer', fontSize: '18px', color: '#ff8888', padding: '0 5px' });
        closeBtn.addEventListener('click', () => { selectionPanel.remove(); selectionPanel = null; resetHighlight_parser(lastHighlighted); });
        selectionPanel.appendChild(closeBtn);

        selectionPanel.addEventListener('mouseenter', () => {
            if (lastHighlighted) {
                resetHighlight_parser(lastHighlighted);
            }
        });

        document.body.appendChild(selectionPanel);
    }

    document.addEventListener('mousemove', throttledHighlight_parser, true);
    document.addEventListener('click', function(e) { if (selectionPanel && selectionPanel.contains(e.target)) return; e.preventDefault(); e.stopPropagation(); const el = getTargetElement_parser(e); if (el) createSelectionPanel_parser(el); }, true);
    document.addEventListener('mouseleave', function() { if (lastHighlighted) { resetHighlight_parser(lastHighlighted); } }, false);
})();
</script>
JS;

    if (preg_match('#</body\b#i', $html)) {
        $html = preg_replace('#(</body\b[^>]*>)#i', $inject . "\n$1", $html, 1);
    } else {
        $html .= "\n" . $inject;
    }

    $base = htmlspecialchars(parse_url($targetUrl, PHP_URL_SCHEME) . '://' . parse_url($targetUrl, PHP_URL_HOST) . '/');
    if (preg_match('#<head\b#i', $html)) {
        $html = preg_replace('#(<head\b[^>]*>)#i', "$1\n<base href=\"$base\">", $html, 1);
    } else {
        $html = "<head><base href=\"$base\"></head>" . $html;
    }
    
    echo $html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Инструменты импорта</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/vanish0077/n8n@f632e15/styles_parser.css">    
</head>
<body>

<div class="main-content" style="margin-left:0; max-width: 900px; margin: 2rem auto;">
    <div class="app-container">
        <header><h1>Инструменты импорта</h1></header>

        <div class="tab-buttons">
            <button class="tab-btn active" data-tab="from-site">Импорт с сайта клиента</button>
            <button class="tab-btn" data-tab="from-file">Импорт из файла (PDF/TXT)</button>
        </div>
        <div class="tab-content">
            <div id="tab-from-site" class="tab-panel active">
                <form method="post" action="">
                    <input type="url" name="target_url" placeholder="https://example.com" required autofocus>
                    <button type="submit">Подключиться</button>
                </form>
                <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $targetUrl === ''): ?>
                    <div class="error" style="text-align:center; margin-top:15px;">Введите корректный URL</div>
                <?php endif; ?>
                
                <div id="site-process-area" style="display:none; margin-top: 20px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
                    <div id="site-progress-bar">
                        <div style="font-weight:bold; color:#2563eb; margin-bottom:10px; text-align:center;">Обработка в n8n...</div>
                        <div class="loading-bar-animation"></div>
                    </div>
                    <div id="site-file-result" style="display:none; text-align:center;"></div>
                </div>
            </div>

            <!-- ВКЛАДКА 2: ИМПОРТ ИЗ ФАЙЛА -->
            <div id="tab-from-file" class="tab-panel">
                 <p style="text-align:center;color:#666;margin-bottom:2rem">Отправьте PDF/TXT файл и получите ZIP архив с JSON и изображениями для Битрикс.</p>
                <form id="file-webhook-form" action="https://n8n.takfit.ru/webhook-test/content-to-bitrix" method="POST" enctype="multipart/form-data">
                    <div id="file-group">
                        <label for="content_file">Файл (PDF или TXT):</label>
                        <input type="file" name="content" id="content_file" accept=".pdf,.txt" required>
                    </div>
                    <div style="text-align:center; margin-top:2rem">
                        <button type="submit" id="file-submit-btn">Отправить и получить ZIP</button>
                    </div>
                </form>
                <div id="file-process-area" style="display:none; margin-top: 20px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;">
                     <div id="file-progress-bar">
                        <div style="font-weight:bold; color:#2563eb; margin-bottom:10px; text-align:center;">Обработка файла...</div>
                        <div class="loading-bar-animation"></div>
                    </div>
                    <div id="file-result-area" style="display:none; text-align:center;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// --- ОБЩАЯ ЛОГИКА ---
document.addEventListener('DOMContentLoaded', () => {
    initTabs_parser();
    initSiteImport_parser();
    initFileImport_parser();

    const hash = window.location.hash.substring(1);
    if (hash) {
        const targetTabButton = document.querySelector(`.tab-btn[data-tab="${hash}"]`);
        if (targetTabButton) {
            targetTabButton.click();
        }
    }
});

function initTabs_parser() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanels.forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(`tab-${btn.dataset.tab}`).classList.add('active');
            history.pushState(null, null, '#' + btn.dataset.tab);
        });
    });
}

// --- ЛОГИКА ВКЛАДКИ 1: ИМПОРТ С САЙТА ---
function initSiteImport_parser(){
    const processArea = document.getElementById('site-process-area');
    const progressBar = document.getElementById('site-progress-bar');
    const resultArea = document.getElementById('site-file-result');

    const status = sessionStorage.getItem('n8n_importer_status');
    const payloadStr = sessionStorage.getItem('n8n_importer_payload');

    if (status === 'pending' && payloadStr) {
        sessionStorage.removeItem('n8n_importer_status');
        sessionStorage.removeItem('n8n_importer_payload');

        const payload = JSON.parse(payloadStr);
        let url = payload.n8n_url_type === 'dynamic' 
            ? 'https://n8n.takfit.ru/webhook-test/content-to-banner' 
            : 'https://n8n.takfit.ru/webhook-test/content-to-bitrix';
        
        // Добавляем type = 'url' только для статичной страницы (аналогично полю url)
        if (payload.n8n_url_type === 'static') {
            payload.type = 'url';
        }
        delete payload.n8n_url_type;
        
        processArea.style.display = 'block';
        progressBar.style.display = 'block';
        resultArea.style.display = 'none';

        fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.blob();
        })
        .then(blob => {
            if (blob.size < 100) throw new Error('n8n вернул пустой или некорректный архив.');
            
            const downloadUrl = URL.createObjectURL(blob);
            const name = 'element_export.zip';
            progressBar.style.display = 'none';
            resultArea.innerHTML = renderFileCard_parser(name, downloadUrl);
            resultArea.style.display = 'block';
        })
        .catch(err => {
            progressBar.style.display = 'none';
            resultArea.innerHTML = `<div class="error-message"><strong>Ошибка:</strong> ${err.message}</div>`;
            resultArea.style.display = 'block';
        });
    }
}

// --- ЛОГИКА ВКЛАДКИ 2: ИМПОРТ ИЗ ФАЙЛА ---
function initFileImport_parser() {
    const form = document.getElementById('file-webhook-form');
    if (!form) return;

    const btn = document.getElementById('file-submit-btn');
    const processArea = document.getElementById('file-process-area');
    const progressBar = document.getElementById('file-progress-bar');
    const resultArea = document.getElementById('file-result-area');
     
    form.onsubmit = async e => {
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = 'Обработка...';
        processArea.style.display = 'block';
        progressBar.style.display = 'block';
        resultArea.innerHTML = '';
        
        try {
            const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/zip' } });
            if (!res.ok) throw new Error(`Ошибка ${res.status} (n8n может быть недоступен)`);
            
            const blob = await res.blob();
            if (blob.size < 100) throw new Error('n8n вернул пустой или некорректный архив.');
            
            let name = 'bitrix_content.zip';
            const disp = res.headers.get('Content-Disposition');
            if (disp && disp.includes('filename=')) {
                name = disp.split('filename=')[1].replace(/["']/g, '');
            }
            const url = URL.createObjectURL(blob);
            
            progressBar.style.display = 'none';
            resultArea.innerHTML = renderFileCard_parser(name, url);
            resultArea.style.display = 'block';
        } catch (err) {
            progressBar.style.display = 'none';
            resultArea.innerHTML = `<div class="error-message"><strong>Ошибка:</strong> ${err.message}</div>`;
            resultArea.style.display = 'block';
        } finally {
            btn.disabled = false;
            btn.textContent = 'Отправить и получить ZIP';
        }
    };
}

// --- ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ РЕНДЕРА КАРТОЧКИ ФАЙЛА ---
function renderFileCard_parser(name, url) {
    return `<div class="file-card" style="justify-content:center; border:none; background:transparent;">
        <div class="file-icon">📦</div>
        <div class="file-info">
            <div class="file-name" style="margin-bottom: 12px; font-weight: bold; font-size:1.1em;">${name}</div>
            <a href="${url}" download="${name}" class="download-btn" style="background:#10b981;">Скачать архив</a>
        </div>
    </div>`;
}
</script>
</body>
</html>