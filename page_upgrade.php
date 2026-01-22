<?php
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    if ($action === 'replace_file') {
        ob_start();
        header('Content-Type: application/json');

        function sendJson_upgrade($status, $data) {
            ob_end_clean();
            $response = ['status' => $status];
            $response['message'] = $data;
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $relPath = $_POST['path'] ?? '';
        $content = $_POST['content'] ?? '';

        if (empty($relPath) || !isset($content)) {
            sendJson_upgrade('error', 'Отсутствует путь или содержимое файла.');
        }

        $root = $_SERVER['DOCUMENT_ROOT'];
        if (strpos($relPath, '..') !== false || $relPath[0] !== '/') {
            sendJson_upgrade('error', 'Недопустимый формат пути.');
        }
        
        $fullPath = $root . $relPath;
        $realFullPath = realpath($fullPath);
        
        if ($realFullPath === false || strpos($realFullPath, realpath($root)) !== 0) {
            sendJson_upgrade('error', 'Попытка доступа за пределы корневой директории.');
        }

        if (!file_exists($fullPath) || is_dir($fullPath)) {
            sendJson_upgrade('error', 'Файл не существует или является директорией.');
        }
        if (!is_writable($fullPath) || !is_writable(dirname($fullPath))) {
             sendJson_upgrade('error', 'Файл или его директория не доступны для записи. Проверьте права.');
        }
        
        $self_filename = basename(__FILE__);
        $forbidden_files = ['access.php', 'robots.php', 'sitemap.php', '.htaccess', 'urlrewrite.php', $self_filename];
        if (in_array(basename($fullPath), $forbidden_files, true)) {
            sendJson_upgrade('error', 'Этот системный файл защищен от перезаписи.');
        }

        try {
            $dateSuffix = date('dmY');
            $backupPathBase = $fullPath . '_back' . $dateSuffix;
            $backupPath = $backupPathBase;
            $counter = 1;
            while (file_exists($backupPath)) {
                $backupPath = $backupPathBase . '_' . $counter;
                $counter++;
            }

            if (!copy($fullPath, $backupPath)) {
                 sendJson_upgrade('error', 'Не удалось создать резервную копию файла. Замена отменена.');
            }

            if (file_put_contents($fullPath, $content) === false) {
                sendJson_upgrade('error', 'Не удалось записать в файл. Резервная копия была создана: ' . htmlspecialchars(basename($backupPath)));
            }
            
            $successMessage = 'Файл ' . htmlspecialchars(basename($fullPath)) . ' успешно обновлен. <br>Создана резервная копия: <strong>' . htmlspecialchars(basename($backupPath)) . '</strong>';
            sendJson_upgrade('success', $successMessage);
        } catch (Exception $e) {
            sendJson_upgrade('error', 'Ошибка при замене файла: ' . $e->getMessage());
        }
    }
    // Создание временного файла для предпросмотра
    else if ($action === 'create_preview') {
        ob_start();
        header('Content-Type: application/json');

        $content = $_POST['content'] ?? '';
        if (empty($content)) {
            echo json_encode(['status' => 'error', 'message' => 'Нет содержимого для предпросмотра.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $previewFilename = 'preview_' . bin2hex(random_bytes(8)) . '.php';
        $root = $_SERVER['DOCUMENT_ROOT'];
        $fullPath = $root . '/' . $previewFilename;
        $deleteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

        $closePanel = <<<HTML
<div id="preview-panel" style="position:fixed;top:0;left:0;width:100%;background:rgba(25,35,50,0.95);color:white;padding:10px 20px;z-index:100000;display:flex;justify-content:space-between;align-items:center;font-family:Arial,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,0.5);border-bottom: 2px solid #0af;">
    <span style="font-size:16px;font-weight:bold;">Режим предпросмотра</span>
    <button id="close-preview-btn" style="background:#d32f2f;color:white;border:none;padding:8px 16px;border-radius:5px;cursor:pointer;font-weight:bold;transition:background 0.2s;">Закрыть</button>
</div>
<script>
    document.getElementById('close-preview-btn').addEventListener('click', function() {
        this.disabled = true; this.textContent = 'Удаление...';
        const fd = new FormData();
        fd.append('action', 'delete_preview');
        fd.append('file', '{$previewFilename}');
        fetch('{$deleteUrl}', { method: 'POST', body: fd, cache: 'no-cache' })
        .then(res => res.json()).then(() => { window.close(); if(!window.closed) alert('Предпросмотр завершен. Можете закрыть эту вкладку.'); })
        .catch(err => { console.error('Failed to delete preview file:', err); alert('Не удалось автоматически удалить временный файл.'); });
    });
</script>
HTML;

        $phpContent = <<<PHP
<?php require(\$_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
\$APPLICATION->SetTitle("Предпросмотр");
?>
{$content}
{$closePanel}
<?php require(\$_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
PHP;

        if (file_put_contents($fullPath, $phpContent) === false) {
             echo json_encode(['status' => 'error', 'message' => 'Не удалось создать временный файл на сервере.'], JSON_UNESCAPED_UNICODE);
             exit;
        }

        ob_end_clean();
        echo json_encode(['status' => 'success', 'url' => '/' . $previewFilename], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Удаление файла предпросмотра
    else if ($action === 'delete_preview') {
        header('Content-Type: application/json');
        $fileToDelete = $_POST['file'] ?? '';
        if (empty($fileToDelete) || !preg_match('/^preview_[a-f0-9]{16}\.php$/', $fileToDelete)) {
            echo json_encode(['status' => 'error', 'message' => 'Недопустимое имя файла.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $fileToDelete;
        if (file_exists($fullPath) && @unlink($fullPath)) {
            echo json_encode(['status' => 'success', 'message' => 'Файл удален.'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Не удалось удалить файл или он уже удален.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
   
    // Если ни один известный action не совпал
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Неподдерживаемый тип запроса'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Запрос на получение дерева файлов
if (isset($_GET['tree'])) {
    header('Content-Type: text/html; charset=utf-8');
    $root = $_SERVER['DOCUMENT_ROOT'];
    $excluded_dirs = ['bitrix', 'modules', 'upload', 'local', '.git', 'cgi-bin', 'personal', 'admin', 'include', 'css', 'js', 'vendor', 'ajax', 'aspro_regions', 'auth'];
    $excluded_files = ['access.php', 'page_generation.php','robots.php','sitemap.php', '.bottom.menu.php', '.bottom_company.menu.php', '.bottom_help.menu.php', '.bottom_info.menu.php', '.cabinet.menu.php', '.htaccess', 'import.php','.htaccess_back', 'indexblocks_index1.php' ,'.left.menu.php', '.only_catalog.menu.php', '.section.php', '.subtop_content_multilevel.menu.php', '.top.menu.php', '.top_catalog_sections.menu.php', '.top_catalog_sections.menu_ext.php', '.top_catalog_wide.menu.php', '.top_catalog_wide.menu_ext.php', '.top_content_multilevel.menu.php', '404.php', 'urlrewrite.php'];
  
    $html = '<div id="file-tree-root">';
  
    function renderFolderContent_upgrade($dirPath, $relBase, $level = 1) {
        global $excluded_dirs, $excluded_files;
        $items = @scandir($dirPath);
        if ($items === false) return '';
        $content = '';
        $hasFiles = false;
        $hasSubdirs = false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (is_dir($dirPath . '/' . $item) && in_array($item, $excluded_dirs)) continue;

            $fullPath = $dirPath . '/' . $item;
            if (!is_readable($fullPath)) continue;
            $relPath = rtrim($relBase, '/') . '/' . $item;
            if (is_dir($fullPath)) {
                $subContent = '';
                if ($level < 2) { 
                    $subContent = renderFolderContent_upgrade($fullPath, $relPath, $level + 1); 
                }
                $detailsContent = '<ul style="list-style-type: none; padding-left: 0;">';
                if (!empty($subContent)) {
                    $detailsContent .= $subContent;
                } else {
                    $detailsContent .= '<li style="margin:5px 0; padding-left: ' . (($level + 1) * 15) . 'px; color: #6b7280; font-style: italic; padding: 8px 0;">Нет файлов для обработки</li>';
                }
                $detailsContent .= '</ul>';
                $content .= '<details class="folder-details" style="padding-left: ' . ($level * 15) . 'px;"><summary class="folder-header"><strong>' . htmlspecialchars($item) . '/</strong></summary>' . $detailsContent . '</details>';
                $hasSubdirs = true;
            } else {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if ($ext !== 'php' || in_array($item, $excluded_files) || $item[0] === '.') continue;
                $hasFiles = true;
                $content .= '<li style="margin:5px 0; padding-left: ' . ($level * 15) . 'px;"><label style="display:flex; align-items:center; gap:8px; cursor:pointer;"><input type="checkbox" class="file-checkbox" value="' . htmlspecialchars($relPath, ENT_QUOTES) . '">' . htmlspecialchars($item) . '</label></li>';
            }
        }
        if (!$hasFiles && !$hasSubdirs) {
            $content .= '<li style="margin:5px 0; padding-left: ' . ($level * 15) . 'px; color: #6b7280; font-style: italic; padding: 8px 0;">Нет файлов для обработки</li>';
        }
        return $content;
    }

    $level1 = @scandir($root);
    if ($level1 === false) { echo 'Ошибка чтения корня'; exit; }
    $rootFiles = []; $rootDirs = [];
    foreach ($level1 as $item1) {
        if ($item1 === '.' || $item1 === '..') continue;
        $full1 = $root . '/' . $item1;
        if (!is_readable($full1)) continue;
        if (is_dir($full1)) { if (!in_array($item1, $excluded_dirs)) $rootDirs[] = $item1; }
        else { $ext = strtolower(pathinfo($item1, PATHINFO_EXTENSION)); if ($ext === 'php' && !in_array($item1, $excluded_files) && $item1[0] !== '.') { $rootFiles[] = $item1; } }
    }
    
    $html .= '<ul style="list-style-type: none; padding-left: 0;">';
    $rootContent = '';
    if (!empty($rootFiles)) {
        foreach ($rootFiles as $file) {
            $rel1 = '/' . $file;
            $rootContent .= '<li style="margin:8px 0"><label style="display:flex; align-items:center; gap:8px; cursor:pointer;"><input type="checkbox" class="file-checkbox" value="' . htmlspecialchars($rel1, ENT_QUOTES) . '">' . htmlspecialchars($file) . '</label></li>';
        }
    } else {
        $rootContent = '<li style="margin:8px 0; color: #6b7280; font-style: italic; padding: 8px 0;">Нет файлов для обработки</li>';
    }
    $html .= '<details open class="folder-details"><summary class="folder-header" style="color:#2563eb; margin-bottom:5px;"><strong>Корень сайта</strong></summary><ul style="padding-left:20px; margin-bottom:15px; list-style-type: none;">' . $rootContent . '</ul></details>';
    foreach ($rootDirs as $dirItem) {
        $folderContent = renderFolderContent_upgrade($root . '/' . $dirItem, '/' . $dirItem, 1);
        $html .= '<details class="folder-details-root"><summary class="folder-header"><strong>' . htmlspecialchars($dirItem) . '/</strong></summary><ul class="folder-content" style="padding-left:20px;margin:5px 0; list-style-type: none;">' . $folderContent . '</ul></details>';
    }
    $html .= '</ul></div>';
    echo $html;
    exit;
}

// Запрос на получение содержимого файла
if (isset($_GET['file'])) {
    $rel = $_GET['file'];
    $full = realpath($_SERVER['DOCUMENT_ROOT'] . $rel);
    if ($full === false || strpos($full, realpath($_SERVER['DOCUMENT_ROOT'])) !== 0 || !file_exists($full) || is_dir($full)) {
        http_response_code(404);
        echo 'File not found or access denied.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    readfile($full);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Улучшение кода</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/vanish0077/n8n@b4a1442/style_upgrade.css">
</head>
<body>

<div class="main-content">
    <div id="page-code-improve" class="page active">
        <div class="app-container">
            <header><h1>Улучшение кода</h1></header>
            <div id="settings-and-actions" style="margin: 20px 0; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                <div style="margin-bottom: 16px; font-weight: 500;">Настройки обработки:</div>
                <div class="settings-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px;">
                    <label class="option" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="opt_round_images">
                        <span>Закруглять углы изображений</span>
                    </label>
                    <label class="option" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="opt_no_style">
                        <span>Не добавлять стили</span>
                    </label>
                    <label class="option" style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="opt_fancybox">
                        <span>Подключать fancybox</span>
                    </label>               
                </div>
                <div id="multi-send-panel" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>Выбрано файлов: <span id="selected-count" style="color: #3b82f6;">0</span></div>
                    <div id="buttons-container" style="display: flex; gap: 10px; opacity: 0; pointer-events: none;"><button type="button" id="btn-select-all" class="btn-secondary">Выбрать все</button><button type="button" id="btn-deselect-all" class="btn-secondary">Снять выделение</button><button type="button" id="btn-process-selected" class="btn-green" disabled>Обработать выбранные</button></div>
                </div>
            </div>
            <div id="code-tree" style="margin-top:20px; background:#f8f9fa; padding:15px; border-radius:8px; max-height:60vh; overflow-y:auto"></div>
        </div>
    </div>
</div>

<div id="result-overlay" class="overlay">
    <div class="modal"><h3 id="modal-title"></h3><p id="modal-message"></p><button class="modal-close">Закрыть</button></div>
</div>

<script>
function initCodeImprove_upgrade(){
    const tree = document.getElementById('code-tree');
    tree.innerHTML = '<em>Загрузка структуры сайта...</em>';
   
    selectedFiles.clear();

    fetch('?tree=1')
        .then(r => r.text())
        .then(html => {
            tree.innerHTML = html;
            initMultiSelectLogic_upgrade();
        })
        .catch(() => {
            tree.innerHTML = '<div style="color:#c53030">Ошибка загрузки дерева файлов</div>';
        });
}

let selectedFiles = new Set();
function updateSelectionUI_upgrade() {
    const count = selectedFiles.size;
    document.getElementById('selected-count').textContent = count;
    document.getElementById('btn-process-selected').disabled = count === 0;
   
    const buttonsContainer = document.getElementById('buttons-container');
    if (buttonsContainer) {
        buttonsContainer.style.opacity = count > 0 ? '1' : '0';
        buttonsContainer.style.pointerEvents = count > 0 ? 'auto' : 'none';
    }
}

async function replaceFile_upgrade(relPath, newCode) {
    const fd = new FormData();
    fd.append('action', 'replace_file');
    fd.append('path', relPath);
    fd.append('content', newCode);

    const res = await fetch('', { method: 'POST', body: fd });
    if (!res.ok) {
        throw new Error(`Ошибка сервера: ${res.status}`);
    }

    const data = await res.json();
    if (data.status !== 'success') {
        throw new Error(data.message || 'Неизвестная ошибка на сервере.');
    }
    return data;
}

function createActionButtons_upgrade(listItem, relPath, newCode) {
    if (!listItem) return;

    const actionsContainer = document.createElement('div');
    actionsContainer.className = 'file-actions';

    const previewBtn = document.createElement('button');
    previewBtn.textContent = 'Предпросмотр';
    previewBtn.className = 'btn-secondary btn-small';
    previewBtn.type = 'button';
    addPreviewLogic_upgrade(previewBtn, newCode);

    const replaceBtn = document.createElement('button');
    replaceBtn.textContent = 'Заменить';
    replaceBtn.className = 'btn-green btn-small';
    replaceBtn.type = 'button';
    replaceBtn.onclick = async () => {
        if (!confirm(`Вы уверены, что хотите заменить файл "${relPath}" на сервере? Будет создана резервная копия.`)) return;

        replaceBtn.disabled = true;
        replaceBtn.textContent = 'Замена...';
        previewBtn.disabled = true;

        try {
            const result = await replaceFile_upgrade(relPath, newCode);
            actionsContainer.innerHTML = `<span style="color: #27ae60; font-weight: bold; font-size: 14px;">✔ Заменено</span>`;
            show_upgrade('Успех', result.message, true);
        } catch (err) {
            alert(`Ошибка замены файла: ${err.message}`);
            replaceBtn.disabled = false;
            replaceBtn.textContent = 'Заменить';
            previewBtn.disabled = false;
        }
    };
    
    actionsContainer.append(previewBtn, replaceBtn);
    listItem.append(actionsContainer);
}

function initMultiSelectLogic_upgrade() {
    const tree = document.getElementById('code-tree');
    tree.addEventListener('change', e => {
        if (!e.target.classList.contains('file-checkbox')) return;
        if (e.target.checked) { selectedFiles.add(e.target.value); } else { selectedFiles.delete(e.target.value); }
        updateSelectionUI_upgrade();
    });
    document.getElementById('btn-select-all')?.addEventListener('click', () => {
        document.querySelectorAll('.file-checkbox:not(:disabled)').forEach(chk => { chk.checked = true; selectedFiles.add(chk.value); });
        updateSelectionUI_upgrade();
    });
    document.getElementById('btn-deselect-all')?.addEventListener('click', () => {
        document.querySelectorAll('.file-checkbox').forEach(chk => chk.checked = false); selectedFiles.clear();
        updateSelectionUI_upgrade();
    });
    document.getElementById('btn-process-selected')?.addEventListener('click', async () => {
        if (selectedFiles.size === 0) return;
        const paths = [...selectedFiles];
        const total = paths.length;
        let successCount = 0;
        const errors = [];
        
        const processButton = document.getElementById('btn-process-selected');
        processButton.disabled = true;
        processButton.textContent = 'Обработка...';
        show_upgrade('Обработка', `Начинаем обработку ${total} файл(ов)...`, true);

        const settings = {
            round_images: document.getElementById('opt_round_images')?.checked ?? false,
            no_style: document.getElementById('opt_no_style')?.checked ?? false,
            fancybox: document.getElementById('opt_fancybox')?.checked ?? false,
            d7_only: document.getElementById('opt_d7_only')?.checked ?? false,
            minify: document.getElementById('opt_minify')?.checked ?? false
        };

        for (const relPath of paths) {
            const checkbox = document.querySelector(`.file-checkbox[value="${CSS.escape(relPath)}"]`);
            const label = checkbox?.closest('label');
            const listItem = label?.parentElement;

            listItem?.querySelector('.file-actions')?.remove();
            label?.classList.remove('processed-error', 'processed-success');

            try {
                show_upgrade('Прогресс', `Обрабатывается файл: ${relPath}`, true);
                const newCode = await improveSingleFile_upgrade(relPath, settings);
                
                createActionButtons_upgrade(listItem, relPath, newCode);
                label?.classList.add('processed-success');
                checkbox.disabled = true;
                successCount++;
            } catch (err) {
                errors.push({ filename: relPath.split('/').pop(), error: err.message || String(err) });
                label?.classList.add('processed-error');
            }
        }
        
        let msg = `<strong>Обработка завершена</strong><br><br>Готово к просмотру/замене: ${successCount} из ${total}<br>`;
        if (errors.length) {
            msg += `<br><strong>Файлы с ошибками (${errors.length}):</strong><ul style="margin:8px 0; padding-left:20px;">`;
            errors.forEach(e => msg += `<li><strong>${e.filename}</strong> — ${e.error}</li>`);
            msg += `</ul><p>Эти файлы отмечены в списке красным.</p>`;
        }
        if (successCount > 0) {
             msg += `<br><p>Успешно обработанные файлы теперь имеют кнопки "Предпросмотр" и "Заменить".</p>`;
        }
        show_upgrade('Результат', msg, errors.length === 0);
        
        selectedFiles.clear();
        document.querySelectorAll('.file-checkbox:checked').forEach(chk => { if(!chk.disabled) chk.checked = false; });
        updateSelectionUI_upgrade();
        processButton.textContent = 'Обработать выбранные';
    });
    updateSelectionUI_upgrade();
}

function addPreviewLogic_upgrade(button, code) {
    button.onclick = async () => {
        button.disabled = true;
        button.textContent = 'Создание...';
        try {
            const fd = new FormData();
            fd.append('action', 'create_preview');
            fd.append('content', code);
            const res = await fetch('', { method: 'POST', body: fd });
            if (!res.ok) throw new Error(`Ошибка сервера ${res.status}`);
            const data = await res.json();
            if (data.status !== 'success' || !data.url) {
                throw new Error(data.message || 'Ошибка создания предпросмотра');
            }
            window.open(data.url, '_blank');
        } catch (err) {
            alert('Ошибка предпросмотра: ' + err.message);
        } finally {
            button.disabled = false;
            button.textContent = 'Предпросмотр';
        }
    };
}

async function improveSingleFile_upgrade(relPath, settings) {
    const res = await fetch('?file=' + encodeURIComponent(relPath));
    if (!res.ok) throw new Error(`Не удалось загрузить файл (${res.status})`);
    
    const code = await res.text();
    if (!code.trim()) throw new Error('Файл пустой');
    
    const fd = new FormData();
    fd.append('code', code);
    fd.append('path', relPath);
    fd.append('mode', 'improve_code');
    fd.append('round_images', settings.round_images ? '1' : '0');
    fd.append('no_style', settings.no_style ? '1' : '0');
    fd.append('fancybox', settings.fancybox? '1' : '0');

    
    const n8nRes = await fetch('https://n8n.takfit.ru/webhook-test/upgrade-code', {
        method: 'POST',
        body: fd
    });
    
    if (!n8nRes.ok) {
        let errorMsg = `n8n ответил ошибкой HTTP ${n8nRes.status}`;
        try {
            const errorJson = await n8nRes.json();
            if (errorJson.message) errorMsg += `: ${errorJson.message}`;
        } catch(e) {/* ignore */}
        throw new Error(errorMsg);
    }
    
    const newCode = await n8nRes.text();

    if (!newCode || newCode.trim().length < 10) {
        throw new Error('Ответ от n8n пустой или слишком короткий');
    }

    try {
        const data = JSON.parse(newCode);
        if (data && typeof data === 'object') {
            throw new Error(`n8n вернул JSON вместо кода: ${data.message || JSON.stringify(data).substring(0, 150)}`);
        }
    } catch (e) {
        if (e instanceof SyntaxError) {
            return newCode; 
        }
        throw e;
    }
    return newCode;
}

function show_upgrade(title, message, success = true) {
    const mt = document.getElementById('modal-title');
    const mm = document.getElementById('modal-message');
    const ov = document.getElementById('result-overlay');
    if (!mt || !mm || !ov) return;
    mt.textContent = title;
    mm.innerHTML = message;
    mt.style.color = success ? '#27ae60' : '#e74c3c';
    ov.style.display = 'flex';
}

document.querySelectorAll('.modal-close').forEach(btn => {
    btn.onclick = () => btn.closest('.overlay').style.display = 'none';
});
document.querySelectorAll('.overlay').forEach(ov => {
    ov.onclick = e => { if (e.target.classList.contains('overlay')) ov.style.display = 'none'; };
});

document.addEventListener('DOMContentLoaded', () => {
    initCodeImprove_upgrade();
});
</script>
</body>
</html>