<?php

// Вспомогательная функция для рекурсивного удаления директории
function deleteDir_create($dirPath) {
    if (!is_dir($dirPath)) return;
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') $dirPath .= '/';
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) deleteDir_create($file);
        else unlink($file);
    }
    rmdir($dirPath);
}

// Обработка для статичной страницы: создание страницы из JSON и изображений
if (isset($_POST['path']) && isset($_POST['content'])) {
    header('Content-Type: application/json');
    $forbidden = ['bitrix','upload','local','admin','images','include','auth','cgi-bin','css','js','personal','search','vendor'];
   
    function send_create($s, $m) {
        echo json_encode(['status' => $s, 'message' => $m], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $path = $_POST['path'] ?? '';
    $content = $_POST['content'] ?? '';
    $imgs = $_FILES['images'] ?? null;
    if (empty($path) || empty($content)) { send_create('error', 'Отсутствуют данные'); }
    $clean = trim($path, '/\\');
    if (strpos($clean, '..') !== false || empty($clean)) { send_create('error', 'Недопустимый путь'); }
    if (in_array(strtolower(explode('/', $clean)[0] ?? ''), $forbidden)) { send_create('error', 'Запрещённая директория'); }
    $data = json_decode($content, true);
    if (json_last_error() || !isset($data['page_title'], $data['content'])) { send_create('error', 'Некорректный JSON'); }
    
    $title = $data['page_title'];
    $html = $data['content'];
    $php = <<<PHP
<?php
require(\$_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
\$APPLICATION->SetTitle("$title");
?>
$html
<?php require(\$_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>
PHP;
    try {
        $root = $_SERVER['DOCUMENT_ROOT'];
        $dir = $root . '/' . $clean;
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) { send_create('error', "Не удалось создать директорию $dir"); }
        if (file_put_contents($dir . '/index.php', $php) === false) { send_create('error', 'Не удалось записать index.php'); }
       $saved = [];
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $m);
        $needed = array_unique($m[1]);

        if ($imgs && isset($imgs['name']) && !empty($imgs['name'][0])) {
            $avail = [];
            foreach ($imgs['name'] as $i => $n) {
                if ($imgs['error'][$i] !== UPLOAD_ERR_OK) continue;
                $avail[basename($n)] = $imgs['tmp_name'][$i];
            }

            foreach ($needed as $p) {
                $trimmed_p = trim($p);
                if (empty($trimmed_p)) continue; 

                $f = basename($trimmed_p); 
                if (empty($f) || !isset($avail[$f])) continue; 

                $target = '';

                if (substr($trimmed_p, 0, 1) !== '/') {
                    $target = $dir . '/' . $f;
                } else {
                    $target = $root . '/' . ltrim($trimmed_p, '/');
                }

                $tdir = dirname($target);
                if (!is_dir($tdir) && !mkdir($tdir, 0775, true)) continue;

                if (move_uploaded_file($avail[$f], $target)) {
                    $saved[] = $p;
                }
            }
        }
        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/' . $clean . '/';
        $msg = "<strong>Страница создана!</strong><br><br>" . "Папка: <b>$clean/</b><br>Файл: <b>index.php</b><br>" . "Ссылка: <a href='$url' target='_blank'>$url</a>";
        if ($saved) {
            $msg .= "<br><br><strong>Изображения размещены (" . count($saved) . "):</strong><br><br>";
            foreach ($saved as $p) {
                $full_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . htmlspecialchars($p);
                $msg .= "• <a href='$full_url' target='_blank'>" . htmlspecialchars($p) . "</a><br>";
            }
        } else { $msg .= "<br><br>Изображения не найдены или не загружены."; }
        $msg .= "<br><br>Готово!";
        send_create('success', $msg);
    } catch (Exception $e) { send_create('error', $e->getMessage()); }
}

if (isset($_POST['action']) && $_POST['action'] === 'ajax_preview') {
    if (isset($_FILES['zip_archive']) && $_FILES['zip_archive']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['zip_archive']['tmp_name'];
        if (mime_content_type($file_tmp) !== 'application/zip') {
            http_response_code(400);
            echo '<div class="alert alert-danger">Ошибка: Загруженный файл не является ZIP-архивом.</div>';
            exit;
        }

        $tmpDirName = 'importer_' . uniqid();
        $tmpDirPath = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $tmpDirName;

        if (!mkdir($tmpDirPath, 0775, true)) {
            http_response_code(500);
            echo '<div class="alert alert-danger">Не удалось создать временную директорию.</div>';
            exit;
        }

        $zip = new ZipArchive;
        if ($zip->open($file_tmp) === TRUE) {
            $zip->extractTo($tmpDirPath);
            $zip->close();
            
            $jsonFiles = glob($tmpDirPath . '/*.json');
            if (empty($jsonFiles)) {
                deleteDir_create($tmpDirPath);
                http_response_code(400);
                echo '<div class="alert alert-danger">В архиве не найден JSON-файл.</div>';
                exit;
            }
            
            // --- Генерация HTML для предпросмотра ---
            $jsonFilePath = $jsonFiles[0];
            $jsonData = json_decode(file_get_contents($jsonFilePath), true);
            $pageSlug = $jsonData['page_slug'];
            
            CModule::IncludeModule("iblock");
            $arIblocks = [];
            $res = CIBlock::GetList(['IBLOCK_TYPE' => 'ASC', 'NAME' => 'ASC'], ['ACTIVE' => 'Y']);
            while ($ar_res = $res->Fetch()) {
                $arIblocks[] = $ar_res;
            }

            ob_start();
            ?>
            <form action="" method="post" id="create-element-form">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="tmp_dir" value="<?= htmlspecialchars($tmpDirPath) ?>">

                <div class="form-group">
                    <label for="iblock_id">Выберите инфоблок для создания элементов:</label>
                    <select name="iblock_id" id="iblock_id" required>
                        <option value="">-- Выберите инфоблок --</option>
                        <?php 
                        $currentType = '';
                        foreach ($arIblocks as $iblock): 
                            if ($currentType != $iblock['IBLOCK_TYPE_ID']) {
                                if ($currentType != '') echo '</optgroup>';
                                $currentType = $iblock['IBLOCK_TYPE_ID'];
                                echo '<optgroup label="' . htmlspecialchars($iblock['IBLOCK_TYPE_ID']) . '">';
                            }
                        ?>
                            <option value="<?= $iblock['ID'] ?>">[<?= $iblock['ID'] ?>] <?= htmlspecialchars($iblock['NAME']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($currentType != '') echo '</optgroup>'; ?>
                    </select>
                </div>
                
                <h3>Элементы для создания</h3>
                <p>Для каждого элемента можно изменить название, ссылку и выбрать тип картинки.</p>
                
                <?php foreach ($jsonData['elements'] as $index => $element): ?>
                    <div class="preview-item">
                        <?php
                        $imageFilenameBase = $element['image']['filerename'];
                        $imagePathPattern = $tmpDirPath . '/' . $imageFilenameBase . '_' . $pageSlug . '.*';
                        $imagePaths = glob($imagePathPattern);
                        $imageUrl = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='; // placeholder
                        if (!empty($imagePaths)) {
                            $imageData = base64_encode(file_get_contents($imagePaths[0]));
                            $imageMime = mime_content_type($imagePaths[0]);
                            $imageUrl = 'data:' . $imageMime . ';base64,' . $imageData;
                        }
                        ?>
                        <img src="<?= $imageUrl ?>" alt="<?= htmlspecialchars($element['image']['alt']) ?>">
                        <div class="info">
                             <div class="form-group" style="margin-bottom: 10px;">
                                <label for="name_<?= $index ?>">Название элемента:</label>
                                <input type="text" name="element_names[<?= $index ?>]" id="name_<?= $index ?>" value="<?= htmlspecialchars($element['name']) ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label for="url_<?= $index ?>">Ссылка на страницу:</label>
                                <input type="url" name="element_urls[<?= $index ?>]" id="url_<?= $index ?>" value="<?= htmlspecialchars($element['url_page'] ?? '') ?>" placeholder="https://example.com">
                            </div>
                            <div class="selector-group">
                                <label for="assign_<?= $index ?>">Использовать картинку как:</label>
                                <select name="image_assignment[<?= $index ?>]" id="assign_<?= $index ?>">
                                    <option value="">-- Не использовать --</option>
                                    <option value="preview">Анонсная картинка</option>
                                    <option value="detail">Детальная картинка</option>
                                </select>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-success">Создать элементы</button>
            </form>
            <?php
            echo ob_get_clean();
            exit;
        } else {
            http_response_code(500);
            echo '<div class="alert alert-danger">Не удалось открыть ZIP-архив.</div>';
            deleteDir_create($tmpDirPath);
            exit;
        }
    } else {
        http_response_code(400);
        echo '<div class="alert alert-danger">Ошибка при загрузке файла. Код ошибки: ' . ($_FILES['zip_archive']['error'] ?? 'неизвестно') . '</div>';
        exit;
    }
}

// --- ОСНОВНОЙ КОД СТРАНИЦЫ ---
$error = '';
$success = '';

// --- ШАГ 3: Создание элементов в инфоблоке ---
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!CModule::IncludeModule("iblock")) {
        $error = "Не удалось подключить модуль инфоблоков.";
    } else {
        $iblockId = (int)($_POST['iblock_id'] ?? 0);
        $tmpDir = $_POST['tmp_dir'] ?? null;
        $elementNames = $_POST['element_names'] ?? [];
        $elementUrls = $_POST['element_urls'] ?? [];
        $imageAssignments = $_POST['image_assignment'] ?? [];
        $jsonFilePath = glob($tmpDir . '/*.json')[0] ?? null;

        if ($iblockId <= 0) $error = 'Необходимо выбрать инфоблок для создания элементов.';
        elseif (!$tmpDir || !is_dir($tmpDir) || !$jsonFilePath) $error = "Ошибка: временная директория или JSON-файл не найдены. Попробуйте загрузить архив заново.";
        else {
            // Проверка наличия свойства LINKIMG
            $propRes = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => 'LINKIMG']);
            $prop = $propRes->GetNext();
            if (!$prop) {
                $error = "В выбранном инфоблоке отсутствует обязательное свойство с кодом 'LINKIMG'.";
            } else {
                $successCount = 0;
                $errorCount = 0;
                $errorMessages = [];
                $el = new CIBlockElement;
                
                try {
                    $jsonData = json_decode(file_get_contents($jsonFilePath), true);
                    $pageSlug = $jsonData['page_slug'];
                    $elementsData = $jsonData['elements'];

                    foreach ($elementNames as $index => $elementName) {
                        $elementName = trim($elementName);
                        if (empty($elementName)) {
                            $errorCount++;
                            $errorMessages[] = "Элемент с индексом {$index}: пропущено, так как название не заполнено.";
                            continue;
                        }

                        $arLoadProductArray = [
                            "IBLOCK_ID" => $iblockId,
                            "NAME"      => $elementName,
                            "ACTIVE"    => "Y",
                            "PROPERTY_VALUES" => []
                        ];
                        
                        $elementUrl = $elementUrls[$index] ?? ($elementsData[$index]['url_page'] ?? '');
                        if (!empty($elementUrl)) {
                            $arLoadProductArray['PROPERTY_VALUES']['LINKIMG'] = $elementUrl;
                        }
                        
                        $imageType = $imageAssignments[$index] ?? '';
                        if (!empty($imageType)) {
                            $elementInfo = $elementsData[$index];
                            $imageFilenameBase = $elementInfo['image']['filerename'];
                            $imagePathArr = glob($tmpDir . '/' . $imageFilenameBase . '_' . $pageSlug . '.*');

                            if (!empty($imagePathArr) && file_exists($imagePathArr[0])) {
                                $arFile = CFile::MakeFileArray($imagePathArr[0]);
                                if ($arFile) {
                                    if ($imageType === 'preview') $arLoadProductArray['PREVIEW_PICTURE'] = $arFile;
                                    elseif ($imageType === 'detail') $arLoadProductArray['DETAIL_PICTURE'] = $arFile;
                                }
                            }
                        }
                        
                        if ($PRODUCT_ID = $el->Add($arLoadProductArray)) {
                            $successCount++;
                        } else {
                            $errorCount++;
                            $errorMessages[] = "Элемент '{$elementName}': " . $el->LAST_ERROR;
                        }
                    }

                    if ($successCount > 0) {
                        $success = "Операция завершена. Успешно создано элементов: {$successCount}.";
                    }
                    if ($errorCount > 0) {
                        $error = "Обнаружены ошибки. Не удалось создать элементов: {$errorCount}.<br><strong>Детали:</strong><br>" . implode("<br>", $errorMessages);
                    }
                    if ($successCount === 0 && $errorCount === 0) {
                        $error = "Не было выбрано ни одного элемента для создания.";
                    }

                } catch (Exception $e) {
                    $error = "Произошла критическая ошибка: " . $e->getMessage();
                } finally {
                     if ($tmpDir && is_dir($tmpDir)) deleteDir_create($tmpDir);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Импорт данных</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/vanish0077/n8n@2d8bb8b/styles_create.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
   
</head>
<body>
    <div class="container">
        <h1>Импорт данных</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error /* Выводим HTML без экранирования, т.к. сами его формируем */ ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($success || $error): ?>
             <a href="?" class="btn">Импортировать еще</a>
        <?php endif; ?>

        <div class="tabs">
            <ul class="tab-nav">
                <li class="active"><a href="#tab-static">Импорт статичной страницы</a></li>
                <li><a href="#tab-iblock">Импорт в инфоблок</a></li>
            </ul>

            <div class="tab-content">
                <div id="tab-static" class="tab-panel active">
                    <div class="file-input-wrapper">
                        <input type="file" id="file-input-static" accept=".zip" style="display:none">
                        <label for="file-input-static" class="file-input-label">Выбрать ZIP-архив</label>
                        <span id="file-name-static"></span>
                    </div>
                    <div class="archive-content" id="archive-content-static">
                        <p id="status-message-static">Содержимое архива появится здесь.</p>
                        <div id="sections-list-static"></div>
                    </div>
                </div>

                <div id="tab-iblock" class="tab-panel">
                    <?php if (!($success || $error)): ?>
                        <div id="upload-form-container">
                            <h2>Шаг 1: Загрузка архива</h2>
                            <p>Выберите ZIP-архив, содержащий `*.json` файл и соответствующие изображения. Предпросмотр появится автоматически.</p>
                            <div class="form-group">
                                <label for="zip_archive_input">ZIP-архив:</label>
                                <input type="file" name="zip_archive" id="zip_archive_input" accept=".zip">
                            </div>
                        </div>

                        <div id="loader">Загрузка и обработка архива...</div>
                        <div id="preview-container"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Модалка для результатов -->
    <div id="result-overlay" class="overlay">
        <div class="modal">
            <h3 id="modal-title"></h3>
            <div id="modal-message"></div>
            <button class="modal-close">Закрыть</button>
        </div>
    </div>

    <script>
        // JS для переключения табов
        document.addEventListener('DOMContentLoaded', function() {
            const tabLinks = document.querySelectorAll('.tab-nav a');
            const tabPanels = document.querySelectorAll('.tab-panel');

            tabLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const targetPanel = document.getElementById(targetId);

                    // Удаляем active с всех
                    tabLinks.forEach(l => l.parentElement.classList.remove('active'));
                    tabPanels.forEach(p => p.classList.remove('active'));

                    // Добавляем active к выбранному
                    this.parentElement.classList.add('active');
                    targetPanel.classList.add('active');

                    // Управление видимостью формы в табе инфоблок
                    const uploadFormContainer = document.getElementById('upload-form-container');
                    if (targetId === 'tab-iblock' && uploadFormContainer) {
                        uploadFormContainer.style.display = 'block';
                    } else if (targetId === 'tab-static' && uploadFormContainer) {
                        uploadFormContainer.style.display = 'none';
                    }

                    // Инициализация статичной страницы при активации
                    if (targetId === 'tab-static') {
                        initStaticTransfer_create();
                    }
                });
            });

            // Инициализация статичной страницы по умолчанию
            initStaticTransfer_create();

            // JS для загрузки файла (активируется только во втором табе)
            const zipInput = document.getElementById('zip_archive_input');
            if (zipInput) {
                zipInput.addEventListener('change', function(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    const loader = document.getElementById('loader');
                    const previewContainer = document.getElementById('preview-container');
                    const uploadFormContainer = document.getElementById('upload-form-container');
                    
                    loader.style.display = 'block';
                    previewContainer.innerHTML = '';
                    
                    const formData = new FormData();
                    formData.append('action', 'ajax_preview');
                    formData.append('zip_archive', file);

                    fetch('', { method: 'POST', body: formData })
                    .then(response => {
                        if (!response.ok) {
                            return response.text().then(text => { throw new Error(text || 'Ошибка сервера'); });
                        }
                        return response.text();
                    })
                    .then(html => {
                        loader.style.display = 'none';
                        previewContainer.innerHTML = html;
                        if (uploadFormContainer) uploadFormContainer.style.display = 'none';
                    })
                    .catch(error => {
                        loader.style.display = 'none';
                        const errorMessage = error.message.trim().startsWith('<') 
                            ? error.message 
                            : `<div class="alert alert-danger">${error.message}</div>`;
                        previewContainer.innerHTML = errorMessage;
                    });
                });
            }
        });

        // Инициализация функционала статичной страницы
        function initStaticTransfer_create() {
            const inputStatic = document.getElementById('file-input-static'),
                  nameStatic = document.getElementById('file-name-static'),
                  listStatic = document.getElementById('sections-list-static'),
                  msgStatic = document.getElementById('status-message-static');
            let lastFileStatic = null;
            inputStatic.onchange = async e => {
                const file = e.target.files[0]; if (!file) return;
                lastFileStatic = file; await processStatic_create(file);
            };
           async function processStatic_create(file) {
    nameStatic.textContent = `Выбран: ${file.name}`;
    listStatic.innerHTML = '';
    msgStatic.textContent = 'Распаковка архива...';
    msgStatic.style.display = 'block';
    try {
        const zip = await JSZip.loadAsync(file);
        msgStatic.style.display = 'none';
        const files = [];
        zip.forEach((p, en) => {
            if (!en.dir) files.push({
                path: p,
                entry: en
            });
        });
        if (!files.length) {
            msgStatic.textContent = 'Архив пуст.';
            msgStatic.style.display = 'block';
            return;
        }
        const jsons = files.filter(f => f.path.toLowerCase().endsWith('.json'));
        for (const j of jsons) {
            const base = j.path.split('/').pop().replace(/\.json$/i, ''),
                suf = '_' + base;
            const imgs = files.filter(f => !f.path.toLowerCase().endsWith('.json') && f.path.includes(suf) && /\.(jpe?g|png|gif|webp|svg)$/i.test(f.path));
            const group = document.createElement('div');
            group.className = 'section-group';
            const head = document.createElement('div');
            head.className = 'section-header collapsed';
            head.innerHTML = `<span>📄 ${j.path} ${imgs.length ? `<small>(${imgs.length} изображ.)</small>` : ''}</span><span class="toggle-icon"></span>`;
            const body = document.createElement('div');
            body.className = 'section-body';
            const btn = document.createElement('button');
            btn.textContent = 'Создать страницу';
            btn.className = 'apply-btn';
            btn.onclick = () => createPageStatic_create(j.entry, imgs, base);
            body.appendChild(btn);
            if (imgs.length) {
                let grid = '<div class="images-grid">';
                for (const img of imgs) {
                    const blob = await img.entry.async('blob'),
                        url = URL.createObjectURL(blob);
                    grid += `<div class="image-item"><img src="${url}" alt="${img.path}"><div class="image-name">${img.path.split('/').pop()}</div></div>`;
                }
                grid += '</div>';
                body.insertAdjacentHTML('beforeend', grid);
            } else body.insertAdjacentHTML('beforeend', '<p style="color:#999;font-style:italic">Изображения не найдены.</p>');
            const toggleIcon = head.querySelector('.toggle-icon');
            head.onclick = () => {
                head.classList.toggle('collapsed');
                body.classList.toggle('open');
            };
            group.append(head, body);
            listStatic.appendChild(group);
        }
    } catch (err) {
        msgStatic.textContent = 'Ошибка чтения архива.';
        console.error(err);
    }
}

            
            async function createPageStatic_create(entry, imgs, base) {
                const folder = prompt(`Папка для страницы "${entry.name}":\n(например: company)`); if (!folder?.trim()) return;
                show_create('Обработка...', 'Распаковка на сервер...', true);
                const json = await entry.async('string');
                const fd = new FormData(); fd.append('path', folder.trim()); fd.append('content', json);
                const suf = '_' + base;
                for (const img of imgs) {
                    const blob = await img.entry.async('blob');
                    let n = img.path.split('/').pop();
                    const dot = n.lastIndexOf('.'); if (dot !== -1) { const pre = n.substring(0, dot), ext = n.substring(dot); if (pre.endsWith(suf)) n = pre.slice(0, -suf.length) + ext; }
                    fd.append('images[]', blob, n);
                }
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                show_create(data.status === 'success' ? 'Готово!' : 'Ошибка', data.message, data.status === 'success');
            }
            if (lastFileStatic) processStatic_create(lastFileStatic);
        }

        // Функция для показа модалки
        function show_create(title, message, success = true) {
            const mt = document.getElementById('modal-title');
            const mm = document.getElementById('modal-message');
            const ov = document.getElementById('result-overlay');
            if (!mt || !mm || !ov) return;
            mt.textContent = title;
            mm.innerHTML = message;
            mt.style.color = success ? '#27ae60' : '#e74c3c';
            ov.style.display = 'flex';
        }

        // Закрытие модалок
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.onclick = () => btn.closest('.overlay').style.display = 'none';
        });
        document.querySelectorAll('.overlay').forEach(ov => {
            ov.onclick = e => { if (e.target.classList.contains('overlay')) ov.style.display = 'none'; };
        });
    </script>

</body>
</html>